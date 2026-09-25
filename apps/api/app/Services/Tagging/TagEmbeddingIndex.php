<?php

namespace App\Services\Tagging;

use App\Services\Processing\EmbeddingVersion;
use Illuminate\Support\Facades\DB;

/**
 * tag_embeddings access for TagResolver stage 3 (ТЗ §21, §36; ADR-0009).
 *
 * Every query is scoped to one model_key + model_version, so vectors of
 * different models/revisions are never compared (§77). Similarity is
 * `1 - cosine distance`. Only active tags are searched: archived tags accept
 * no new assignments (ADR-0007), so they cannot be auto-attached or offered
 * as a closest tag.
 *
 * PostgreSQL uses pgvector (`<=>`, HNSW). The sqlite test schema stores the
 * vector literal as text, so there the same cosine is computed in PHP.
 */
final class TagEmbeddingIndex
{
    /**
     * @param  list<float>  $vector
     * @return list<array{tag_id: int, similarity: float}> Best first, ties by tag_id.
     */
    public function nearest(array $vector, string $modelKey, string $modelVersion, int $limit): array
    {
        if ($this->isPgsql()) {
            $rows = DB::select(
                'SELECT te.tag_id, 1 - (te.embedding <=> CAST(? AS vector)) AS similarity '
                .'FROM tag_embeddings te JOIN tags t ON t.id = te.tag_id '
                ."WHERE te.model_key = ? AND te.model_version = ? AND t.status = 'active' "
                .'ORDER BY te.embedding <=> CAST(? AS vector), te.tag_id LIMIT ?',
                [
                    $literal = EmbeddingVersion::toVectorLiteral($vector),
                    $modelKey,
                    $modelVersion,
                    $literal,
                    $limit,
                ],
            );

            return array_map(static fn (object $row): array => [
                'tag_id' => (int) $row->tag_id,
                'similarity' => (float) $row->similarity,
            ], $rows);
        }

        $scored = $this->scoreInPhp($vector, $modelKey, $modelVersion, null);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Similarity of $vector to each of $tagIds that has an embedding of this
     * version and is active. Tags without one are absent from the result.
     *
     * @param  list<float>  $vector
     * @param  list<int>  $tagIds
     * @return list<array{tag_id: int, similarity: float}> Best first, ties by tag_id.
     */
    public function similarities(array $vector, array $tagIds, string $modelKey, string $modelVersion): array
    {
        if ($tagIds === []) {
            return [];
        }

        if ($this->isPgsql()) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $rows = DB::select(
                'SELECT te.tag_id, 1 - (te.embedding <=> CAST(? AS vector)) AS similarity '
                .'FROM tag_embeddings te JOIN tags t ON t.id = te.tag_id '
                ."WHERE te.model_key = ? AND te.model_version = ? AND t.status = 'active' "
                ."AND te.tag_id IN ({$placeholders}) "
                .'ORDER BY similarity DESC, te.tag_id',
                [EmbeddingVersion::toVectorLiteral($vector), $modelKey, $modelVersion, ...$tagIds],
            );

            return array_map(static fn (object $row): array => [
                'tag_id' => (int) $row->tag_id,
                'similarity' => (float) $row->similarity,
            ], $rows);
        }

        return $this->scoreInPhp($vector, $modelKey, $modelVersion, $tagIds);
    }

    /**
     * Upserts the embedding of one tag for one model version (unique
     * tag_id + model_key + model_version, ADR-0009).
     *
     * @param  list<float>  $vector
     */
    public function store(int $tagId, string $modelKey, string $modelVersion, string $sourceText, array $vector): void
    {
        $literal = EmbeddingVersion::toVectorLiteral($vector);

        DB::transaction(function () use ($tagId, $modelKey, $modelVersion, $sourceText, $literal): void {
            $key = ['tag_id' => $tagId, 'model_key' => $modelKey, 'model_version' => $modelVersion];

            DB::table('tag_embeddings')->insertOrIgnore($key + [
                'source_text' => $sourceText,
                'embedding' => $literal,
                'created_at' => now(),
            ]);

            DB::table('tag_embeddings')->where($key)->update([
                'source_text' => $sourceText,
                'embedding' => $literal,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<float>  $vector
     * @param  list<int>|null  $tagIds
     * @return list<array{tag_id: int, similarity: float}>
     */
    private function scoreInPhp(array $vector, string $modelKey, string $modelVersion, ?array $tagIds): array
    {
        $query = DB::table('tag_embeddings as te')
            ->join('tags as t', 't.id', '=', 'te.tag_id')
            ->where('te.model_key', $modelKey)
            ->where('te.model_version', $modelVersion)
            ->where('t.status', 'active')
            ->select(['te.tag_id', 'te.embedding']);

        if ($tagIds !== null) {
            $query->whereIn('te.tag_id', $tagIds);
        }

        $scored = [];
        foreach ($query->get() as $row) {
            $stored = json_decode((string) $row->embedding, true);
            if (! is_array($stored) || count($stored) !== count($vector)) {
                continue;
            }

            $scored[] = ['tag_id' => (int) $row->tag_id, 'similarity' => self::cosine($vector, $stored)];
        }

        usort($scored, static fn (array $a, array $b): int => [$b['similarity'], $a['tag_id']] <=> [$a['similarity'], $b['tag_id']]);

        return $scored;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float|int>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $other = (float) $b[$i];
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
