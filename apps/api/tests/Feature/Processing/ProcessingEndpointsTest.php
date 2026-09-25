<?php

namespace Tests\Feature\Processing;

use App\Contracts\Ai\Exceptions\AiException;
use App\Models\ProcessingTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Concerns\RunsProcessingQueue;
use Tests\TestCase;

/**
 * POST /api/admin/images/{id}/reprocess and /retry
 * (contracts/public-api.openapi.yaml reprocessImage/retryImage; §76, ADR-0003).
 */
class ProcessingEndpointsTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;
    use RunsProcessingQueue;

    private const array IMAGE_DETAIL_KEYS = [
        'id', 'batch_id', 'original_name', 'status', 'urls', 'created_at', 'updated_at', 'mime_type', 'extension',
        'width', 'height', 'file_size', 'sha256', 'caption', 'ocr_text', 'error_code', 'error_message',
        'image_tags', 'latest_analysis', 'processing_tasks',
    ];

    public function test_endpoints_require_authentication_and_csrf(): void
    {
        $this->withSession(['_token' => $this->csrfToken()]);
        $this->postJson('/api/admin/images/1/reprocess', [], $this->csrfHeaders())->assertStatus(401);
        $this->postJson('/api/admin/images/1/retry', [], $this->csrfHeaders())->assertStatus(401);

        $this->loginAsAdmin();
        $this->postJson('/api/admin/images/1/retry')->assertStatus(419)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_reprocess_ready_image_returns_202_image_detail_queued(): void
    {
        $this->loginAsAdmin();
        $image = $this->processedImage();

        $response = $this->postJson("/api/admin/images/{$image->id}/reprocess", [], $this->csrfHeaders());

        $response->assertStatus(202);
        $response->assertJsonStructure(self::IMAGE_DETAIL_KEYS);
        $response->assertJsonPath('status', 'queued');
        $response->assertJsonPath('latest_analysis.id', $image->analyses()->sole()->id);

        // processing_tasks shows the latest task per type: the new queued ai_analysis.
        $tasks = collect($response->json('processing_tasks'))->keyBy('type');
        $this->assertSame(['queued', 0, 4], [$tasks['ai_analysis']['status'], $tasks['ai_analysis']['attempts'], $tasks['ai_analysis']['max_attempts']]);
        $this->assertSame('succeeded', $tasks['finalize']['status']);
        $this->assertCount(5, $tasks);
    }

    public function test_reprocess_in_flight_image_is_a_202_no_op(): void
    {
        $this->loginAsAdmin();
        $image = $this->createUploadedImage();
        $this->runQueuedJobs(2); // image is now queued for AI

        $response = $this->postJson("/api/admin/images/{$image->id}/reprocess", [], $this->csrfHeaders());

        $response->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertSame(1, ProcessingTask::where('image_id', $image->id)->where('type', 'ai_analysis')->count());
    }

    public function test_retry_failed_image_returns_202_queued_with_reset_task(): void
    {
        $this->loginAsAdmin();
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::unavailable('down')));
        $image = $this->processedImage();
        $this->assertSame('failed', $image->status);

        $response = $this->postJson("/api/admin/images/{$image->id}/retry", [], $this->csrfHeaders());

        $response->assertStatus(202);
        $response->assertJsonStructure(self::IMAGE_DETAIL_KEYS);
        $response->assertJsonPath('status', 'queued');
        $response->assertJsonPath('error_code', null);
        $task = collect($response->json('processing_tasks'))->firstWhere('type', 'ai_analysis');
        $this->assertSame(['queued', 0, null], [$task['status'], $task['attempts'], $task['error_code']]);

        $this->runQueuedJobs();
        $this->getJson("/api/admin/images/{$image->id}")->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_retry_non_failed_image_is_409_image_not_retryable(): void
    {
        $this->loginAsAdmin();
        $image = $this->processedImage();

        $response = $this->postJson("/api/admin/images/{$image->id}/retry", [], $this->csrfHeaders());

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_RETRYABLE');
        $response->assertJsonPath('error.details.status', 'ready');
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('error.trace_id'));
    }

    public function test_unknown_or_deleted_image_is_404(): void
    {
        $this->loginAsAdmin();
        $image = $this->processedImage();
        $image->delete();

        foreach (['reprocess', 'retry'] as $action) {
            $this->postJson("/api/admin/images/{$image->id}/{$action}", [], $this->csrfHeaders())
                ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
            $this->postJson("/api/admin/images/999999/{$action}", [], $this->csrfHeaders())
                ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
        }
    }

    public function test_failed_image_detail_exposes_error_without_internal_paths(): void
    {
        $this->loginAsAdmin();
        $image = $this->createUploadedImage(contents: 'garbage');
        $this->runQueuedJobs();

        $response = $this->getJson("/api/admin/images/{$image->id}");

        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('error_code', 'METADATA_EXTRACTION_FAILED');
        $this->assertStringNotContainsString('originals/', (string) $response->json('error_message'));
        $this->assertStringNotContainsString('/', (string) $response->json('processing_tasks.0.error_message'));
    }
}
