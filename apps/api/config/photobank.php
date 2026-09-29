<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits (ТЗ §63, ADR-0005)
    |--------------------------------------------------------------------------
    |
    | Content-based MIME allowlist and a configurable max size. The default of
    | 50 MiB matches ADR-0005; extensions are derived from the detected MIME
    | type, never trusted from the client (§63).
    |
    */

    'upload' => [
        // Bytes, not KB — ADR-0005 names this env var explicitly so infra can
        // align it with nginx `client_max_body_size` / PHP `upload_max_filesize`.
        'max_bytes' => (int) env('PHOTO_MAX_UPLOAD_BYTES', 52428800),

        'allowed_mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media serving (ТЗ §57, ADR-0011)
    |--------------------------------------------------------------------------
    |
    | Cache-Control max-age applied to thumbnail/preview/original responses.
    | X-Accel-Redirect (Docker/nginx) is an infra-owned concern; this backend
    | always streams the file itself, which ADR-0011 allows outside Docker.
    |
    */

    'media' => [
        'cache_max_age' => (int) env('PHOTO_MEDIA_CACHE_MAX_AGE', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background processing (ТЗ §25, §28, §47, §48; ADR-0005)
    |--------------------------------------------------------------------------
    |
    | Retry state lives in processing_tasks, not in the broker (§25): a
    | retryable failure re-queues the task with the next backoff delay until
    | attempts reach max_attempts. AI stages use ADR-0005's policy — first
    | attempt + 3 retries after 10/30/120 s. Local stages (file decoding,
    | variants, finalize) get fewer attempts: their failures are rarely
    | transient, and deterministic ones (undecodable file) fail immediately.
    |
    */

    'processing' => [
        'queues' => [
            'processing' => 'images.processing',
            'ai' => 'images.ai',
            'reprocess' => 'images.reprocess',
        ],

        'max_attempts' => [
            'metadata' => 3,
            'variants' => 3,
            'ai_analysis' => 4,
            // Stage 3 of TagResolver calls AI text embeddings (§36), so it is an AI job too.
            'tag_resolution' => 4,
            'finalize' => 3,
        ],

        // Delay before retry N (seconds), §47. The last value repeats if max_attempts grows.
        'backoff' => [10, 30, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image variants (ТЗ §11, §12)
    |--------------------------------------------------------------------------
    |
    | Stored under variants/<image-id>/<name>.webp. The preview doubles as the
    | reduced copy sent to AI (ADR-0005: within the AI's 20 MiB limit); the
    | original is never modified.
    |
    */

    'variants' => [
        'thumbnail' => ['max_width' => 400, 'max_height' => 400, 'quality' => 80],
        'preview' => ['max_width' => 1600, 'max_height' => 1600, 'quality' => 85],
    ],

    /*
    |--------------------------------------------------------------------------
    | TagResolver (ТЗ §36, §37)
    |--------------------------------------------------------------------------
    |
    | Stage 3 thresholds on cosine similarity (1 - cosine distance, ADR-0009)
    | between a concept's text embedding and tag_embeddings. §37 starts at
    | 0.82; measured on SigLIP2 text embeddings (2026-09-30) that value attaches
    | unrelated words (стадион~замок 0.845, ключ~замок 0.866), while forms of the
    | same concept score ≥ 0.927 (здания~здание 0.939, защитная каска~каска 0.963)
    | and different concepts ≤ 0.905. So auto-attach is kept for word forms only;
    | everything else goes to moderation as a candidate with its closest tag:
    |   >= auto_attach            → assign the canonical tag;
    |   >= candidate, < auto      → tag_candidate with closest_tag;
    |   <  candidate              → new tag_candidate.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Search relevance (ADR-0004, 2026-09-30 addendum)
    |--------------------------------------------------------------------------
    |
    | semantic/hybrid return images whose caption, OCR text or tag names contain
    | every query word first; the rest only when their similarity reaches
    | max(min_similarity, relative_similarity × the best similarity of the query).
    | SigLIP2 text→image similarity on the 21-photo test set (2026-09-30):
    | relevant 0.10–0.14, unrelated ≤ 0.07.
    |
    */

    'search' => [
        'min_similarity' => (float) env('SEARCH_MIN_SIMILARITY', 0.05),
        'relative_similarity' => (float) env('SEARCH_RELATIVE_SIMILARITY', 0.5),
        // With a word match the matched images are the answer; only near-best neighbours join.
        'relative_similarity_with_word_match' => (float) env('SEARCH_RELATIVE_SIMILARITY_WITH_WORD_MATCH', 0.8),
    ],

    'tagging' => [
        'auto_attach_threshold' => (float) env('TAG_AUTO_ATTACH_THRESHOLD', 0.92),
        'candidate_threshold' => (float) env('TAG_CANDIDATE_THRESHOLD', 0.68),
        'semantic_top_k' => 5,
    ],

];
