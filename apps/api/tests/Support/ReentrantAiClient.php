<?php

namespace Tests\Support;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use Closure;

/**
 * Simulates concurrency deterministically: the first analyzeImage() call
 * runs $whileWaiting (e.g. another worker processing the same task) before
 * answering, as if that happened while this worker waited for AI.
 */
final class ReentrantAiClient implements AiClientInterface
{
    private bool $fired = false;

    public function __construct(
        private readonly AiClientInterface $inner,
        private readonly Closure $whileWaiting,
    ) {}

    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult
    {
        if (! $this->fired) {
            $this->fired = true;
            ($this->whileWaiting)();
        }

        return $this->inner->analyzeImage($imageContents, $requestId);
    }

    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult
    {
        return $this->inner->createTextEmbeddings($texts, $requestId);
    }

    public function health(): AiHealthResult
    {
        return $this->inner->health();
    }
}
