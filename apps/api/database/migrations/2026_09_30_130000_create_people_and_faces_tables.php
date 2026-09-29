<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0015: people and the faces found on images. A face vector is biometric data (152-ФЗ):
 * deleting a person deletes their faces (see PersonController::destroy).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            // mb_strtolower(name): case-insensitive search that does not depend on the DB collation.
            $table->string('name_normalized', 255);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('name_normalized');
        });

        Schema::create('faces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            // Box in fractions of the image (0..1) and the face size in pixels of the analyzed copy.
            $table->float('box_x');
            $table->float('box_y');
            $table->float('box_width');
            $table->float('box_height');
            $table->unsignedInteger('size_px');
            $table->float('score');
            $table->string('model_revision', 255);
            $table->string('status', 20)->default('unassigned');
            $table->float('similarity')->nullable();
            // People an admin said this face is not (never suggested again).
            $table->json('rejected_person_ids')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'status']);
            $table->index(['status', 'model_revision']);
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE faces ADD COLUMN embedding vector(512) NOT NULL');
        } else {
            // sqlite (tests): opaque JSON text; cosine is computed in PHP.
            Schema::table('faces', function (Blueprint $table): void {
                $table->text('embedding')->nullable();
            });
        }

        Schema::table('images', function (Blueprint $table): void {
            // Revision of the face model the image was scanned with; null — not scanned yet.
            $table->string('faces_revision', 255)->nullable()->after('caption_edited');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->dropColumn('faces_revision');
        });
        Schema::dropIfExists('faces');
        Schema::dropIfExists('people');
    }
};
