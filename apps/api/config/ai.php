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

    /*
    |--------------------------------------------------------------------------
    | Active embedding version (ADR-0009)
    |--------------------------------------------------------------------------
    |
    | Only image_embeddings rows whose model_key/model_version equal these
    | values are stored with active = true, so a vector search never mixes
    | model versions (§77). model_key is the AI's `models.embedding` without the
    | organization prefix; model_version is `models.embedding_revision`.
    | While the version is unset no embedding is activated: the analysis is
    | still stored, but the image stays out of semantic search until the
    | version is configured and the image is reprocessed.
    |
    */

    'embedding' => [
        'active_model_key' => env('EMBEDDING_ACTIVE_MODEL_KEY', 'siglip2-so400m-patch14-384'),
        'active_model_version' => env('EMBEDDING_ACTIVE_MODEL_VERSION'),
    ],

];
