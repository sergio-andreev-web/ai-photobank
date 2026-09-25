<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_candidate_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('tag_candidates')->cascadeOnDelete();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();

            $table->decimal('confidence', 5, 4);

            $table->timestamp('created_at')->useCurrent();

            // ADR-0008: at most one link per candidate/image pair; repeated detection updates
            // it instead of inserting a duplicate.
            $table->unique(['candidate_id', 'image_id']);
            $table->index('image_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_candidate_images');
    }
};
