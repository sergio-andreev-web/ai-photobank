<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_candidates', function (Blueprint $table) {
            $table->id();

            $table->string('display_name', 255);
            $table->string('normalized_name', 255);
            $table->string('type', 64);

            // Restrict, not nullOnDelete: ADR-0007 counts a closest_tag_id reference as the tag
            // being "in use", so it must be archived rather than hard-deleted while this exists.
            $table->foreignId('closest_tag_id')->nullable()->constrained('tags')->restrictOnDelete();
            $table->decimal('similarity', 6, 5)->nullable();

            $table->string('status', 32);
            $table->unsignedInteger('occurrences_count')->default(1);

            // ADR-0008 (accepted, adds to §23): records which tag the resolution produced
            // (created or merge target), for audit of the moderation decision.
            $table->foreignId('resolved_tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // §39 upsert key.
            $table->unique(['normalized_name', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_candidates');
    }
};
