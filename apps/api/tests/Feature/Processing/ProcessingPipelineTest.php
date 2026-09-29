<?php

namespace Tests\Feature\Processing;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\Concept;
use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Exceptions\ApiException;
use App\Jobs\RunProcessingTask;
use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\ImageEmbedding;
use App\Models\ProcessingTask;
use App\Models\User;
use App\Services\Ai\FakeAiClient;
use App\Services\Processing\BatchStatusUpdater;
use App\Services\Processing\ProcessingPipeline;
use App\Services\Processing\StageFailure;
use App\Support\Presenters\BatchPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\RunsProcessingQueue;
use Tests\Support\ReentrantAiClient;
use Tests\TestCase;

/**
 * Background pipeline invariants (ТЗ §25, §28, §46, §47, §76; ADR-0003,
 * ADR-0005, ADR-0009, ADR-0010; contracts/state-machines.md) with
 * FakeAiClient — no GPU, no network, no real broker.
 */
class ProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;
    use RunsProcessingQueue;

    private const array ORDER = ['metadata', 'variants', 'ai_analysis', 'tag_resolution', 'finalize'];

    public function test_happy_path_runs_all_stages_in_order_and_persists_results(): void
    {
        $image = $this->createUploadedImage(contents: $this->jpeg(2000, 1000));

        $this->assertSame('uploaded', $image->refresh()->status);
        $this->assertSame(0, $this->ai->analyzeCallCount(), 'upload must not wait for AI (§81)');

        $this->runQueuedJobs();
        $image->refresh();

        $this->assertSame('ready', $image->status);
        $this->assertNull($image->error_code);
        $this->assertSame([2000, 1000], [$image->width, $image->height]);
        $this->assertSame(self::ORDER, $this->taskTypes($image));
        $this->assertTrue(ProcessingTask::where('image_id', $image->id)->get()->every(
            fn (ProcessingTask $t) => $t->status === 'succeeded' && $t->attempts === 1 && $t->finished_at !== null,
        ));

        // Variants at fixed keys (§11, §12), scaled down, WebP; original untouched.
        $disk = Storage::disk('photobank');
        $this->assertSame("variants/{$image->id}/thumbnail.webp", $image->thumbnail_key);
        $this->assertSame("variants/{$image->id}/preview.webp", $image->preview_key);
        $this->assertSame([400, 200, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($disk->get($image->thumbnail_key)), 0, 3));
        $this->assertSame([1600, 800, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($disk->get($image->preview_key)), 0, 3));
        $this->assertSame($image->sha256, hash('sha256', $disk->get($image->storage_key)));

        // Analysis with raw response (§22), caption/OCR on the image.
        $analysis = ImageAnalysis::where('image_id', $image->id)->sole();
        $this->assertSame(FakeAiClient::VISION_REVISION, $analysis->vision_model_version);
        $this->assertSame(FakeAiClient::EMBEDDING_REVISION, $analysis->embedding_model_version);
        $this->assertCount(1152, $analysis->raw_response['embedding']);
        $this->assertSame($this->ai->analyzeRequestIds()[0], $analysis->raw_response['request_id']);
        $this->assertSame('Fake caption for deterministic test fixture.', $image->caption);
        $this->assertSame('FAKE OCR TEXT', $image->ocr_text);

        // Embedding version per ADR-0009, active because it matches the configured version.
        $embedding = ImageEmbedding::where('image_id', $image->id)->sole();
        $this->assertSame(['siglip2-stub', FakeAiClient::EMBEDDING_REVISION, true], [$embedding->model_key, $embedding->model_version, $embedding->active]);

        // AI stage runs on the images.ai queue, the rest on images.processing (§48).
        $this->assertSame(
            ['images.processing', 'images.processing', 'images.ai', 'images.processing', 'images.processing'],
            array_map(fn (RunProcessingTask $job) => $job->queue, $this->pushedJobs()),
        );
    }

    public function test_image_status_follows_the_state_machine_per_stage(): void
    {
        $image = $this->createUploadedImage();
        $seen = [];

        while ($this->runQueuedJobs(1) === 1) {
            $seen[] = $image->refresh()->status;
        }

        // metadata → uploaded, variants → queued, AI phase → processing, finalize → ready.
        $this->assertSame(['uploaded', 'queued', 'processing', 'processing', 'ready'], $seen);
    }

    public function test_transient_ai_failures_are_retried_with_backoff_and_then_succeed(): void
    {
        $this->ai->failAnalyzeWith(
            AiException::unavailable('connection refused'),
            AiException::fromErrorCode('MODEL_NOT_READY', 'loading', 503),
        );
        $image = $this->createUploadedImage();

        $this->runQueuedJobs(3); // metadata, variants, failing ai attempt 1
        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['queued', 1, 'AI_SERVICE_UNAVAILABLE'], [$task->status, $task->attempts, $task->error_code]);
        $this->assertSame('processing', $image->refresh()->status, 'image keeps processing while AI retries (§46)');
        $this->assertSame(0, BatchPresenter::progress($image->batch)['processed']);

        $this->runQueuedJobs();

        $task->refresh();
        $this->assertSame(['succeeded', 3, null], [$task->status, $task->attempts, $task->error_code]);
        $this->assertSame('ready', $image->refresh()->status);
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count());
        $this->assertSame([10, 30], $this->retryDelays());
    }

    public function test_exhausted_ai_retries_fail_task_image_and_batch(): void
    {
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::fromErrorCode('SERVICE_BUSY', 'busy', 429)));
        $batch = $this->makeBatch('processing');
        $image = $this->createUploadedImage($batch);

        $this->runQueuedJobs();

        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['failed', 4, 4, 'AI_SERVICE_UNAVAILABLE'], [$task->status, $task->attempts, $task->max_attempts, $task->error_code]);
        $this->assertNotNull($task->finished_at);
        $this->assertSame(4, $this->ai->analyzeCallCount(), 'first attempt + 3 retries (ADR-0005)');
        $this->assertSame([10, 30, 120], $this->retryDelays());

        $image->refresh();
        $this->assertSame(['failed', 'AI_SERVICE_UNAVAILABLE'], [$image->status, $image->error_code]);
        $this->assertSame('AI service error SERVICE_BUSY (HTTP 429)', $image->error_message);
        $this->assertSame(['ai_analysis'], array_values(array_diff($this->taskTypes($image), ['metadata', 'variants'])), 'no stage after the failed one');
        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_non_retryable_ai_rejection_fails_immediately(): void
    {
        $this->ai->failAnalyzeWith(AiException::fromErrorCode('IMAGE_DECODE_FAILED', 'bad image', 422));
        $image = $this->createUploadedImage();

        $this->runQueuedJobs();

        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['failed', 1, 'AI_REQUEST_REJECTED'], [$task->status, $task->attempts, $task->error_code]);
        $this->assertSame('AI_REQUEST_REJECTED', $image->refresh()->error_code);
        $this->assertSame([], $this->retryDelays());
    }

    public function test_model_output_invalid_502_is_retryable(): void
    {
        $this->ai->failAnalyzeWith(AiException::fromErrorCode('MODEL_OUTPUT_INVALID', 'bad json', 502));
        $image = $this->createUploadedImage();

        $this->runQueuedJobs();

        $this->assertSame(['succeeded', 2], [$this->task($image, 'ai_analysis')->status, $this->task($image, 'ai_analysis')->attempts]);
        $this->assertSame('ready', $image->refresh()->status);
    }

    public function test_exhausted_model_output_invalid_retries_fail_with_ai_output_invalid(): void
    {
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::fromErrorCode('MODEL_OUTPUT_INVALID', 'bad json', 502)));
        $image = $this->createUploadedImage();

        $this->runQueuedJobs();

        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['failed', 4, 'AI_OUTPUT_INVALID'], [$task->status, $task->attempts, $task->error_code]);
        $this->assertSame([10, 30, 120], $this->retryDelays());
        $image->refresh();
        $this->assertSame(['failed', 'AI_OUTPUT_INVALID'], [$image->status, $image->error_code]);
    }

    public function test_invalid_2xx_ai_response_fails_without_retry(): void
    {
        $this->ai->failAnalyzeWith(new InvalidAiResponseException(['/embedding: must have 1152..1152 items, got 2']));
        $image = $this->createUploadedImage();

        $this->runQueuedJobs();

        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['failed', 1, 'AI_RESPONSE_INVALID'], [$task->status, $task->attempts, $task->error_code]);
        $this->assertSame('failed', $image->refresh()->status);
        $this->assertSame(0, ImageAnalysis::where('image_id', $image->id)->count());
    }

    public function test_undecodable_original_fails_metadata_without_retry(): void
    {
        $image = $this->createUploadedImage(contents: 'definitely not an image');

        $this->runQueuedJobs();

        $task = $this->task($image, 'metadata');
        $this->assertSame(['failed', 1, 'METADATA_EXTRACTION_FAILED'], [$task->status, $task->attempts, $task->error_code]);
        $image->refresh();
        $this->assertSame(['failed', 'METADATA_EXTRACTION_FAILED', null], [$image->status, $image->error_code, $image->width]);
        $this->assertSame(0, $this->ai->analyzeCallCount());
    }

    public function test_unexpected_exception_is_retried_as_internal_error_without_leaking_details(): void
    {
        $this->resolver->failures = [new RuntimeException('SQLSTATE /var/www/storage/secret path')];
        $image = $this->createUploadedImage();

        $this->runQueuedJobs(4); // up to the failing tag_resolution attempt
        $task = $this->task($image, 'tag_resolution');
        $this->assertSame(['queued', 1, 'INTERNAL_ERROR', 'Unexpected error in tag_resolution stage'], [$task->status, $task->attempts, $task->error_code, $task->error_message]);

        $this->runQueuedJobs();
        $this->assertSame('ready', $image->refresh()->status);
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count(), 'retrying tag_resolution does not re-run AI');
    }

    public function test_classified_stage_failure_uses_its_code(): void
    {
        $this->resolver->failures = [new StageFailure('TAG_RESOLUTION_FAILED', 'Embedding version mismatch', retryable: false)];
        $image = $this->createUploadedImage();

        $this->runQueuedJobs();

        $this->assertSame(['failed', 'TAG_RESOLUTION_FAILED', 'Embedding version mismatch'], [$image->refresh()->status, $image->error_code, $image->error_message]);
    }

    public function test_duplicate_message_is_a_no_op(): void
    {
        $image = $this->createUploadedImage();
        $this->runQueuedJobs();

        // Every message delivered a second time (at-least-once broker, double dispatch).
        foreach ($this->pushedJobs() as $job) {
            $this->runJob($job);
        }

        $this->assertSame(self::ORDER, $this->taskTypes($image));
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count());
        $this->assertSame(1, ImageEmbedding::where('image_id', $image->id)->count());
        $this->assertSame(1, $this->ai->analyzeCallCount());
        $this->assertCount(5, $this->pushedJobs(), 'no extra messages');
    }

    public function test_redelivery_after_worker_crash_reclaims_the_processing_task(): void
    {
        $image = $this->createUploadedImage();
        $this->runQueuedJobs(2);
        $aiJob = $this->pushedJobs()[2];

        // A worker claimed the task and died before finishing.
        $this->task($image, 'ai_analysis')->update(['status' => 'processing', 'attempts' => 1, 'started_at' => now()]);

        $this->runJob($aiJob); // plain duplicate: someone is (apparently) working on it
        $this->assertSame('processing', $this->task($image, 'ai_analysis')->status);

        $this->runJob($aiJob, redelivered: true); // broker redelivery after the crash
        $this->assertSame(['succeeded', 2], [$this->task($image, 'ai_analysis')->status, $this->task($image, 'ai_analysis')->attempts]);

        $this->runQueuedJobs();
        $this->assertSame('ready', $image->refresh()->status);
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count());
    }

    public function test_a_worker_that_lost_its_claim_does_not_persist(): void
    {
        $image = $this->createUploadedImage();
        $this->runQueuedJobs(2);
        $aiJob = $this->pushedJobs()[2];

        // While worker A waits for AI, the broker redelivers to worker B, which completes the task.
        $this->app->instance(AiClientInterface::class, new ReentrantAiClient($this->ai, function () use ($aiJob): void {
            $this->runJob($aiJob, redelivered: true);
        }));

        $this->runJob($aiJob);

        $task = $this->task($image, 'ai_analysis');
        $this->assertSame(['succeeded', 2], [$task->status, $task->attempts]);
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count(), 'worker A must not add a second analysis');
        $this->assertSame(1, ProcessingTask::where('image_id', $image->id)->where('type', 'tag_resolution')->count());
    }

    public function test_soft_deleted_image_stops_at_the_next_stage(): void
    {
        $batch = $this->makeBatch('processing');
        $image = $this->createUploadedImage($batch);
        $this->runQueuedJobs(2);

        $image->delete();
        $this->runQueuedJobs();

        $this->assertSame(0, $this->ai->analyzeCallCount());
        $this->assertSame('queued', $this->task($image, 'ai_analysis')->status);
    }

    public function test_embedding_of_a_non_active_version_is_kept_inactive(): void
    {
        $image = $this->processedImage();
        $active = ImageEmbedding::where('image_id', $image->id)->sole();

        config(['ai.embedding.active_model_version' => 'some-other-revision']);
        $this->reprocess($image);
        $this->runQueuedJobs();

        $this->assertTrue($active->refresh()->active, 'previous active embedding stays the searchable one');
        $this->assertFalse(ImageEmbedding::where('image_id', $image->id)->whereKeyNot($active->id)->sole()->active);
    }

    public function test_unset_active_version_activates_nothing(): void
    {
        config(['ai.embedding.active_model_version' => null]);

        $image = $this->processedImage();

        $this->assertSame('ready', $image->status);
        $this->assertFalse(ImageEmbedding::where('image_id', $image->id)->sole()->active);
    }

    public function test_broker_giving_up_marks_task_and_image_failed(): void
    {
        $batch = $this->makeBatch('processing');
        $image = $this->createUploadedImage($batch);

        $this->pushedJobs()[0]->failed(new RuntimeException('worker killed'));

        $this->assertSame(['failed', 'INTERNAL_ERROR'], [$this->task($image, 'metadata')->status, $this->task($image, 'metadata')->error_code]);
        $this->assertSame('failed', $image->refresh()->status);
        $this->assertSame('failed', $batch->refresh()->status);

        // Abandoning an already finished task changes nothing.
        $this->pushedJobs()[0]->failed(null);
        $this->assertSame('failed', $this->task($image, 'metadata')->status);
    }

    public function test_reprocess_creates_new_analysis_and_keeps_history(): void
    {
        $image = $this->processedImage();
        $first = ImageAnalysis::where('image_id', $image->id)->sole();
        $firstEmbedding = ImageEmbedding::where('image_id', $image->id)->sole();

        $this->ai->withConcepts([new Concept(ConceptType::Person, 'engineer', 0.9)]);
        $this->reprocess($image);

        $this->assertSame('queued', $image->refresh()->status);
        $this->assertSame('images.reprocess', last($this->pushedJobs())->queue);

        $this->runQueuedJobs();

        $analyses = ImageAnalysis::where('image_id', $image->id)->orderBy('id')->get();
        $this->assertCount(2, $analyses);
        $this->assertSame($first->id, $analyses[0]->id);
        $this->assertSame('fake object', $analyses[0]->concepts[0]['label'], 'old analysis is not modified');
        $this->assertSame('engineer', $analyses[1]->concepts[0]['label']);
        $this->assertSame([$analyses[1]->id], array_slice($this->resolver->analysisIds, -1), 'resolver sees the new analysis');

        // Reprocess is an AI-only cycle: metadata/variants are not repeated.
        $this->assertSame(['metadata', 'variants', 'ai_analysis', 'tag_resolution', 'finalize', 'ai_analysis', 'tag_resolution', 'finalize'], $this->taskTypes($image));

        $this->assertFalse($firstEmbedding->refresh()->active);
        $this->assertSame(1, ImageEmbedding::where('image_id', $image->id)->where('active', true)->count());
        $this->assertSame('ready', $image->refresh()->status);
    }

    public function test_reprocess_keeps_a_caption_edited_by_an_admin(): void
    {
        $image = $this->processedImage();
        $image->update(['caption' => 'Ручная подпись', 'caption_edited' => true]);

        $this->reprocess($image);
        $this->runQueuedJobs();

        $image->refresh();
        $this->assertSame('ready', $image->status);
        $this->assertSame('Ручная подпись', $image->caption);
        $this->assertTrue($image->caption_edited);
        // The new analysis still records what the model said (history, §76).
        $latest = ImageAnalysis::where('image_id', $image->id)->orderByDesc('id')->first();
        $this->assertSame('Fake caption for deterministic test fixture.', $latest->caption);
    }

    public function test_reprocess_of_failed_image_without_variants_starts_from_the_missing_stage(): void
    {
        $image = $this->createUploadedImage(contents: 'not an image');
        $this->runQueuedJobs();
        $this->assertSame('failed', $image->refresh()->status);

        Storage::disk('photobank')->put($image->storage_key, $this->jpeg(10, 10));
        $this->reprocess($image);

        $this->assertSame('metadata', ProcessingTask::where('image_id', $image->id)->latest('id')->first()->type);
        $this->runQueuedJobs();
        $this->assertSame('ready', $image->refresh()->status);
        $this->assertNull($image->error_code);
    }

    public function test_reprocess_while_in_flight_is_a_no_op(): void
    {
        $image = $this->createUploadedImage();

        foreach (['uploaded', 'queued', 'processing'] as $status) {
            $image->update(['status' => $status]);
            $before = ProcessingTask::count();

            $this->reprocess($image);

            $this->assertSame($before, ProcessingTask::count());
            $this->assertSame($status, $image->refresh()->status);
        }
    }

    public function test_concurrent_reprocess_requests_create_a_single_run(): void
    {
        $image = $this->processedImage();
        $messages = count($this->pushedJobs());

        $this->reprocess($image);
        $this->reprocess($image);

        $this->assertSame(2, ProcessingTask::where('image_id', $image->id)->where('type', 'ai_analysis')->count());
        $this->assertCount($messages + 1, $this->pushedJobs());

        $this->runQueuedJobs();
        $this->assertSame(2, ImageAnalysis::where('image_id', $image->id)->count());
    }

    public function test_retry_resumes_at_the_failed_task_with_attempts_reset(): void
    {
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::unavailable('down')));
        $batch = $this->makeBatch('processing');
        $image = $this->createUploadedImage($batch);
        $this->runQueuedJobs();
        $failedTask = $this->task($image, 'ai_analysis');
        $this->assertSame('failed', $batch->refresh()->status);

        app(ProcessingPipeline::class)->retry($image->id);

        $failedTask->refresh();
        $this->assertSame(['queued', 0, null, null], [$failedTask->status, $failedTask->attempts, $failedTask->error_code, $failedTask->finished_at]);
        $image->refresh();
        $this->assertSame(['queued', null, null], [$image->status, $image->error_code, $image->error_message]);
        $this->assertSame('processing', $batch->refresh()->status, 'retry returns the batch to processing (ADR-0005)');

        $this->runQueuedJobs();

        $this->assertSame('ready', $image->refresh()->status);
        $this->assertSame(['succeeded', 1], [$failedTask->refresh()->status, $failedTask->attempts]);
        $this->assertSame(self::ORDER, $this->taskTypes($image), 'same task row reused, earlier stages not repeated');
        $this->assertSame('completed', $batch->refresh()->status);
    }

    public function test_retry_is_only_allowed_for_failed_images(): void
    {
        $image = $this->processedImage();

        try {
            app(ProcessingPipeline::class)->retry($image->id);
            $this->fail('Expected IMAGE_NOT_RETRYABLE');
        } catch (ApiException $e) {
            $this->assertSame(['IMAGE_NOT_RETRYABLE', 409, ['status' => 'ready']], [$e->errorCode, $e->status, $e->details]);
        }

        $this->assertSame('ready', $image->refresh()->status);
    }

    public function test_repeated_retry_does_not_duplicate_work(): void
    {
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::unavailable('down')));
        $image = $this->createUploadedImage();
        $this->runQueuedJobs();

        app(ProcessingPipeline::class)->retry($image->id);
        $messages = count($this->pushedJobs());

        $this->expectExceptionObject(new ApiException('IMAGE_NOT_RETRYABLE', 409, 'Only failed images can be retried'));

        try {
            app(ProcessingPipeline::class)->retry($image->id);
        } finally {
            $this->assertCount($messages, $this->pushedJobs());
        }
    }

    public function test_batch_counters_stay_consistent_while_images_finish(): void
    {
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::unavailable('down')));
        $batch = $this->makeBatch('uploading');
        $failing = $this->createUploadedImage($batch);
        $this->runQueuedJobs(); // AI failures are consumed by the first image
        $ok = $this->createUploadedImage($batch);

        $this->assertSame('uploading', $batch->refresh()->status, 'only complete closes a batch');
        app(BatchStatusUpdater::class)->complete($batch->id);
        $this->assertSame('processing', $batch->refresh()->status);

        while (true) {
            $progress = BatchPresenter::progress($batch);
            $this->assertTrue($progress['failed'] >= 0 && $progress['failed'] <= $progress['processed'] && $progress['processed'] <= $progress['total']);
            if ($this->runQueuedJobs(1) === 0) {
                break;
            }
        }

        $batch->refresh();
        $this->assertSame(['completed_with_errors', 2, 2, 1], [$batch->status, $batch->total_images, $batch->processed_images, $batch->failed_images]);
        $this->assertSame(['failed', 'ready'], [$failing->refresh()->status, $ok->refresh()->status]);
    }

    public function test_deleting_the_last_pending_image_finishes_the_batch(): void
    {
        $this->loginAsAdminForProcessing();
        $batch = $this->makeBatch('processing');
        $pending = $this->createUploadedImage($batch);
        $done = $this->createUploadedImage($batch);

        // Only $done gets a worker; $pending's messages stay in the queue.
        for ($i = 0; isset($this->pushedJobs()[$i]); $i++) {
            $job = $this->pushedJobs()[$i];
            if (ProcessingTask::find($job->taskId)->image_id === $done->id) {
                $this->runJob($job);
            }
        }
        $this->assertSame('processing', $batch->refresh()->status);

        $this->deleteJson("/api/admin/images/{$pending->id}", [], ['X-CSRF-TOKEN' => 'test-csrf-token'])->assertNoContent();

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame('ready', $done->refresh()->status);
    }

    private function loginAsAdminForProcessing(): void
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin-processing@example.test',
            'password' => 'secret-password', 'role' => 'admin',
        ]);
        $this->actingAs($user, 'web');
        $this->withSession(['_token' => 'test-csrf-token']);
    }

    private function reprocess(Image $image): void
    {
        app(ProcessingPipeline::class)->reprocess($image->id);
    }

    private function makeBatch(string $status): Batch
    {
        $user = User::first() ?? User::create([
            'name' => 'Admin', 'email' => 'pipeline@example.test',
            'password' => 'secret-password', 'role' => 'admin',
        ]);

        return Batch::create(['status' => $status, 'created_by' => $user->id]);
    }

    private function task(Image $image, string $type): ProcessingTask
    {
        return ProcessingTask::where('image_id', $image->id)->where('type', $type)->orderByDesc('id')->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function taskTypes(Image $image): array
    {
        return ProcessingTask::where('image_id', $image->id)->orderBy('id')->pluck('type')->all();
    }

    /**
     * @return list<int>
     */
    private function retryDelays(): array
    {
        return array_values(array_filter(array_map(fn (RunProcessingTask $job) => $job->delay, $this->pushedJobs())));
    }
}
