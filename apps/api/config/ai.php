<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Client Driver
    |--------------------------------------------------------------------------
    |
    | Selects the AiClientInterface implementation bound by AiServiceProvider.
    | "http" talks to the AI service over contracts/ai-api.openapi.yaml.
    | "fake" is deterministic and used in tests / local dev without GPU
    | (ТЗ §7, AGENT A Definition of Done: backend работает с FakeAiClient).
    |
    */

    'driver' => env('AI_DRIVER', 'http'),

    /*
    |--------------------------------------------------------------------------
    | HTTP driver settings (contracts/ai-api.openapi.yaml)
    |--------------------------------------------------------------------------
    */

    'base_url' => env('AI_BASE_URL', 'http://host.docker.internal:8001'),

    'api_key' => env('AI_SERVICE_API_KEY'),

    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Embedding dimensions
    |--------------------------------------------------------------------------
    |
    | Fixed at 1152 for MVP (schemas/image-analysis.json, schemas/text-embeddings.json).
    | A dimension change is a breaking contract change (§77).
    |
    */

    'embedding_dimensions' => 1152,

];
