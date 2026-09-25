<?php

namespace Tests\Feature\Search;

use App\Contracts\Ai\AiClientInterface;
use App\Models\ImageEmbedding;
use App\Services\Processing\EmbeddingVersion;
use App\Services\Search\ImageSearch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * Indexes the search queries rely on (ТЗ §79, ADR-0009). The HNSW part only
 * exists on PostgreSQL + pgvector and is skipped on the sqlite test
 * connection; run with `DB_CONNECTION=pgsql` to exercise it.
 */
class SearchIndexesTest extends TestCase
{
    use BuildsTaxonomy;
    use RefreshDatabase;

    /**
     * @return list<list<string>>
     */
    private function indexedColumns(string $table, bool $uniqueOnly = false): array
    {
        return array_values(array_map(
            static fn (array $index): array => $index['columns'],
            array_filter(Schema::getIndexes($table), static fn (array $index): bool => ! $uniqueOnly || $index['unique']),
        ));
    }

    private function skipUnlessPgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector/HNSW assertions require a pgsql connection.');
        }
    }

    public function test_tag_filter_is_backed_by_image_tags_indexes(): void
    {
        // AND filter: `image_tags WHERE tag_id IN (...) GROUP BY image_id`.
        $this->assertContains(['tag_id'], $this->indexedColumns('image_tags'));
        $this->assertContains(['image_id', 'tag_id'], $this->indexedColumns('image_tags', uniqueOnly: true));
    }

    public function test_image_embeddings_have_partial_hnsw_cosine_index_on_active_rows(): void
    {
        $this->skipUnlessPgsql();

        $definitions = DB::table('pg_indexes')->where('tablename', 'image_embeddings')->pluck('indexdef', 'indexname');

        $this->assertStringContainsString('USING hnsw (embedding vector_cosine_ops)', $definitions['image_embeddings_embedding_hnsw']);
        $this->assertStringContainsString('WHERE active', $definitions['image_embeddings_embedding_hnsw']);
        // One active row per image: the join from a ranked embedding to its image never duplicates.
        $this->assertStringContainsString('UNIQUE', $definitions['image_embeddings_active_unique']);
        $this->assertStringContainsString('WHERE active', $definitions['image_embeddings_active_unique']);
    }

    public function test_semantic_ranking_query_can_use_the_hnsw_index(): void
    {
        $this->skipUnlessPgsql();

        foreach ([0.9, 0.5, 0.1] as $similarity) {
            ImageEmbedding::create([
                'image_id' => $this->makeImage()->id,
                'model_key' => 'siglip2-stub',
                'model_version' => 'fake-embedding-rev-1',
                'embedding' => EmbeddingVersion::toVectorLiteral(ScriptedEmbeddingsAiClient::vector(10, $similarity)),
                'active' => true,
            ]);
        }

        $ai = (new ScriptedEmbeddingsAiClient)->embedAs('каска', ScriptedEmbeddingsAiClient::vector(10));
        $this->app->instance(AiClientInterface::class, $ai);

        $ranking = null;
        DB::listen(function (QueryExecuted $query) use (&$ranking): void {
            if (str_contains($query->sql, '<=>') && str_contains(strtolower($query->sql), 'order by')) {
                $ranking = $query;
            }
        });

        $page = app(ImageSearch::class)->semantic('каска', [], 1, 50, 'req-1');
        $this->assertCount(3, $page->imageIds);
        $this->assertNotNull($ranking, 'ranking query was not captured');

        // A tiny table makes any other plan cheapest, so rule them out: with seq/bitmap scans and
        // explicit sorts disabled, the planner must use an index that yields distance order, which
        // shows the partial HNSW index is applicable to the query shape (predicate `active`, ORDER BY
        // distance then id via incremental sort, LIMIT).
        $plan = DB::transaction(function () use ($ranking): string {
            DB::statement('SET LOCAL enable_seqscan = off');
            DB::statement('SET LOCAL enable_bitmapscan = off');
            DB::statement('SET LOCAL enable_sort = off');

            return implode("\n", array_map(
                static fn (object $row): string => $row->{'QUERY PLAN'},
                DB::select('EXPLAIN '.$ranking->sql, $ranking->bindings),
            ));
        });

        $this->assertStringContainsString('image_embeddings_embedding_hnsw', $plan);
    }
}
