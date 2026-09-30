<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('object_research_enabled')->default(false);
            $t->string('object_research_provider')->default('codex_cli');
            $t->timestamps();
        });
        Schema::create('image_research_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('image_id')->constrained()->cascadeOnDelete();
            $t->string('trigger');
            $t->string('provider');
            $t->text('question');
            $t->string('status')->default('queued');
            $t->json('result')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['image_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_research_runs');
        Schema::dropIfExists('research_settings');
    }
};
