<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\FaceDetectionResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * AiClientInterface driver for contracts/ai-api.openapi.yaml. One request in,
 * one response out — no retries here. Retry policy (§47) belongs to the
 * processing-task orchestration that calls this client, not to the client.
 */
final class HttpAiClient implements AiClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds,
        private readonly AiResponseValidator $validator = new AiResponseValidator,
    ) {}

    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult
    {
        $response = $this->send($requestId, fn (PendingRequest $client): Response => $client
            ->attach('image', $imageContents, 'image')
            ->post($this->endpoint('/v1/images/analyze'), [
                'request_id' => $requestId,
            ]));

        $data = $this->decodeOrFail($response, $requestId);
        $this->validator->assertValidImageAnalysis($data, $requestId);

        return ImageAnalysisResult::fromArray($data);
    }

    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult
    {
        $response = $this->send($requestId, fn (PendingRequest $client): Response => $client
            ->asJson()
            ->post($this->endpoint('/v1/embeddings/text'), [
                'texts' => array_values($texts),
            ]));

        $data = $this->decodeOrFail($response, $requestId);
        $this->validator->assertValidTextEmbeddings($data, count($texts), $requestId);

        return TextEmbeddingsResult::fromArray($data);
    }

    public function detectFaces(string $imageContents, string $requestId): FaceDetectionResult
    {
        $response = $this->send($requestId, fn (PendingRequest $client): Response => $client
            ->attach('image', $imageContents, 'image')
            ->post($this->endpoint('/v1/faces/detect'), [
                'request_id' => $requestId,
            ]));

        $data = $this->decodeOrFail($response, $requestId);
        $this->validator->assertValidFaceDetection($data, $requestId);

        return FaceDetectionResult::fromArray($data);
    }

    public function health(): AiHealthResult
    {
        $requestId = (string) str()->uuid();
        $response = $this->send($requestId, fn (PendingRequest $client): Response => $client
            ->get($this->endpoint('/health')));

        return AiHealthResult::fromArray($this->decodeOrFail($response, $requestId));
    }

    /**
     * @param  \Closure(PendingRequest): Response  $request
     *
     * @throws AiException When the AI service is unreachable (§46).
     */
    private function send(string $requestId, \Closure $request): Response
    {
        try {
            return $request($this->client($requestId));
        } catch (ConnectionException $exception) {
            throw AiException::unavailable(
                message: 'Could not reach the AI service: '.$exception->getMessage(),
                requestId: $requestId,
            );
        }
    }

    private function client(string $requestId): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->withHeaders(['X-Request-ID' => $requestId]);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $client = $client->withToken($this->apiKey);
        }

        return $client;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @return mixed Decoded 2xx body; shape is checked by the caller's validator.
     *
     * @throws AiException On a non-2xx response.
     * @throws InvalidAiResponseException On a 2xx body that is not JSON.
     */
    private function decodeOrFail(Response $response, string $requestId): mixed
    {
        if ($response->successful()) {
            $data = json_decode($response->body(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidAiResponseException(['/: body is not valid JSON'], $requestId);
            }

            return $data;
        }

        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        throw AiException::fromErrorCode(
            code: is_array($error) ? ($error['code'] ?? 'INTERNAL_ERROR') : 'INTERNAL_ERROR',
            message: is_array($error) ? ($error['message'] ?? 'AI service returned an error') : 'AI service returned an unparseable error body',
            httpStatus: $response->status(),
            details: is_array($error) ? ($error['details'] ?? []) : [],
            requestId: is_array($error) ? ($error['request_id'] ?? $requestId) : $requestId,
            retryAfter: self::retryAfterSeconds($response),
        );
    }

    /**
     * Retry-After is an integer number of seconds in the AI contract; an
     * HTTP-date or garbage value is ignored rather than guessed.
     */
    private static function retryAfterSeconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        return ctype_digit($value) ? (int) $value : null;
    }
}
