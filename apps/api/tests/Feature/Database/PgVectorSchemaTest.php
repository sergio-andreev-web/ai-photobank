<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * pgvector/HNSW only exist on PostgreSQL. This sandbox's default test connection is sqlite
 * (see phpunit.xml), so these assertions are skipped there and only run for real against a
 * pgvector-enabled Postgres connection (e.g. `DB_CONNECTION=pgsql php artisan test`, or CI/
 * docker-compose once a Postgres test job exists).
 */
class PgVectorSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function skipUnlessPgsql(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector/HNSW assertions require a pgsql connection.');
        }
    }

    public function test_vector_extension_is_installed(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'vector'");
        $this->assertNotNull($row, 'vector extension should be installed');
    }

    public function test_image_embeddings_column_is_vector_1152(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne(
            "SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a ".
            "JOIN pg_class c ON c.oid = a.attrelid ".
            "WHERE c.relname = 'image_embeddings' AND a.attname = 'embedding' AND a.attnum > 0"
        );

        $this->assertSame('vector(1152)', $row->type);
    }

    public function test_tag_embeddings_column_is_vector_1152(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne(
            "SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a ".
            "JOIN pg_class c ON c.oid = a.attrelid ".
            "WHERE c.relname = 'tag_embeddings' AND a.attname = 'embedding' AND a.attnum > 0"
        );

        $this->assertSame('vector(1152)', $row->type);
    }

    public function test_image_embeddings_has_partial_hnsw_index_on_active(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'image_embeddings_embedding_hnsw'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('USING hnsw', $row->indexdef);
        $this->assertStringContainsString('active', $row->indexdef);
    }

    public function test_image_embeddings_has_one_active_row_per_image_constraint(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'image_embeddings_active_unique'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('UNIQUE', $row->indexdef);
    }

    public function test_tag_embeddings_has_hnsw_index(): void
    {
        $this->skipUnlessPgsql();

        $row = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'tag_embeddings_embedding_hnsw'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('USING hnsw', $row->indexdef);
    }

    public function test_tag_embeddings_unique_includes_model_version(): void
    {
        $this->skipUnlessPgsql();

        DB::table('tags')->insert([
            'name' => 'Каска', 'slug' => 'kaska', 'normalized_name' => 'каска',
            'type' => 'object', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tagId = DB::getPdo()->lastInsertId();

        $vector = '['.implode(',', array_fill(0, 1152, 0.1)).']';

        DB::statement(
            'INSERT INTO tag_embeddings (tag_id, model_key, model_version, source_text, embedding, created_at) '.
            'VALUES (?, ?, ?, ?, ?, now())',
            [$tagId, 'siglip2-so400m-patch14-384', 'rev-1', 'Каска', $vector]
        );

        // Same tag + model_key but a different model_version must be allowed to coexist
        // (ADR-0009: re-embedding during a revision upgrade before the old row is retired).
        DB::statement(
            'INSERT INTO tag_embeddings (tag_id, model_key, model_version, source_text, embedding, created_at) '.
            'VALUES (?, ?, ?, ?, ?, now())',
            [$tagId, 'siglip2-so400m-patch14-384', 'rev-2', 'Каска', $vector]
        );

        $this->assertEquals(2, DB::table('tag_embeddings')->where('tag_id', $tagId)->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement(
            'INSERT INTO tag_embeddings (tag_id, model_key, model_version, source_text, embedding, created_at) '.
            'VALUES (?, ?, ?, ?, ?, now())',
            [$tagId, 'siglip2-so400m-patch14-384', 'rev-2', 'Каска дубль', $vector]
        );
    }
}
