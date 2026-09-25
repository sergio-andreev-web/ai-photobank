<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photobank');
    }

    private function makeImage(User $user): Image
    {
        $batch = Batch::create(['status' => 'uploading', 'created_by' => $user->id]);
        $storageKey = 'originals/2026/09/'.$batch->id.'/original.jpg';
        Storage::disk('photobank')->put($storageKey, 'fake-original-bytes');

        return Image::create([
            'batch_id' => $batch->id,
            'original_name' => 'photo one.jpg',
            'storage_key' => $storageKey,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => strlen('fake-original-bytes'),
            'sha256' => hash('sha256', 'fake-original-bytes'),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ]);
    }

    public function test_media_requires_authentication(): void
    {
        $this->getJson('/api/media/images/1/original')->assertStatus(401);
    }

    public function test_original_variant_is_served(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->get("/api/media/images/{$image->id}/original");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        // Symfony's ResponseHeaderBag rebuilds Cache-Control from its internal directive map
        // (alphabetical), so assert directive presence rather than the exact literal string.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $response->assertHeader('ETag');

        // ADR-0011: original is served inline (not as a forced download), with the
        // sanitized original filename.
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $contentDisposition);
        $this->assertStringContainsString('photo one.jpg', $contentDisposition);
    }

    public function test_soft_deleted_image_returns_404(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);
        $image->delete();

        $response = $this->getJson("/api/media/images/{$image->id}/original");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_thumbnail_not_yet_available_returns_404(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->getJson("/api/media/images/{$image->id}/thumbnail");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'MEDIA_NOT_AVAILABLE');
        $response->assertJsonPath('error.details.variant', 'thumbnail');
    }

    public function test_unknown_image_returns_404(): void
    {
        $this->loginAsAdmin();

        $response = $this->getJson('/api/media/images/999/original');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_matching_if_none_match_returns_304(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $etag = '"'.$image->sha256.'"';

        $response = $this->get("/api/media/images/{$image->id}/original", [
            'If-None-Match' => $etag,
        ]);

        $response->assertStatus(304);
    }
}
