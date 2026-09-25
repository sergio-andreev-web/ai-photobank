<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('status', 32);

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('image_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_tasks');
    }
};
