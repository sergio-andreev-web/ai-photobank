<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;
use ZipArchive;

/**
 * POST /api/admin/images/download (ADR-0014).
 */
class DownloadImagesTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photobank');
        $this->user = $this->loginAsAdmin();
    }

    private function image(string $name, string $bytes, bool $stored = true): Image
    {
        $batch = Batch::create(['status' => 'completed', 'created_by' => $this->user->id]);
        $key = 'originals/2026/09/'.$batch->id.'/'.uniqid('', true).'.jpg';
        if ($stored) {
            Storage::disk('photobank')->put($key, $bytes);
        }

        return Image::create([
            'batch_id' => $batch->id, 'original_name' => $name, 'storage_key' => $key,
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes.uniqid()), 'status' => 'ready', 'created_by' => $this->user->id,
        ]);
    }

    public function test_zip_holds_the_originals_under_their_names_with_repeats_numbered(): void
    {
        $a = $this->image('Каска.jpg', 'bytes-a');
        $b = $this->image('каска.jpg', 'bytes-b');
        $c = $this->image('plate.webp', 'bytes-c');

        $response = $this->postJson('/api/admin/images/download', ['image_ids' => [$a->id, $b->id, $c->id]], $this->csrfHeaders());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertMatchesRegularExpression('/attachment; filename=photobank-\d{8}-\d{6}\.zip/', $response->headers->get('Content-Disposition'));

        $path = $response->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            $this->assertSame(ZipArchive::CM_STORE, $zip->statIndex($i)['comp_method']);
        }
        $zip->close();

        $this->assertSame(['Каска.jpg' => 'bytes-a', 'каска (2).jpg' => 'bytes-b', 'plate.webp' => 'bytes-c'], $entries);
    }

    public function test_unknown_or_deleted_images_are_a_validation_error(): void
    {
        $kept = $this->image('a.jpg', 'x');
        $deleted = $this->image('b.jpg', 'y');
        $deleted->delete();

        $this->postJson('/api/admin/images/download', ['image_ids' => [$kept->id, $deleted->id, 999999]], $this->csrfHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.fields.image_ids.0', "Изображения не найдены или удалены: {$deleted->id}, 999999.");
    }

    public function test_request_shape_is_validated(): void
    {
        $this->postJson('/api/admin/images/download', ['image_ids' => []], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/images/download', ['image_ids' => range(1, 101)], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/images/download', ['image_ids' => [1, 1]], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/images/download', ['image_ids' => [1], 'format' => 'tar'], $this->csrfHeaders())->assertStatus(422);
    }

    public function test_a_missing_original_is_media_not_available(): void
    {
        $image = $this->image('lost.jpg', 'x', stored: false);

        $this->postJson('/api/admin/images/download', ['image_ids' => [$image->id]], $this->csrfHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MEDIA_NOT_AVAILABLE');
    }

    public function test_requires_csrf_and_authentication(): void
    {
        $image = $this->image('a.jpg', 'x');

        $this->postJson('/api/admin/images/download', ['image_ids' => [$image->id]])->assertStatus(419);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/images/download', ['image_ids' => [$image->id]], $this->csrfHeaders())->assertStatus(401);
    }
}
