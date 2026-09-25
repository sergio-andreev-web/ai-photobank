<?php

namespace App\Jobs;

use App\Services\Processing\ProcessingPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * "Run processing task N" (§25, §48). The message carries only the task id;
 * all state and business retries (§47) live in processing_tasks, handled by
 * ProcessingPipeline, so handle() normally never throws.
 *
 * $tries covers broker-level redelivery only (worker crash, DB outage); it
 * does not add business attempts. When those run out, failed() marks the
 * task/image failed so nothing is left stuck in `processing`.
 */
class RunProcessingTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Seconds; must outlive one AI request (AI_REQUEST_TIMEOUT) or the worker kills the job mid-call. */
    public int $timeout;

    public function __construct(public readonly int $taskId)
    {
        $this->timeout = (int) config('ai.timeout') + 60;
    }

    public function handle(ProcessingPipeline $pipeline): void
    {
        $pipeline->runTask($this->taskId, redelivered: $this->attempts() > 1);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessingPipeline::class)->abandon($this->taskId, $exception);
    }
}
