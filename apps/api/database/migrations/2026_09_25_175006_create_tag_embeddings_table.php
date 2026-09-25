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

        Schema::create('tag_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->string('model_key', 255);
            $table->string('model_version', 255);

            $table->text('source_text');

            $table->timestamp('created_at')->useCurrent();

            // ADR-0009 (accepted, revises §21): unique widened to tag_id + model_key +
            // model_version so a re-embed during a revision upgrade can coexist with the old
            // row until it is superseded, instead of colliding on tag_id + model_key alone.
            $table->unique(['tag_id', 'model_key', 'model_version']);
            $table->index(['model_key', 'model_version']);
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tag_embeddings ADD COLUMN embedding vector(1152) NOT NULL');

            // ADR-0009: HNSW cosine index for stage-3 resolver search; the accompanying
            // (model_key, model_version) btree index above lets queries exclude
            // incompatible embedding versions before/around the ANN search.
            DB::statement(
                'CREATE INDEX tag_embeddings_embedding_hnsw ON tag_embeddings '
                .'USING hnsw (embedding vector_cosine_ops)'
            );
        } else {
            Schema::table('tag_embeddings', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_embeddings');
    }
};
