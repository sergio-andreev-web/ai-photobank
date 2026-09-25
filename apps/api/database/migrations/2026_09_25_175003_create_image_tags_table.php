<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();
            // Restrict: a tag referenced by image_tags is "in use" (ADR-0007) and must be
            // archived, not hard-deleted, while this link exists.
            $table->foreignId('tag_id')->constrained('tags')->restrictOnDelete();

            $table->string('source', 32);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('locked')->default(false);

            $table->timestamps();

            // unique(image_id, tag_id) already covers lookups by image_id alone (§79);
            // tag_id needs its own index since it isn't the leading column.
            $table->unique(['image_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_tags');
    }
};
