<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

class ImageControllerTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    private function makeImage(User $user, array $attributes = []): Image
    {
        $batch = Batch::create(['status' => 'uploading', 'created_by' => $user->id]);

        return Image::create(array_merge([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/'.uniqid('', true).'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', uniqid('', true)),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ], $attributes));
    }

    public function test_latest_analysis_prefers_the_newer_row_when_timestamps_tie(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user, ['status' => 'ready']);
        $analyze = fn (string $caption): ImageAnalysis => ImageAnalysis::create([
            'image_id' => $image->id,
            'vision_model' => 'Qwen/Qwen3-VL-4B-Instruct',
            'vision_model_version' => 'rev-1',
            'embedding_model' => 'google/siglip2-so400m-patch14-384',
            'embedding_model_version' => 'rev-1',
            'prompt_version' => 'image-analysis-v1',
            'caption' => $caption,
            'concepts' => [],
            'ocr' => [],
            'raw_response' => [],
            'processing_ms' => 1,
        ]);

        // Reprocess within the same second leaves both history rows with one timestamp.
        Carbon::setTestNow('2026-09-25 12:00:00');
        $analyze('before reprocess');
        $newer = $analyze('after reprocess');
        Carbon::setTestNow();

        $response = $this->getJson("/api/admin/images/{$image->id}");

        $response->assertOk();
        $response->assertJsonPath('latest_analysis.id', $newer->id);
        $response->assertJsonPath('latest_analysis.caption', 'after reprocess');
    }

    public function test_images_require_authentication(): void
    {
        $this->getJson('/api/admin/images')->assertStatus(401);
    }

    public function test_list_images_filters_by_status_and_batch(): void
    {
        $user = $this->loginAsAdmin();
        $ready = $this->makeImage($user, ['status' => 'ready']);
        $this->makeImage($user, ['status' => 'failed']);

        $response = $this->getJson('/api/admin/images?status=ready');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $ready->id);
    }

    public function test_get_image_returns_detail_with_empty_relations_before_processing(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->getJson("/api/admin/images/{$image->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $image->id);
        $response->assertJsonPath('status', 'uploaded');
        $response->assertJsonPath('image_tags', []);
        $response->assertJsonPath('latest_analysis', null);
        $response->assertJsonPath('processing_tasks', []);
        $response->assertJsonPath('urls.thumbnail', null);
        $response->assertJsonPath('urls.preview', null);
        $response->assertJsonPath('urls.original', "/api/media/images/{$image->id}/original");
    }

    public function test_get_image_not_found(): void
    {
        $this->loginAsAdmin();

        $response = $this->getJson('/api/admin/images/999');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_deleted_image_returns_404(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);
        $image->delete();

        $response = $this->getJson("/api/admin/images/{$image->id}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_update_image_renames_original_name(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->patchJson(
            "/api/admin/images/{$image->id}",
            ['original_name' => 'renamed.jpg'],
            $this->csrfHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('original_name', 'renamed.jpg');
        $this->assertDatabaseHas('images', ['id' => $image->id, 'original_name' => 'renamed.jpg']);
    }

    public function test_update_image_rejects_unknown_fields(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->patchJson(
            "/api/admin/images/{$image->id}",
            ['caption' => 'not editable'],
            $this->csrfHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_delete_image_soft_deletes_and_repeat_delete_is_404(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $first = $this->deleteJson("/api/admin/images/{$image->id}", [], $this->csrfHeaders());
        $first->assertNoContent();

        $this->assertSoftDeleted('images', ['id' => $image->id]);

        $second = $this->deleteJson("/api/admin/images/{$image->id}", [], $this->csrfHeaders());
        $second->assertStatus(404);
        $second->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_delete_image_without_csrf_token_returns_419(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage($user);

        $response = $this->deleteJson("/api/admin/images/{$image->id}");

        $response->assertStatus(419);
        $response->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }
}
