<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('tags')->restrictOnDelete();

            $table->string('name', 255);
            $table->string('slug', 255);
            $table->string('normalized_name', 255);
            $table->string('type', 64);
            $table->string('status', 32);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['normalized_name', 'type']);
            $table->unique('slug');
            $table->index('normalized_name');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
