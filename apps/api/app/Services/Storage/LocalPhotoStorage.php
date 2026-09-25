<?php

namespace App\Services\Storage;

use App\Contracts\Storage\PhotoStorageInterface;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * PhotoStorageInterface backed by the "photobank" Laravel Filesystem disk
 * (config/filesystems.php, ТЗ §11).
 */
final readonly class LocalPhotoStorage implements PhotoStorageInterface
{
    public function __construct(private Filesystem $disk)
    {
    }

    public function put(string $storageKey, mixed $contents): void
    {
        $this->disk->put($storageKey, $contents);
    }

    public function get(string $storageKey): string
    {
        return $this->disk->get($storageKey);
    }

    public function exists(string $storageKey): bool
    {
        return $this->disk->exists($storageKey);
    }

    public function delete(string $storageKey): bool
    {
        return $this->disk->delete($storageKey);
    }

    public function size(string $storageKey): int
    {
        return $this->disk->size($storageKey);
    }

    public function path(string $storageKey): string
    {
        return $this->disk->path($storageKey);
    }
}
