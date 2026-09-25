<?php

namespace Tests\Feature\Storage;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Services\Storage\LocalPhotoStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalPhotoStorageTest extends TestCase
{
    public function test_container_resolves_photo_storage_interface_to_local_implementation(): void
    {
        $this->assertInstanceOf(LocalPhotoStorage::class, $this->app->make(PhotoStorageInterface::class));
    }

    public function test_put_get_exists_and_delete_round_trip_by_storage_key(): void
    {
        Storage::fake('photobank');
        $storage = $this->app->make(PhotoStorageInterface::class);

        $key = 'originals/2026/09/batch-1/image.jpg';
        $this->assertFalse($storage->exists($key));

        $storage->put($key, 'binary-contents');

        $this->assertTrue($storage->exists($key));
        $this->assertSame('binary-contents', $storage->get($key));
        $this->assertSame(strlen('binary-contents'), $storage->size($key));
        $this->assertStringEndsWith($key, $storage->path($key));

        $storage->delete($key);

        $this->assertFalse($storage->exists($key));
    }
}
