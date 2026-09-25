<?php

namespace App\Services\Processing;

use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Exceptions\ApiException;
use App\Jobs\RunProcessingTask;
use App\Models\Image;
use App\Models\ProcessingTask;
use App\Services\Processing\Stages\AiAnalysisStage;
use App\Services\Processing\Stages\FinalizeStage;
use App\Services\Processing\Stages\MetadataStage;
use App\Services\Processing\Stages\TagResolutionStage;
use App\Services\Processing\Stages\VariantsStage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates processing_tasks (§25, §28) and the image/task state machines
 * in contracts/state-machines.md. processing_tasks is the source of truth;
 * queue messages only say "look at task N" (§25: не полагаться на RabbitMQ).
 *
 * Concurrency and idempotency:
 * - Every state change locks the image row, then the task row (one order
 *   everywhere, so retry/reprocess/workers cannot deadlock each other).
 * - A worker claims a task by moving it queued → processing and bumping
 *   `attempts`; the (status, attempts) pair is its claim token. Results are
 *   persisted only if the token is unchanged, in the same transaction that
 *   marks the task succeeded and creates the next one — so a duplicate or
 *   stale message is a no-op and image_analyses gets one row per run.
 * - Messages are dispatched after commit, so a worker never sees a task
 *   that is not visible yet.
 * - Soft-deleted images stop at the next claim/persist (§78).
 */
final class ProcessingPipeline
{
    /** @var array<string, class-string<ProcessingStage>> */
    private const array STAGES = [
        ProcessingState::TASK_METADATA => MetadataStage::class,
        ProcessingState::TASK_VARIANTS => VariantsStage::class,
        ProcessingState::TASK_AI_ANALYSIS => AiAnalysisStage::class,
        ProcessingState::TASK_TAG_RESOLUTION => TagResolutionStage::class,
        ProcessingState::TASK_FINALIZE => FinalizeStage::class,
    ];

    /** Statuses in which reprocess is a no-op 202 (ADR-0003): a run is already pending. */
    private const array IN_FLIGHT_STATUSES = [
        ProcessingState::IMAGE_UPLOADED,
        ProcessingState::IMAGE_QUEUED,
        ProcessingState::IMAGE_PROCESSING,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly BatchStatusUpdater $batches,
    ) {}

    /**
     * Right after upload: queue `metadata`. Call inside the transaction that
     * creates the image so an image never exists without its first task.
     */
    public function start(Image $image): ProcessingTask
    {
        return DB::transaction(function () use ($image): ProcessingTask {
            $task = $this->createTask($image, ProcessingState::TASK_METADATA);
            $this->dispatch($task);

            return $task;
        });
    }

    /**
     * POST /images/{id}/retry (ADR-0003): only from `failed`; resumes at the
     * failed task with attempts reset to 0 (state-machines.md, Processing task).
     *
     * @throws ApiException IMAGE_NOT_FOUND, IMAGE_NOT_RETRYABLE
     */
    public function retry(int $imageId): void
    {
        DB::transaction(function () use ($imageId): void {
            $image = $this->lockImage($imageId);

            if ($image->status !== ProcessingState::IMAGE_FAILED) {
                throw new ApiException('IMAGE_NOT_RETRYABLE', 409, 'Only failed images can be retried', [
                    'status' => $image->status,
                ]);
            }

            $task = ProcessingTask::where('image_id', $image->id)
                ->where('status', ProcessingState::TASK_FAILED)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($task === null) {
                // A failed image without a failed task (e.g. abandoned by the broker before
                // its task row was updated) restarts from its first unfinished stage.
                $task = $this->createTask($image, $this->firstUnfinishedStage($image));
            } else {
                $task->fill([
                    'status' => ProcessingState::TASK_QUEUED,
                    'attempts' => 0,
                    'error_code' => null,
                    'error_message' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ])->save();
            }

            $this->requeueImage($image);
            $this->dispatch($task);

            Log::info('processing.image.retry', ['image_id' => $image->id, 'task_id' => $task->id, 'type' => $task->type]);
        }, 3);
    }

    /**
     * POST /images/{id}/reprocess (§76, ADR-0003): from ready/failed a new
     * AI run (ai_analysis → tag_resolution → finalize) with new task rows and
     * a new image_analyses row; earlier tasks/analyses stay as history. An
     * image that never got its variants restarts from the first missing
     * stage, since AI needs the preview. From uploaded/queued/processing it
     * is a no-op: a run is already pending.
     *
     * @throws ApiException IMAGE_NOT_FOUND
     */
    public function reprocess(int $imageId): void
    {
        DB::transaction(function () use ($imageId): void {
            $image = $this->lockImage($imageId);

            if (in_array($image->status, self::IN_FLIGHT_STATUSES, true)) {
                return;
            }

            $task = $this->createTask($image, $this->firstUnfinishedStage($image));
            $this->requeueImage($image);
            $this->dispatch($task, config('photobank.processing.queues.reprocess'));

            Log::info('processing.image.reprocess', ['image_id' => $image->id, 'task_id' => $task->id, 'type' => $task->type]);
        }, 3);
    }

    /**
     * Worker entry point. $redelivered = the broker re-delivered this message
     * after a worker died mid-task, so a `processing` task may be re-claimed.
     */
    public function runTask(int $taskId, bool $redelivered = false): void
    {
        $claim = DB::transaction(fn (): ?array => $this->claim($taskId, $redelivered), 3);

        if ($claim === null) {
            Log::info('processing.task.skipped', ['task_id' => $taskId]);

            return;
        }

        [$task, $image] = $claim;
        $context = ['task_id' => $task->id, 'image_id' => $image->id, 'type' => $task->type, 'attempt' => $task->attempts];
        Log::info('processing.task.started', $context);

        $stage = $this->stage($task->type);

        try {
            $outcome = $stage->execute($image);

            $persisted = DB::transaction(function () use ($task, $stage, $outcome): bool {
                $owned = $this->reacquire($task);
                if ($owned === null) {
                    return false;
                }

                [$task, $image] = $owned;
                $stage->persist($image, $outcome);
                $this->succeed($task, $image);

                return true;
            }, 3);
        } catch (Throwable $e) {
            $this->fail($task, $e);

            return;
        }

        Log::info($persisted ? 'processing.task.succeeded' : 'processing.task.lost_claim', $context);
    }

    /**
     * The broker gave up on a message (job exhausted its broker-level tries,
     * e.g. the worker kept crashing). Never leave the task/image stuck.
     */
    public function abandon(int $taskId, ?Throwable $e): void
    {
        DB::transaction(function () use ($taskId, $e): void {
            $task = ProcessingTask::find($taskId);
            $image = $task ? Image::lockForUpdate()->find($task->image_id) : null;
            $task = $task ? ProcessingTask::lockForUpdate()->find($taskId) : null;

            if ($task === null || $image === null
                || ! in_array($task->status, [ProcessingState::TASK_QUEUED, ProcessingState::TASK_PROCESSING], true)) {
                return;
            }

            if ($e !== null) {
                report($e);
            }

            $this->markFailed($task, $image, ProcessingState::ERROR_INTERNAL, 'Processing worker gave up on the task');
        });
    }

    /**
     * @return array{0: ProcessingTask, 1: Image}|null
     */
    private function claim(int $taskId, bool $redelivered): ?array
    {
        $imageId = ProcessingTask::whereKey($taskId)->value('image_id');
        $image = $imageId ? Image::lockForUpdate()->find($imageId) : null;
        $task = $image ? ProcessingTask::lockForUpdate()->find($taskId) : null;

        if ($task === null) {
            return null;
        }

        $claimable = $task->status === ProcessingState::TASK_QUEUED
            || ($redelivered && $task->status === ProcessingState::TASK_PROCESSING);

        if (! $claimable || ! $this->isLatestOfType($task)) {
            return null;
        }

        $task->fill([
            'status' => ProcessingState::TASK_PROCESSING,
            'attempts' => $task->attempts + 1,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        if (in_array($task->type, ProcessingState::AI_PHASE_TASKS, true)
            && $image->status !== ProcessingState::IMAGE_PROCESSING) {
            $image->status = ProcessingState::IMAGE_PROCESSING;
            $image->save();
        }

        return [$task, $image];
    }

    /**
     * Re-locks image + task and returns them only if this worker's claim still
     * holds. A soft-deleted image counts as a lost claim: processing stops (§78).
     *
     * @return array{0: ProcessingTask, 1: Image}|null
     */
    private function reacquire(ProcessingTask $claimed): ?array
    {
        $image = Image::lockForUpdate()->find($claimed->image_id);
        $task = $image ? ProcessingTask::lockForUpdate()->find($claimed->id) : null;

        if ($task === null
            || $task->status !== ProcessingState::TASK_PROCESSING
            || $task->attempts !== $claimed->attempts) {
            return null;
        }

        return [$task, $image];
    }

    private function succeed(ProcessingTask $task, Image $image): void
    {
        $task->fill([
            'status' => ProcessingState::TASK_SUCCEEDED,
            'error_code' => null,
            'error_message' => null,
            'finished_at' => now(),
        ])->save();

        if ($task->type === ProcessingState::TASK_VARIANTS && $image->status === ProcessingState::IMAGE_UPLOADED) {
            // metadata + variants done, AI analysis waits for a worker (state-machines.md).
            $image->status = ProcessingState::IMAGE_QUEUED;
            $image->save();
        }

        $next = ProcessingState::nextTask($task->type);

        if ($next !== null) {
            $this->dispatch($this->createTask($image, $next));
        } else {
            $this->batches->refresh($image->batch_id);
        }
    }

    private function fail(ProcessingTask $claimed, Throwable $e): void
    {
        [$code, $message, $retryable] = $this->classify($claimed, $e);

        $context = [
            'task_id' => $claimed->id,
            'image_id' => $claimed->image_id,
            'type' => $claimed->type,
            'attempt' => $claimed->attempts,
            'error_code' => $code,
            'retryable' => $retryable,
            'request_id' => $e instanceof AiException || $e instanceof InvalidAiResponseException ? $e->requestId : null,
            'exception' => $e->getMessage(),
        ];

        DB::transaction(function () use ($claimed, $code, $message, $retryable, $context): void {
            $owned = $this->reacquire($claimed);
            if ($owned === null) {
                Log::info('processing.task.lost_claim', $context);

                return;
            }

            [$task, $image] = $owned;

            if ($retryable && $task->attempts < $task->max_attempts) {
                $task->fill([
                    'status' => ProcessingState::TASK_QUEUED,
                    'error_code' => $code,
                    'error_message' => $message,
                ])->save();

                $delay = $this->backoff($task->attempts);
                $this->dispatch($task, delaySeconds: $delay);

                Log::warning('processing.task.retry_scheduled', $context + ['delay_s' => $delay]);

                return;
            }

            $this->markFailed($task, $image, $code, $message);
            Log::error('processing.task.failed', $context);
        }, 3);
    }

    private function markFailed(ProcessingTask $task, Image $image, string $code, string $message): void
    {
        $task->fill([
            'status' => ProcessingState::TASK_FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();

        $image->fill([
            'status' => ProcessingState::IMAGE_FAILED,
            'error_code' => $code,
            'error_message' => $message,
        ])->save();

        $this->batches->refresh($image->batch_id);
    }

    /**
     * @return array{0: string, 1: string, 2: bool} error code, admin-facing message, retryable
     */
    private function classify(ProcessingTask $task, Throwable $e): array
    {
        return match (true) {
            $e instanceof StageFailure => [$e->errorCode, $e->getMessage(), $e->retryable],

            // ADR-0010: a 2xx that breaks the schema is a contract violation — no retries.
            $e instanceof InvalidAiResponseException => [
                ProcessingState::ERROR_AI_RESPONSE_INVALID,
                'AI service response does not match the contract',
                false,
            ],

            // ADR-0010 (contracts 1.2.0): the AI could not produce schema-valid output. Retried
            // like any 5xx, but recorded under its own code once attempts run out.
            $e instanceof AiException && $e->errorCode === 'MODEL_OUTPUT_INVALID' => [
                ProcessingState::ERROR_AI_OUTPUT_INVALID,
                sprintf('AI service error %s (HTTP %d)', $e->errorCode, $e->httpStatus),
                $e->retryable,
            ],

            // 5xx, 429, timeout, connection refused (§46, §47) vs. other 4xx.
            $e instanceof AiException => [
                $e->retryable ? ProcessingState::ERROR_AI_SERVICE_UNAVAILABLE : ProcessingState::ERROR_AI_REQUEST_REJECTED,
                sprintf('AI service error %s (HTTP %d)', $e->errorCode, $e->httpStatus),
                $e->retryable,
            ],

            // Unexpected (DB/storage hiccup, bug): retried within max_attempts, details only in logs
            // because error_message is shown to admins and must not leak paths (§11).
            default => $this->internalError($task, $e),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function internalError(ProcessingTask $task, Throwable $e): array
    {
        report($e);

        return [ProcessingState::ERROR_INTERNAL, "Unexpected error in {$task->type} stage", true];
    }

    private function backoff(int $attempts): int
    {
        $delays = config('photobank.processing.backoff');

        return (int) ($delays[$attempts - 1] ?? end($delays));
    }

    private function createTask(Image $image, string $type): ProcessingTask
    {
        return ProcessingTask::create([
            'image_id' => $image->id,
            'type' => $type,
            'status' => ProcessingState::TASK_QUEUED,
            'attempts' => 0,
            'max_attempts' => (int) config("photobank.processing.max_attempts.{$type}"),
        ]);
    }

    private function dispatch(ProcessingTask $task, ?string $queue = null, int $delaySeconds = 0): void
    {
        $queue ??= $task->type === ProcessingState::TASK_AI_ANALYSIS
            ? config('photobank.processing.queues.ai')
            : config('photobank.processing.queues.processing');

        $pending = RunProcessingTask::dispatch($task->id)->onQueue($queue)->afterCommit();

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    /**
     * failed/ready → queued: clears the previous error; the batch leaves its final status.
     */
    private function requeueImage(Image $image): void
    {
        $image->fill([
            'status' => ProcessingState::IMAGE_QUEUED,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->batches->refresh($image->batch_id);
    }

    /**
     * AI needs metadata and the preview; start at the first of those still missing.
     */
    private function firstUnfinishedStage(Image $image): string
    {
        return match (true) {
            $image->width === null || $image->height === null => ProcessingState::TASK_METADATA,
            $image->preview_key === null || $image->thumbnail_key === null => ProcessingState::TASK_VARIANTS,
            default => ProcessingState::TASK_AI_ANALYSIS,
        };
    }

    private function isLatestOfType(ProcessingTask $task): bool
    {
        return (int) ProcessingTask::where('image_id', $task->image_id)
            ->where('type', $task->type)
            ->max('id') === (int) $task->id;
    }

    /**
     * @throws ApiException
     */
    private function lockImage(int $imageId): Image
    {
        $image = Image::lockForUpdate()->find($imageId);

        if ($image === null) {
            throw new ApiException('IMAGE_NOT_FOUND', 404, 'Image not found');
        }

        return $image;
    }

    private function stage(string $type): ProcessingStage
    {
        return $this->container->make(self::STAGES[$type]);
    }
}
