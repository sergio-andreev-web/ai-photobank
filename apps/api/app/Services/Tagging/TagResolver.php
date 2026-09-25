<?php

namespace App\Services\Tagging;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Tagging\ResolvedTag;
use App\Contracts\Tagging\TagResolverInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Services\Processing\EmbeddingVersion;
use App\Services\Processing\ProcessingState;
use App\Services\Processing\StageFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TagResolver (ТЗ §35–§39; ADR-0007, ADR-0008, ADR-0009). For each concept
 * of the analysis, strictly in order:
 *
 * 1. exact canonical match on tags.normalized_name — a tag of the concept's
 *    type wins; a single tag of another type is taken as is; several tags of
 *    other types are ambiguous → candidate with the closest of them by
 *    embedding (ADR-0007);
 * 2. alias match on tag_aliases.normalized_alias (globally unique);
 * 3. semantic match: the concept's text embedding against tag_embeddings of
 *    the active model version, top-k; ≥ auto_attach → assign, ≥ candidate →
 *    candidate with closest tag, otherwise a new candidate (§37).
 *
 * A stage 1/2 match on an archived tag drops the concept (no link, no
 * candidate). AI never creates canonical tags: unmatched concepts only
 * become tag_candidates (§23, §39). image_tags are not written here — the
 * pipeline applies the result (see TagResolverInterface).
 */
final readonly class TagResolver implements TagResolverInterface
{
    /** POST /v1/embeddings/text accepts at most 64 texts (§32). */
    private const int EMBEDDING_BATCH = 64;

    public function __construct(
        private AiClientInterface $ai,
        private TagEmbeddingIndex $index,
        private TagCandidateRecorder $candidates,
    ) {}

    public function resolve(Image $image, ImageAnalysis $analysis): array
    {
        $concepts = $this->concepts($analysis);
        if ($concepts === []) {
            return [];
        }

        $names = array_values(array_unique(array_column($concepts, 'normalized')));
        $byName = Tag::whereIn('normalized_name', $names)->orderBy('id')->get()->groupBy('normalized_name');
        $byAlias = TagAlias::with('tag')->whereIn('normalized_alias', $names)->get()->keyBy('normalized_alias');

        $resolved = [];
        /** @var list<array{concept: array, among: list<int>|null}> $semantic */
        $semantic = [];

        foreach ($concepts as $concept) {
            /** @var Collection<int, Tag> $matches */
            $matches = $byName->get($concept['normalized'], collect());

            // Stage 1 — exact canonical match.
            if ($matches->isNotEmpty()) {
                $match = $matches->firstWhere('type', $concept['type'])
                    ?? ($matches->count() === 1 ? $matches->first() : null);

                if ($match !== null) {
                    if ($match->status === Taxonomy::TAG_ACTIVE) {
                        $resolved[] = new ResolvedTag($match->id, $concept['confidence']);
                    }

                    continue;
                }

                // Same name under several other types: ambiguous, ask a moderator.
                $active = $matches->where('status', Taxonomy::TAG_ACTIVE)->pluck('id')->all();
                if ($active !== []) {
                    $semantic[] = ['concept' => $concept, 'among' => $active];
                }

                continue;
            }

            // Stage 2 — alias match.
            $alias = $byAlias->get($concept['normalized']);
            if ($alias !== null) {
                if ($alias->tag->status === Taxonomy::TAG_ACTIVE) {
                    $resolved[] = new ResolvedTag($alias->tag_id, $concept['confidence']);
                }

                continue;
            }

            // Stage 3 — semantic match.
            $semantic[] = ['concept' => $concept, 'among' => null];
        }

        if ($semantic !== []) {
            array_push($resolved, ...$this->resolveSemantically($image, $semantic));
        }

        return $resolved;
    }

    /**
     * Valid, normalized concepts, one per normalized_name + type (the
     * candidate upsert key, §39) with the highest confidence; the first
     * label seen becomes the candidate's display name.
     *
     * @return list<array{normalized: string, label: string, type: string, confidence: float}>
     */
    private function concepts(ImageAnalysis $analysis): array
    {
        $unique = [];

        foreach ($analysis->concepts ?? [] as $raw) {
            if (! is_array($raw) || ! is_string($raw['label'] ?? null)
                || ! in_array($raw['type'] ?? null, Taxonomy::TAG_TYPES, true)
                || ! is_numeric($raw['confidence'] ?? null)) {
                continue;
            }

            $normalized = TagNormalizer::normalize($raw['label']);
            if ($normalized === '') {
                continue;
            }

            $key = $raw['type']."\0".$normalized;
            $confidence = max(0.0, min(1.0, (float) $raw['confidence']));

            if (! isset($unique[$key])) {
                $unique[$key] = [
                    'normalized' => $normalized,
                    'label' => mb_substr(trim($raw['label']), 0, 255),
                    'type' => $raw['type'],
                    'confidence' => $confidence,
                ];
            } elseif ($confidence > $unique[$key]['confidence']) {
                $unique[$key]['confidence'] = $confidence;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{concept: array, among: list<int>|null}>  $pending
     * @return list<ResolvedTag>
     */
    private function resolveSemantically(Image $image, array $pending): array
    {
        [$vectors, $modelKey, $modelVersion] = $this->embed(
            array_values(array_unique(array_map(static fn (array $p): string => $p['concept']['normalized'], $pending))),
        );

        $autoAttach = (float) config('photobank.tagging.auto_attach_threshold');
        $candidateThreshold = (float) config('photobank.tagging.candidate_threshold');
        $topK = (int) config('photobank.tagging.semantic_top_k');

        $resolved = [];

        foreach ($pending as ['concept' => $concept, 'among' => $among]) {
            $vector = $vectors[$concept['normalized']];

            if ($among !== null) {
                $best = $this->index->similarities($vector, $among, $modelKey, $modelVersion)[0] ?? null;
                $this->recordCandidate($image, $concept, $best['tag_id'] ?? null, $best['similarity'] ?? null);

                continue;
            }

            $best = $this->index->nearest($vector, $modelKey, $modelVersion, $topK)[0] ?? null;

            if ($best !== null && $best['similarity'] >= $autoAttach) {
                $resolved[] = new ResolvedTag($best['tag_id'], $concept['confidence']);
            } elseif ($best !== null && $best['similarity'] >= $candidateThreshold) {
                $this->recordCandidate($image, $concept, $best['tag_id'], $best['similarity']);
            } else {
                $this->recordCandidate($image, $concept, null, null);
            }
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $texts
     * @return array{0: array<string, list<float>>, 1: string, 2: string}
     */
    private function embed(array $texts): array
    {
        $vectors = [];
        $modelKey = $modelVersion = null;

        foreach (array_chunk($texts, self::EMBEDDING_BATCH) as $chunk) {
            $requestId = (string) Str::uuid();
            $result = $this->ai->createTextEmbeddings($chunk, $requestId);
            $key = EmbeddingVersion::modelKey($result->model);

            // ADR-0009: stage 3 compares only against the active version; anything else is a
            // retryable failure (the AI may be mid-rollout), never a silent cross-version match.
            if (! EmbeddingVersion::isActive($key, $result->revision)) {
                Log::warning('taxonomy.resolver.embedding_version_mismatch', [
                    'model_key' => $key,
                    'model_version' => $result->revision,
                    'request_id' => $requestId,
                ]);

                throw new StageFailure(
                    ProcessingState::ERROR_TAG_RESOLUTION_FAILED,
                    'Text embedding model version does not match the active embedding version',
                    retryable: true,
                );
            }

            if (count($result->embeddings) !== count($chunk)) {
                throw new StageFailure(
                    ProcessingState::ERROR_AI_RESPONSE_INVALID,
                    'AI service returned a different number of text embeddings than requested',
                    retryable: false,
                );
            }

            $modelKey = $key;
            $modelVersion = $result->revision;

            foreach ($chunk as $i => $text) {
                $vectors[$text] = $result->embeddings[$i];
            }
        }

        return [$vectors, (string) $modelKey, (string) $modelVersion];
    }

    /**
     * @param  array{normalized: string, label: string, type: string, confidence: float}  $concept
     */
    private function recordCandidate(Image $image, array $concept, ?int $closestTagId, ?float $similarity): void
    {
        $this->candidates->record(
            normalizedName: $concept['normalized'],
            displayName: $concept['label'],
            type: $concept['type'],
            imageId: $image->id,
            confidence: $concept['confidence'],
            closestTagId: $closestTagId,
            similarity: $similarity,
        );
    }
}
