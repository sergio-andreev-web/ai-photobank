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

];
