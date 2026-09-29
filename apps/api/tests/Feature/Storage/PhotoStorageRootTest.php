<?php

namespace Tests\Feature\Storage;

use Tests\TestCase;

class PhotoStorageRootTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHOTO_STORAGE_ROOT');
        parent::tearDown();
    }

    public function test_relative_root_is_resolved_against_the_app_root(): void
    {
        putenv('PHOTO_STORAGE_ROOT=storage/app/photobank');

        $this->assertSame(base_path('storage/app/photobank'), $this->photobankRoot());
    }

    public function test_absolute_root_is_kept(): void
    {
        putenv('PHOTO_STORAGE_ROOT=/srv/photos');

        $this->assertSame('/srv/photos', $this->photobankRoot());
    }

    public function test_default_root_is_under_storage(): void
    {
        putenv('PHOTO_STORAGE_ROOT');

        $this->assertSame(storage_path('app/photobank'), $this->photobankRoot());
    }

    private function photobankRoot(): string
    {
        return (require base_path('config/filesystems.php'))['disks']['photobank']['root'];
    }
}
