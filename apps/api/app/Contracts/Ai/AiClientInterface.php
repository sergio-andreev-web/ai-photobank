<?php

namespace App\Contracts\Ai;

use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\TextEmbeddingsResult;

/**
 * Laravel's only line of communication with the AI service (ТЗ §7).
 *
 * Implementations talk to contracts/ai-api.openapi.yaml over HTTP (see
 * {@see \App\Services\Ai\HttpAiClient}) or return deterministic fixtures for
 * tests without GPU (see {@see \App\Services\Ai\FakeAiClient}). Callers must
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
     * @throws \App\Contracts\Ai\Exceptions\AiException On any non-2xx response.
     */
    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult;

    /**
     * @param  list<string>  $texts  1..64 non-empty strings (§32).
     * @return TextEmbeddingsResult embeddings[i] corresponds to $texts[i].
     *
     * @throws \App\Contracts\Ai\Exceptions\AiException On any non-2xx response.
     */
    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult;

    /**
     * Liveness probe (GET /health, §30). Does not guarantee models are loaded.
     *
     * @throws \App\Contracts\Ai\Exceptions\AiException On any non-2xx response.
     */
    public function health(): AiHealthResult;
}
