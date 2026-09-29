<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * GET /api/admin/images/trash, POST /api/admin/images/{image}/restore (ADR-0013).
 */
class TrashTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    public function test_trash_lists_deleted_images_most_recent_first_with_deleted_at(): void
    {
        $kept = $this->makeImage();
        $older = $this->makeImage(['original_name' => 'older.jpg']);
        $newer = $this->makeImage(['original_name' => 'newer.jpg']);
        $older->delete();
        $newer->delete();
        DB::table('images')->where('id', $older->id)->update(['deleted_at' => '2026-09-20 10:00:00']);
        DB::table('images')->where('id', $newer->id)->update(['deleted_at' => '2026-09-29 10:00:00']);

        $response = $this->getJson('/api/admin/images/trash')->assertOk();

        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.deleted_at', '2026-09-29T10:00:00Z')
            ->assertJsonPath('data.0.original_name', 'newer.jpg');
        $this->assertNotContains($kept->id, array_column($response->json('data'), 'id'));
    }

    public function test_restore_brings_the_image_back_and_recounts_its_batch(): void
    {
        $image = $this->makeImage();
        $batch = Batch::find($image->batch_id);
        $batch->update(['status' => 'processing']);
        $image->delete();

        $this->getJson("/api/admin/images/{$image->id}")->assertStatus(404);

        $response = $this->postJson("/api/admin/images/{$image->id}/restore", [], $this->csrfHeaders());

        $response->assertOk()->assertJsonPath('id', $image->id)->assertJsonPath('status', 'ready');
        $this->assertFalse($image->fresh()->trashed());
        $this->getJson("/api/admin/images/{$image->id}")->assertOk();
        $this->getJson('/api/admin/images/trash')->assertOk()->assertJsonPath('meta.total', 0);
        // Its only image is final again: the batch finishes.
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->total_images);
    }

    public function test_restore_is_idempotent_and_unknown_image_is_not_found(): void
    {
        $image = $this->makeImage();

        $this->postJson("/api/admin/images/{$image->id}/restore", [], $this->csrfHeaders())->assertOk();
        $this->postJson('/api/admin/images/999999/restore', [], $this->csrfHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_requires_authentication_and_csrf(): void
    {
        $image = $this->makeImage();
        $image->delete();

        $this->postJson("/api/admin/images/{$image->id}/restore")->assertStatus(419);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/images/trash')->assertStatus(401);
        $this->postJson("/api/admin/images/{$image->id}/restore", [], $this->csrfHeaders())->assertStatus(401);
    }
}
