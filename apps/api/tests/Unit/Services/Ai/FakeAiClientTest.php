<?php

namespace Tests\Unit\Services\Ai;

use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use App\Services\Ai\FakeAiClient;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test (no Laravel bootstrap needed): FakeAiClient must satisfy the
 * same shape contract as HttpAiClient (schemas/image-analysis.json,
 * schemas/text-embeddings.json) without a GPU or network call.
 */
class FakeAiClientTest extends TestCase
{
    public function test_analyze_image_returns_a_1152_dimensional_embedding(): void
    {
        $client = new FakeAiClient;

        $result = $client->analyzeImage('fake-bytes', 'request-1');

        $this->assertSame('request-1', $result->requestId);
        $this->assertCount(ImageAnalysisResult::EMBEDDING_DIMENSIONS, $result->embedding);
        $this->assertNotEmpty($result->concepts);
        $this->assertSame('fake-analysis-v1', $result->promptVersion);
    }

    public function test_analyze_image_is_deterministic_for_the_same_bytes(): void
    {
        $client = new FakeAiClient;

        $first = $client->analyzeImage('same-bytes', 'request-1');
        $second = $client->analyzeImage('same-bytes', 'request-2');

        $this->assertSame($first->embedding, $second->embedding);
    }

    public function test_analyze_image_differs_for_different_bytes(): void
    {
        $client = new FakeAiClient;

        $first = $client->analyzeImage('bytes-a', 'request-1');
        $second = $client->analyzeImage('bytes-b', 'request-1');

        $this->assertNotSame($first->embedding, $second->embedding);
    }

    public function test_create_text_embeddings_preserves_order_and_dimensions(): void
    {
        $client = new FakeAiClient;

        $result = $client->createTextEmbeddings(['red car', 'blue car'], 'request-1');

        $this->assertInstanceOf(TextEmbeddingsResult::class, $result);
        $this->assertCount(2, $result->embeddings);
        $this->assertCount(TextEmbeddingsResult::EMBEDDING_DIMENSIONS, $result->embeddings[0]);
        $this->assertNotSame($result->embeddings[0], $result->embeddings[1]);

        // Same text -> same embedding, independent of position or other inputs.
        $again = $client->createTextEmbeddings(['red car'], 'request-2');
        $this->assertSame($result->embeddings[0], $again->embeddings[0]);
    }

    public function test_health_reports_ok(): void
    {
        $client = new FakeAiClient;

        $health = $client->health();

        $this->assertSame('ok', $health->status);
    }
}
