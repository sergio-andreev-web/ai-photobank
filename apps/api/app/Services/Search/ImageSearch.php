<?php

namespace App\Services\Search;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Exceptions\ApiException;
use App\Services\Processing\EmbeddingVersion;
use App\Support\Vectors;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/search (ТЗ §42–§46; ADR-0004, ADR-0009).
 *
 * - tags: AND over image_tags, never calls AI, created_at desc, id desc.
 * - semantic / hybrid: the query is embedded by AI and ranked against the
 *   active image embedding version only (§77). Explicit tag_ids are part of
 *   the same WHERE clause as the vector ranking, so they are a hard
 *   constraint (§43) and are applied before, not after, any top-K cut.
 *   Relevance (ADR-0004, 2026-09-30 addendum): images whose caption, OCR text
 *   or tag names contain every query word come first; the rest are returned
 *   only above max(min_similarity, relative × best similarity), with a
 *   stricter relative share when some image matched the words.
 *
 * Only non-deleted images are returned; semantic/hybrid additionally require
 * an active embedding of the configured version.
 *
 * PostgreSQL ranks with pgvector (`<=>`, HNSW partial index on active rows).
 * The sqlite test schema stores vectors as text, so there the same cosine is
 * computed in PHP.
 */
final readonly class ImageSearch
{
    public function __construct(private AiClientInterface $ai) {}

    /**
     * @param  list<int>  $tagIds  Unique, existing tag IDs (validated by the caller).
     */
    public function byTags(array $tagIds, int $page, int $perPage): SearchPage
    {
        $query = DB::table('images')->whereNull('images.deleted_at');
        self::requireAllTags($query, 'images.id', $tagIds);

        $total = (clone $query)->count();

        $ids = $query->orderByDesc('images.created_at')
            ->orderByDesc('images.id')
            ->forPage($page, $perPage)
            ->pluck('images.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return new SearchPage($ids, [], $total);
    }

    /**
     * semantic when $tagIds is empty, hybrid otherwise.
     *
     * @param  list<int>  $tagIds  Unique, existing tag IDs (validated by the caller).
     *
     * @throws ApiException 503 AI_SERVICE_UNAVAILABLE / EMBEDDING_VERSION_MISMATCH.
     */
    public function semantic(string $text, array $tagIds, int $page, int $perPage, string $requestId): SearchPage
    {
        [$modelKey, $modelVersion, $vector] = $this->embedQuery($text, $requestId);

        $query = DB::table('images as i')
            ->join('image_embeddings as ie', 'ie.image_id', '=', 'i.id')
            ->whereNull('i.deleted_at')
            // Literal predicate (not a bound `= ?`) so the planner can prove the partial HNSW
            // index `WHERE active` applies. The key/version filter keeps a stale active row from
            // before an EMBEDDING_ACTIVE_* switch out of the ranking (§77, ADR-0009).
            ->whereRaw('ie.active')
            ->where('ie.model_key', $modelKey)
            ->where('ie.model_version', $modelVersion);

        if ($tagIds !== []) {
            self::requireAllTags($query, 'i.id', $tagIds);
        }

        [$ranked, $total] = DB::connection()->getDriverName() === 'pgsql'
            ? $this->rankWithPgvector($query, $vector, $text, $page, $perPage)
            : $this->rankInPhp($query, $vector, $text, $page, $perPage);

        $scores = [];
        foreach ($ranked as [$imageId, $similarity]) {
            // Float error can push an identical vector a hair past 1; the contract bounds it to [-1, 1].
            $scores[$imageId] = max(-1.0, min(1.0, $similarity));
        }

        return new SearchPage(array_keys($scores), $scores, $total);
    }

    /**
     * @return array{0: string, 1: string, 2: list<float>} model_key, model_version, vector.
     *
     * @throws ApiException
     */
    private function embedQuery(string $text, string $requestId): array
    {
        try {
            $result = $this->ai->createTextEmbeddings([$text], $requestId);
        } catch (AiException $e) {
            // Unreachable, busy, not ready, or rejecting our request: semantic search cannot run
            // either way, and the only AI failure this operation declares is 503 (§46, ADR-0004).
            Log::warning('search.ai_unavailable', [
                'request_id' => $requestId,
                'ai_error_code' => $e->errorCode,
                'ai_http_status' => $e->httpStatus,
                'retryable' => $e->retryable,
            ]);

            throw new ApiException(
                'AI_SERVICE_UNAVAILABLE',
                503,
                'AI service is unavailable',
                headers: $e->retryAfter !== null ? ['Retry-After' => (string) $e->retryAfter] : [],
            );
        } catch (InvalidAiResponseException $e) {
            Log::error('search.ai_response_invalid', [
                'request_id' => $requestId,
                'violations' => array_slice($e->violations, 0, 5),
            ]);

            throw new ApiException('AI_SERVICE_UNAVAILABLE', 503, 'AI service is unavailable');
        }

        $modelKey = EmbeddingVersion::modelKey($result->model);

        if (! EmbeddingVersion::isActive($modelKey, $result->revision)) {
            // Ranking a vector of another model/revision against image embeddings would mix
            // versions (§77); ADR-0009 makes this a 503 instead.
            Log::warning('search.embedding_version_mismatch', [
                'request_id' => $requestId,
                'model_key' => $modelKey,
                'model_version' => $result->revision,
                'active_model_key' => config('ai.embedding.active_model_key'),
                'active_model_version' => config('ai.embedding.active_model_version'),
            ]);

            throw new ApiException(
                'EMBEDDING_VERSION_MISMATCH',
                503,
                'Text embedding model does not match the active image embedding version',
            );
        }

        return [$modelKey, $result->revision, $result->embeddings[0]];
    }

    /**
     * Similarity floor for images without a word match: max(min, relative × best). When some
     * image matches the query words, those are the precise answer and only near-best vector
     * neighbours are added (relative_similarity_with_word_match).
     */
    private static function floor(?float $best, bool $hasWordMatch): float
    {
        $min = (float) config('photobank.search.min_similarity');
        $relative = (float) config($hasWordMatch
            ? 'photobank.search.relative_similarity_with_word_match'
            : 'photobank.search.relative_similarity');

        return $best === null ? $min : max($min, $relative * $best);
    }

    /**
     * Full text over caption, OCR text and the names of the image's tags; `russian` stems both
     * sides, so «жилищная» matches «ЖИЛИЩНАЯ». plainto_tsquery ANDs the query words.
     */
    private const string TEXT_MATCH_SQL = "to_tsvector('russian', coalesce(i.caption, '') || ' ' || coalesce(i.ocr_text, '') || ' ' || "
        ."coalesce((SELECT string_agg(t.name, ' ') FROM image_tags it_m JOIN tags t ON t.id = it_m.tag_id WHERE it_m.image_id = i.id), '')) "
        ."@@ plainto_tsquery('russian', ?)";

    /**
     * @param  list<float>  $vector
     * @return array{0: list<array{0: int, 1: float}>, 1: int} the page and the total.
     */
    private function rankWithPgvector(Builder $query, array $vector, string $text, int $page, int $perPage): array
    {
        $literal = EmbeddingVersion::toVectorLiteral($vector);
        $similarity = '1 - (ie.embedding <=> CAST(? AS vector))';

        return DB::transaction(function () use ($query, $literal, $similarity, $text, $page, $perPage): array {
            // pgvector >= 0.8: keep walking the HNSW graph until LIMIT rows pass the WHERE
            // clause, in exact distance order. Without it a selective tag filter would only see
            // the first hnsw.ef_search (40) neighbours and silently drop matches (§43, ADR-0004).
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $best = (clone $query)
                ->selectRaw("{$similarity} AS similarity", [$literal])
                ->orderByRaw('ie.embedding <=> CAST(? AS vector)', [$literal])
                ->limit(1)
                ->value('similarity');

            $hasWordMatch = (clone $query)->whereRaw(self::TEXT_MATCH_SQL, [$text])->exists();

            $relevant = (clone $query)->whereRaw(
                "({$similarity} >= ? OR ".self::TEXT_MATCH_SQL.')',
                [$literal, self::floor($best === null ? null : (float) $best, $hasWordMatch), $text],
            );
            $total = (clone $relevant)->count();
            if ($total <= ($page - 1) * $perPage) {
                return [[], $total];
            }

            $rows = $relevant
                ->selectRaw("i.id, {$similarity} AS similarity, (".self::TEXT_MATCH_SQL.') AS text_match', [$literal, $text])
                ->orderByDesc('text_match')
                ->orderByDesc('similarity')
                ->orderBy('i.id')
                ->forPage($page, $perPage)
                ->get();

            return [$rows->map(static fn (object $row): array => [(int) $row->id, (float) $row->similarity])->all(), $total];
        });
    }

    /**
     * The sqlite test schema has neither pgvector nor full text search: cosine in PHP, and a
     * word match that approximates the `russian` stemmer by comparing word stems (all but the
     * last two letters of longer words).
     *
     * @param  list<float>  $vector
     * @return array{0: list<array{0: int, 1: float}>, 1: int} the page and the total.
     */
    private function rankInPhp(Builder $query, array $vector, string $text, int $page, int $perPage): array
    {
        $rows = $query->select(['i.id', 'ie.embedding', 'i.caption', 'i.ocr_text'])->get();
        $tagNames = DB::table('image_tags')
            ->join('tags', 'tags.id', '=', 'image_tags.tag_id')
            ->whereIn('image_tags.image_id', $rows->pluck('id'))
            ->get(['image_tags.image_id', 'tags.name'])
            ->groupBy('image_id');

        $scored = [];
        foreach ($rows as $row) {
            $stored = Vectors::decode($row->embedding);
            if ($stored === null || count($stored) !== count($vector)) {
                continue;
            }
            $names = $tagNames->get($row->id, collect())->pluck('name')->all();
            $haystack = implode(' ', [(string) $row->caption, (string) $row->ocr_text, ...$names]);
            $scored[] = [(int) $row->id, Vectors::cosine($vector, $stored), self::wordsMatch($text, $haystack)];
        }

        $floor = self::floor($scored === [] ? null : max(array_column($scored, 1)), in_array(true, array_column($scored, 2), true));
        $relevant = array_values(array_filter($scored, static fn (array $row): bool => $row[2] || $row[1] >= $floor));
        // Word match first, then similarity desc, then id asc (ADR-0004).
        usort($relevant, static fn (array $a, array $b): int => [$b[2], $b[1], $a[0]] <=> [$a[2], $a[1], $b[0]]);

        $slice = array_slice($relevant, ($page - 1) * $perPage, $perPage);

        return [array_map(static fn (array $row): array => [$row[0], $row[1]], $slice), count($relevant)];
    }

    private static function wordsMatch(string $query, string $haystack): bool
    {
        $words = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn (string $word): bool => mb_strlen($word) > 1,
        );
        if ($words === []) {
            return false;
        }

        $haystack = mb_strtolower($haystack);
        foreach ($words as $word) {
            $length = mb_strlen($word);
            if (! str_contains($haystack, $length > 4 ? mb_substr($word, 0, $length - 2) : $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * AND semantics: the image carries every one of $tagIds (ADR-0004); child
     * tags are not expanded.
     *
     * @param  list<int>  $tagIds
     */
    private static function requireAllTags(Builder $query, string $imageIdColumn, array $tagIds): void
    {
        $query->whereIn($imageIdColumn, static fn (Builder $sub) => $sub
            ->from('image_tags')
            ->select('image_id')
            ->whereIn('tag_id', $tagIds)
            ->groupBy('image_id')
            ->havingRaw('COUNT(DISTINCT tag_id) = ?', [count($tagIds)]));
    }
}
