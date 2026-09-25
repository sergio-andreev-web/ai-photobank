<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('upload_batches')->restrictOnDelete();

            $table->string('original_name', 1024);
            $table->string('storage_key', 2048);
            $table->string('preview_key', 2048)->nullable();
            $table->string('thumbnail_key', 2048)->nullable();

            $table->string('mime_type', 100);
            $table->string('extension', 16);

            // §15/state-machines.md: status=uploaded means metadata has not run yet, so
            // dimensions are filled in by the `metadata` processing task, not at upload time.
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();

            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);

            $table->text('caption')->nullable();
            $table->text('ocr_text')->nullable();

            $table->string('status', 32);

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('sha256');
            $table->index('status');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
