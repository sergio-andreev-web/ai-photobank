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

        $total = (clone $query)->count();

        if ($total <= ($page - 1) * $perPage) {
            return new SearchPage([], [], $total);
        }

        $ranked = DB::connection()->getDriverName() === 'pgsql'
            ? $this->rankWithPgvector($query, $vector, $page, $perPage)
            : $this->rankInPhp($query, $vector, $page, $perPage);

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
     * @param  list<float>  $vector
     * @return list<array{0: int, 1: float}>
     */
    private function rankWithPgvector(Builder $query, array $vector, int $page, int $perPage): array
    {
        $literal = EmbeddingVersion::toVectorLiteral($vector);

        return DB::transaction(function () use ($query, $literal, $page, $perPage): array {
            // pgvector >= 0.8: keep walking the HNSW graph until LIMIT rows pass the WHERE
            // clause, in exact distance order. Without it a selective tag filter would only see
            // the first hnsw.ef_search (40) neighbours and silently drop matches (§43, ADR-0004).
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $rows = $query
                ->selectRaw('i.id, 1 - (ie.embedding <=> CAST(? AS vector)) AS similarity', [$literal])
                ->orderByRaw('ie.embedding <=> CAST(? AS vector)', [$literal])
                ->orderBy('i.id')
                ->forPage($page, $perPage)
                ->get();

            return $rows->map(static fn (object $row): array => [(int) $row->id, (float) $row->similarity])->all();
        });
    }

    /**
     * @param  list<float>  $vector
     * @return list<array{0: int, 1: float}>
     */
    private function rankInPhp(Builder $query, array $vector, int $page, int $perPage): array
    {
        $scored = [];
        foreach ($query->select(['i.id', 'ie.embedding'])->get() as $row) {
            $stored = Vectors::decode($row->embedding);
            if ($stored === null || count($stored) !== count($vector)) {
                continue;
            }

            $scored[] = [(int) $row->id, Vectors::cosine($vector, $stored)];
        }

        // similarity desc, then id asc (ADR-0004).
        usort($scored, static fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        return array_slice($scored, ($page - 1) * $perPage, $perPage);
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
