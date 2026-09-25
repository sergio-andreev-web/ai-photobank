<?php

namespace App\Services\Tagging;

use App\Contracts\Ai\AiClientInterface;
use App\Models\Tag;
use App\Models\TagEmbedding;
use App\Services\Processing\EmbeddingVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Rebuilds a tag's text embedding (ТЗ §21: on create, rename and alias
 * add/remove; ADR-0007: asynchronously). Always computed from the tag's
 * current name and aliases, so running it twice or out of order converges
 * on the same row — one per tag_id + model_key + model_version (ADR-0009).
 */
final readonly class TagEmbeddingRegenerator
{
    public function __construct(
        private AiClientInterface $ai,
        private TagEmbeddingIndex $index,
    ) {}

    /**
     * Name first, then aliases in creation order — aliases carry the other
     * languages/synonyms (§17) that concept labels are matched against.
     */
    public static function sourceText(Tag $tag): string
    {
        $parts = [$tag->name];
        foreach ($tag->aliases()->orderBy('id')->pluck('alias') as $alias) {
            if (! in_array($alias, $parts, true)) {
                $parts[] = $alias;
            }
        }

        return implode(', ', $parts);
    }

    public function regenerate(int $tagId): void
    {
        $tag = Tag::find($tagId);
        if ($tag === null) {
            return;
        }

        $sourceText = self::sourceText($tag);

        $upToDate = TagEmbedding::where('tag_id', $tag->id)
            ->where('model_key', config('ai.embedding.active_model_key'))
            ->where('model_version', (string) config('ai.embedding.active_model_version'))
            ->where('source_text', $sourceText)
            ->exists();

        if ($upToDate) {
            return;
        }

        $requestId = (string) Str::uuid();
        $result = $this->ai->createTextEmbeddings([$sourceText], $requestId);

        $modelKey = EmbeddingVersion::modelKey($result->model);

        if (! EmbeddingVersion::isActive($modelKey, $result->revision)) {
            // Kept for a later version switch, but stage 3 will not see it (ADR-0009).
            Log::warning('taxonomy.tag_embedding.inactive_version', [
                'tag_id' => $tag->id,
                'model_key' => $modelKey,
                'model_version' => $result->revision,
                'request_id' => $requestId,
            ]);
        }

        $this->index->store($tag->id, $modelKey, $result->revision, $sourceText, $result->embeddings[0]);

        Log::info('taxonomy.tag_embedding.stored', [
            'tag_id' => $tag->id,
            'model_key' => $modelKey,
            'model_version' => $result->revision,
            'request_id' => $requestId,
        ]);
    }
}
