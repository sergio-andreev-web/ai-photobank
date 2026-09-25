<?php

namespace Tests\Feature\Admin;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Batch;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

class BatchControllerTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photobank');
    }

    public function test_batches_require_authentication(): void
    {
        $this->getJson('/api/admin/batches')->assertStatus(401);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->loginAsAdmin(['role' => 'moderator']);

        $this->getJson('/api/admin/batches')->assertStatus(403);
    }

    public function test_create_batch_returns_created_status_with_zero_progress(): void
    {
        $this->loginAsAdmin();

        $response = $this->postJson('/api/admin/batches', ['name' => 'September batch'], $this->csrfHeaders());

        $response->assertCreated();
        $response->assertJsonPath('name', 'September batch');
        $response->assertJsonPath('status', 'created');
        $response->assertJsonPath('progress.total', 0);
        $response->assertJsonPath('progress.processed', 0);
        $response->assertJsonPath('progress.failed', 0);
        $response->assertHeader('Location');
    }

    public function test_create_batch_without_name_is_allowed(): void
    {
        $this->loginAsAdmin();

        $response = $this->postJson('/api/admin/batches', [], $this->csrfHeaders());

        $response->assertCreated();
        $response->assertJsonPath('name', null);
    }

    public function test_get_batch_not_found(): void
    {
        $this->loginAsAdmin();

        $response = $this->getJson('/api/admin/batches/999');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'BATCH_NOT_FOUND');
    }

    public function test_list_batches_filters_by_status(): void
    {
        $user = $this->loginAsAdmin();
        Batch::create(['status' => 'created', 'created_by' => $user->id]);
        Batch::create(['status' => 'completed', 'created_by' => $user->id, 'completed_at' => now()]);

        $response = $this->getJson('/api/admin/batches?status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', 'completed');
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_upload_image_creates_image_and_transitions_batch_to_uploading(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        $file = UploadedFile::fake()->image('photo.jpg', 20, 20);

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertCreated();
        $response->assertJsonPath('status', 'uploaded');
        $response->assertJsonPath('batch_id', $batch->id);
        $response->assertHeader('Location');

        $this->assertDatabaseHas('images', [
            'batch_id' => $batch->id,
            'status' => 'uploaded',
        ]);

        $batch->refresh();
        $this->assertSame('uploading', $batch->status);

        $image = Image::first();
        Storage::disk('photobank')->assertExists($image->storage_key);
    }

    public function test_upload_image_rejects_duplicate_sha256(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        $file = UploadedFile::fake()->image('photo.jpg', 20, 20);

        $first = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );
        $first->assertCreated();
        $existingImageId = $first->json('id');

        $duplicateFile = UploadedFile::fake()->createWithContent('photo.jpg', file_get_contents($file->getRealPath()));

        $second = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $duplicateFile],
            $this->csrfHeaders(),
        );

        $second->assertStatus(409);
        $second->assertJsonPath('error.code', 'IMAGE_ALREADY_EXISTS');
        $second->assertJsonPath('error.details.existing_image_id', $existingImageId);
    }

    public function test_upload_image_handles_concurrent_duplicate_insert_as_409(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        $file = UploadedFile::fake()->image('race.jpg', 20, 20);
        $sha256 = hash_file('sha256', $file->getRealPath());

        // Simulate a second request winning the race: it commits a row with the same sha256
        // while this request is still writing the file — after the duplicate pre-check, before
        // our own INSERT — so the unique index, not the pre-check, catches the duplicate
        // (ADR-0005: "unique index guarantees one successful upload, the second gets 409").
        $storedKey = null;
        $this->mock(PhotoStorageInterface::class, function (MockInterface $storage) use (&$storedKey, $batch, $sha256, $user): void {
            $storage->shouldReceive('put')->once()->andReturnUsing(function (string $storageKey) use (&$storedKey, $batch, $sha256, $user): void {
                $storedKey = $storageKey;
                Image::create([
                    'batch_id' => $batch->id,
                    'original_name' => 'winner.jpg',
                    'storage_key' => 'originals/2026/09/'.$batch->id.'/winner.jpg',
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                    'file_size' => 1,
                    'sha256' => $sha256,
                    'status' => 'uploaded',
                    'created_by' => $user->id,
                ]);
            });
            // The losing request must remove the original it has already stored.
            $storage->shouldReceive('delete')->once()->withArgs(function (string $key) use (&$storedKey): bool {
                return $key === $storedKey;
            })->andReturnTrue();
        });

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'IMAGE_ALREADY_EXISTS');

        $winner = Image::where('sha256', $sha256)->where('original_name', 'winner.jpg')->firstOrFail();
        $response->assertJsonPath('error.details.existing_image_id', $winner->id);

        // Only the "winning" row exists — our own losing INSERT must not have landed.
        $this->assertSame(1, Image::where('sha256', $sha256)->count());
    }

    public function test_upload_image_rejects_file_larger_than_configured_limit(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        // 51201 KiB > the 50 MiB (52428800 bytes) default from config/photobank.php
        // (PHOTO_MAX_UPLOAD_BYTES). Laravel's fake() creates this as a sparse file,
        // so it costs no real disk I/O.
        $file = UploadedFile::fake()->create('big.jpg', 51201, 'image/jpeg');

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'FILE_TOO_LARGE');
        $this->assertDatabaseCount('images', 0);
    }

    public function test_upload_image_rejects_php_ini_size_error_as_413(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        // Simulates PHP itself rejecting the upload for exceeding upload_max_filesize/
        // post_max_size (UPLOAD_ERR_INI_SIZE) before any of our own validation runs —
        // distinct from our own app-level size check above.
        $path = tempnam(sys_get_temp_dir(), 'upload-ini-size-');
        file_put_contents($path, 'irrelevant, PHP itself already rejected this upload');
        $file = new UploadedFile($path, 'too-big.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'FILE_TOO_LARGE');
        $this->assertDatabaseCount('images', 0);
    }

    public function test_upload_image_rejects_unsupported_mime_type(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        $file = UploadedFile::fake()->create('document.pdf', 10, 'application/pdf');

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertStatus(415);
        $response->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
    }

    public function test_upload_image_rejects_batch_not_accepting_uploads(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'completed', 'created_by' => $user->id, 'completed_at' => now()]);

        $file = UploadedFile::fake()->image('photo.jpg', 20, 20);

        $response = $this->post(
            "/api/admin/batches/{$batch->id}/images",
            ['image' => $file],
            $this->csrfHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'BATCH_NOT_ACCEPTING_UPLOADS');
        $response->assertJsonPath('error.details.status', 'completed');
    }

    public function test_complete_is_idempotent_and_moves_batch_to_processing_when_images_are_pending(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'uploading', 'created_by' => $user->id]);
        Image::create([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/a.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'a'),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ]);

        $first = $this->postJson("/api/admin/batches/{$batch->id}/complete", [], $this->csrfHeaders());
        $first->assertOk();
        $first->assertJsonPath('status', 'processing');

        $second = $this->postJson("/api/admin/batches/{$batch->id}/complete", [], $this->csrfHeaders());
        $second->assertOk();
        $second->assertJsonPath('status', 'processing');
    }

    public function test_complete_finalizes_empty_batch_as_completed(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        $response = $this->postJson("/api/admin/batches/{$batch->id}/complete", [], $this->csrfHeaders());

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');
        $this->assertNotNull($response->json('completed_at'));
    }

    public function test_complete_finalizes_batch_with_only_failed_images_as_failed(): void
    {
        $user = $this->loginAsAdmin();
        $batch = Batch::create(['status' => 'uploading', 'created_by' => $user->id]);
        Image::create([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/a.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'a'),
            'status' => 'failed',
            'error_code' => 'AI_SERVICE_UNAVAILABLE',
            'created_by' => $user->id,
        ]);

        $response = $this->postJson("/api/admin/batches/{$batch->id}/complete", [], $this->csrfHeaders());

        $response->assertOk();
        $response->assertJsonPath('status', 'failed');
    }
}
