<?php

namespace App\Contracts\Storage;

/**
 * Laravel Filesystem abstraction over the "photobank" disk (ТЗ §11).
 *
 * Only relative storage keys (e.g. "originals/2026/09/42/a1b2c3.jpg") ever
 * cross this boundary or get persisted to the database — never absolute
 * filesystem paths — so the backing disk can move to S3 later without
 * touching callers. Key naming (originals/variants layout, randomization,
 * MIME sniffing) belongs to the upload/variant-generation feature, not to
 * this base interface.
 */
interface PhotoStorageInterface
{
    /**
     * @param  resource|string  $contents
     */
    public function put(string $storageKey, mixed $contents): void;

    public function get(string $storageKey): string;

    public function exists(string $storageKey): bool;

    public function delete(string $storageKey): bool;

    public function size(string $storageKey): int;

    /**
     * Absolute local filesystem path for a storage key. For internal use only
     * (e.g. handing a file to an image-processing library) — never expose the
     * result outside the backend process (§11, §63).
     */
    public function path(string $storageKey): string;
}
