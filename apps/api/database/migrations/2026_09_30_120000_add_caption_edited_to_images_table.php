<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0006, 2026-09-30 addendum: a caption fixed by an admin survives reprocess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->boolean('caption_edited')->default(false)->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->dropColumn('caption_edited');
        });
    }
};
