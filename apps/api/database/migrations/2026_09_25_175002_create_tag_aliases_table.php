<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->string('alias', 255);
            $table->string('normalized_alias', 255);
            $table->string('locale', 10)->nullable();

            $table->timestamps();

            // ADR-0007 (accepted): alias uniqueness is global, not per-tag, so stage 2
            // resolver lookup can never match more than one tag.
            $table->unique('normalized_alias');
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_aliases');
    }
};
