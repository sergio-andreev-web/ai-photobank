<?php

namespace App\Jobs;

use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Services\Tagging\TagEmbeddingRegenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * "Re-embed tag N" (ТЗ §21, ADR-0007), on the AI queue (§48) since it calls
 * the AI service. The message carries only the tag id; the job re-reads the
 * current name/aliases, so duplicates and reordering are harmless.
 *
 * Transient AI failures (§46, §47) are retried with the §47 delays; a
 * non-retryable AI error or contract violation fails at once (ADR-0010).
 */
class RegenerateTagEmbedding implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout;

    public function __construct(public readonly int $tagId)
    {
        $this->timeout = (int) config('ai.timeout') + 60;
        $this->onQueue(config('photobank.processing.queues.ai'));
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('photobank.processing.backoff');
    }

    public function handle(TagEmbeddingRegenerator $regenerator): void
    {
        try {
            $regenerator->regenerate($this->tagId);
        } catch (AiException $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (InvalidAiResponseException $e) {
            $this->fail($e);
        }
    }
}
