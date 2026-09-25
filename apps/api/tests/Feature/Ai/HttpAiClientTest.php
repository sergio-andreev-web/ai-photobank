<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\HttpAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HttpAiClient against contracts/ai-api.openapi.yaml, with HTTP faked:
 * errors are classified by the ai-error.json catalog, 2xx bodies are
 * schema-validated before any DTO is built (§63, §71, ADR-0010).
 */
class HttpAiClientTest extends TestCase
{
    private function client(): HttpAiClient
    {
        return new HttpAiClient('http://ai.test', 'secret-key', 5);
    }

    private function validAnalysis(string $requestId): array
    {
        return (new FakeAiClient)->analyzeImage('bytes', $requestId)->toArray();
    }

    public function test_valid_analysis_is_parsed_and_request_id_propagated(): void
    {
        Http::fake(['ai.test/v1/images/analyze' => Http::response($this->validAnalysis('req-1'))]);

        $result = $this->client()->analyzeImage('image-bytes', 'req-1');

        $this->assertSame('req-1', $result->requestId);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Request-ID', 'req-1') && $r->hasHeader('Authorization', 'Bearer secret-key'));
    }

    public function test_2xx_with_invalid_body_raises_invalid_response(): void
    {
        $body = $this->validAnalysis('req-1');
        $body['embedding'] = [0.1, 0.2];
        Http::fake(['ai.test/*' => Http::response($body)]);

        $this->expectException(InvalidAiResponseException::class);

        $this->client()->analyzeImage('image-bytes', 'req-1');
    }

    public function test_2xx_with_non_json_body_raises_invalid_response(): void
    {
        Http::fake(['ai.test/*' => Http::response('<html>proxy error</html>', 200)]);

        $this->expectException(InvalidAiResponseException::class);

        $this->client()->analyzeImage('image-bytes', 'req-1');
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: bool}>
     */
    public static function errorResponses(): iterable
    {
        yield 'service busy' => [429, 'SERVICE_BUSY', true];
        yield 'model not ready' => [503, 'MODEL_NOT_READY', true];
        yield 'model output invalid' => [502, 'MODEL_OUTPUT_INVALID', true];
        yield 'decode failed' => [422, 'IMAGE_DECODE_FAILED', false];
        yield 'unauthorized' => [401, 'UNAUTHORIZED', false];
        yield 'unknown 5xx code' => [504, 'GATEWAY_SOMETHING', true];
        yield 'unknown 4xx code' => [409, 'SOMETHING_NEW', false];
    }

    #[DataProvider('errorResponses')]
    public function test_error_responses_are_classified_by_contract(int $status, string $code, bool $retryable): void
    {
        Http::fake(['ai.test/*' => Http::response([
            'error' => ['code' => $code, 'message' => 'x', 'details' => [], 'request_id' => 'req-1'],
        ], $status)]);

        try {
            $this->client()->analyzeImage('image-bytes', 'req-1');
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame([$code, $status, $retryable], [$e->errorCode, $e->httpStatus, $e->retryable]);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: int|null}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'seconds' => [['Retry-After' => '30'], 30];
        yield 'absent' => [[], null];
        yield 'http date is not guessed' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'garbage' => [['Retry-After' => '-5'], null];
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_retry_after_seconds_are_carried_on_the_exception(array $headers, ?int $expected): void
    {
        Http::fake(['ai.test/*' => Http::response([
            'error' => ['code' => 'MODEL_NOT_READY', 'message' => 'x', 'details' => [], 'request_id' => 'req-1'],
        ], 503, $headers)]);

        try {
            $this->client()->createTextEmbeddings(['каска'], 'req-1');
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame($expected, $e->retryAfter);
        }
    }

    public function test_timeout_or_connection_failure_is_retryable_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->client()->analyzeImage('image-bytes', 'req-1');
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame(['AI_SERVICE_UNAVAILABLE', true], [$e->errorCode, $e->retryable]);
        }
    }

    public function test_text_embeddings_response_is_validated_against_request_size(): void
    {
        $body = (new FakeAiClient)->createTextEmbeddings(['one'], 'req-1')->toArray();
        Http::fake(['ai.test/v1/embeddings/text' => Http::response($body)]);

        $this->expectException(InvalidAiResponseException::class);

        $this->client()->createTextEmbeddings(['one', 'two'], 'req-1');
    }
}
