<?php

namespace App\Contracts\Ai;

use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\FaceDetectionResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\HttpAiClient;

/**
 * Laravel's only line of communication with the AI service (ТЗ §7).
 *
 * Implementations talk to contracts/ai-api.openapi.yaml over HTTP (see
 * {@see HttpAiClient}) or return deterministic fixtures for
 * tests without GPU (see {@see FakeAiClient}). Callers must
 * not know which driver is active — it is selected by config('ai.driver').
 *
 * Retry policy (§47) and idempotent processing-task orchestration are the
 * caller's responsibility, not this interface's.
 */
interface AiClientInterface
{
    /**
     * @param  string  $imageContents  Binary image bytes (preview or AI copy, §12).
     * @param  string  $requestId  Propagated as X-Request-ID and multipart field
     *                             `request_id` (§31, ADR-0002/ADR-0010).
     *
     * @throws AiException On any non-2xx response.
     * @throws InvalidAiResponseException On a 2xx body that
     *                                    fails image-analysis.json.
     */
    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult;

    /**
     * @param  list<string>  $texts  1..64 non-empty strings (§32).
     * @return TextEmbeddingsResult embeddings[i] corresponds to $texts[i].
     *
     * @throws AiException On any non-2xx response.
     * @throws InvalidAiResponseException On a 2xx body that
     *                                    fails text-embeddings.json.
     */
    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult;

    /**
     * Faces and their vectors (POST /v1/faces/detect, ADR-0015). The AI knows no people.
     *
     * @throws AiException On any non-2xx response (503 MODEL_NOT_READY when faces are off).
     * @throws InvalidAiResponseException On a 2xx body that fails face-detection.json.
     */
    public function detectFaces(string $imageContents, string $requestId): FaceDetectionResult;

    /**
     * Liveness probe (GET /health, §30). Does not guarantee models are loaded.
     *
     * @throws AiException On any non-2xx response.
     */
    public function health(): AiHealthResult;
}
