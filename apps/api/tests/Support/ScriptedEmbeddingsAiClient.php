<?php

namespace Tests\Support;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use App\Services\Ai\FakeAiClient;
use Throwable;

/**
 * FakeAiClient for analyze/health, with text embeddings a test can place on
 * purpose: vector(axis, similarity) has cosine `similarity` with
 * vector(axis, 1.0), so stage 3 thresholds (§37) are exercised exactly.
 * Unscripted texts get a vector on a private axis — similarity ≈ 0 to
 * everything scripted.
 */
final class ScriptedEmbeddingsAiClient implements AiClientInterface
{
    public const int DIMENSIONS = 1152;

    public FakeAiClient $fake;

    /** @var array<string, list<float>> */
    public array $vectors = [];

    public string $model = FakeAiClient::EMBEDDING_MODEL;

    public string $revision = FakeAiClient::EMBEDDING_REVISION;

    /** @var list<list<string>> Texts of every createTextEmbeddings() call. */
    public array $textCalls = [];

    /** @var list<string> Request IDs of every createTextEmbeddings() call, failed ones included. */
    public array $requestIds = [];

    /** @var list<Throwable> */
    private array $failures = [];

    private int $nextPrivateAxis = 600;

    public function __construct()
    {
        $this->fake = new FakeAiClient;
    }

    /**
     * Unit vector at cosine $similarity from the pure `axis` direction.
     *
     * @return list<float>
     */
    public static function vector(int $axis, float $similarity = 1.0, int $offAxis = 1100): array
    {
        $v = array_fill(0, self::DIMENSIONS, 0.0);
        $v[$axis] = $similarity;
        $v[$offAxis] = sqrt(max(0.0, 1 - $similarity * $similarity));

        return $v;
    }

    /**
     * @param  list<float>  $vector
     */
    public function embedAs(string $text, array $vector): self
    {
        $this->vectors[$text] = $vector;

        return $this;
    }

    public function failTextEmbeddingsWith(Throwable ...$errors): self
    {
        array_push($this->failures, ...$errors);

        return $this;
    }

    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult
    {
        return $this->fake->analyzeImage($imageContents, $requestId);
    }

    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult
    {
        $this->textCalls[] = $texts;
        $this->requestIds[] = $requestId;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return new TextEmbeddingsResult(
            model: $this->model,
            revision: $this->revision,
            embeddings: array_map(function (string $text): array {
                return $this->vectors[$text] ??= self::vector($this->nextPrivateAxis++, 1.0);
            }, $texts),
        );
    }

    public function health(): AiHealthResult
    {
        return $this->fake->health();
    }
}
