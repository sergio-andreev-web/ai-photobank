<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();

            $table->string('model_key', 255);
            $table->string('model_version', 255);

            $table->boolean('active')->default(true);

            $table->timestamp('created_at')->useCurrent();
        });

        if ($driver === 'pgsql') {
            // §20/§31: SigLIP2 image embedding, fixed 1152 dimensions for MVP (embedding_dimensions
            // const in contracts/schemas/image-analysis.json). No first-party Blueprint column type
            // for pgvector, so the column and its ANN index are added with raw SQL.
            DB::statement('ALTER TABLE image_embeddings ADD COLUMN embedding vector(1152) NOT NULL');

            // ADR-0009: exactly one active embedding row per image, enforced at the DB level so a
            // buggy reprocess can never leave two active versions for the same image.
            DB::statement(
                'CREATE UNIQUE INDEX image_embeddings_active_unique ON image_embeddings (image_id) WHERE active'
            );

            // ADR-0009: HNSW cosine index, partial on active rows only — semantic search must never
            // rank against superseded embedding versions.
            DB::statement(
                'CREATE INDEX image_embeddings_embedding_hnsw ON image_embeddings '
                .'USING hnsw (embedding vector_cosine_ops) WHERE active'
            );
        } else {
            // Non-Postgres connections (sqlite in tests) have no vector type or HNSW access
            // method; keep the column present as opaque storage so schema/relationship tests
            // can run without a real Postgres instance. Vector search itself is out of scope
            // for this task and is exercised against Postgres only.
            Schema::table('image_embeddings', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_embeddings');
    }
};
