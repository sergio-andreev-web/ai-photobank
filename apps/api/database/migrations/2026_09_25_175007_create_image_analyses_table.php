<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();

            $table->string('vision_model', 255);
            $table->string('vision_model_version', 255);

            $table->string('embedding_model', 255);
            $table->string('embedding_model_version', 255);

            $table->string('prompt_version', 64);

            $table->text('caption')->nullable();

            $table->jsonb('concepts');
            $table->jsonb('ocr');

            // §22: never discard the raw AI response, needed for debug/model comparison and
            // reprocessing; §76 keeps every past analysis row instead of overwriting it.
            $table->jsonb('raw_response');

            $table->integer('processing_ms')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('image_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_analyses');
    }
};
