<?php

namespace Tests\Feature\Admin;

use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * POST/DELETE /api/admin/images/{image}/tags (ТЗ §41; ADR-0006;
 * contracts addImageTag/removeImageTag).
 */
class ImageTagControllerTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const array IMAGE_TAG_KEYS = ['tag', 'source', 'confidence', 'locked', 'created_at', 'updated_at'];

    public function test_requires_authentication(): void
    {
        $image = $this->makeImage();
        $this->withSession(['_token' => $this->csrfToken()]);

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => 1], $this->csrfHeaders())->assertStatus(401);
        $this->deleteJson("/api/admin/images/{$image->id}/tags/1", [], $this->csrfHeaders())->assertStatus(401);
    }

    public function test_add_creates_manual_locked_link_and_repeat_is_200_unchanged(): void
    {
        $this->loginAsAdmin();
        $image = $this->makeImage();
        $tag = $this->makeTag('Каска');

        $created = $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $tag->id], $this->csrfHeaders());

        $created->assertStatus(201)
            ->assertJsonPath('tag', ['id' => $tag->id, 'name' => 'Каска', 'type' => 'object', 'status' => 'active'])
            ->assertJsonPath('source', 'manual')
            ->assertJsonPath('confidence', null)
            ->assertJsonPath('locked', true);
        $this->assertSame(self::IMAGE_TAG_KEYS, array_keys($created->json()));

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $tag->id], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('source', 'manual');

        $this->assertSame(1, ImageTag::count());
    }

    public function test_add_converts_ai_link_to_manual_and_lifts_suppression(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage();
        $aiTag = $this->makeTag('Завод');
        $suppressedTag = $this->makeTag('Зима', 'season');
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $aiTag->id, 'source' => 'ai', 'confidence' => 0.7, 'locked' => false]);
        ImageTagSuppression::create(['image_id' => $image->id, 'tag_id' => $suppressedTag->id, 'created_by' => $user->id]);

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $aiTag->id], $this->csrfHeaders())
            ->assertStatus(201)
            ->assertJsonPath('source', 'manual')
            ->assertJsonPath('locked', true);

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $suppressedTag->id], $this->csrfHeaders())
            ->assertStatus(201);

        $this->assertSame(0, ImageTagSuppression::count());
        $this->assertSame(1, ImageTag::where('tag_id', $aiTag->id)->count());
    }

    public function test_add_errors(): void
    {
        $this->loginAsAdmin();
        $image = $this->makeImage();
        $archived = $this->makeTag('Архив', 'other', 'archived');
        $deleted = $this->makeImage();
        $deleted->delete();

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $archived->id], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'TAG_ARCHIVED');
        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => 999], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'TAG_NOT_FOUND');
        $this->postJson('/api/admin/images/999/tags', ['tag_id' => $archived->id], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
        $this->postJson("/api/admin/images/{$deleted->id}/tags", ['tag_id' => $archived->id], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
        $this->postJson("/api/admin/images/{$image->id}/tags", [], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $archived->id, 'locked' => false], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => 1])
            ->assertStatus(419);

        $this->assertSame(0, ImageTag::count());
    }

    public function test_remove_deletes_link_and_records_suppression_for_any_source(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage();
        $ai = $this->makeTag('Завод');
        $manual = $this->makeTag('Инженер', 'person');
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $ai->id, 'source' => 'ai', 'confidence' => 0.7, 'locked' => false]);
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $manual->id, 'source' => 'manual', 'locked' => true]);

        $this->deleteJson("/api/admin/images/{$image->id}/tags/{$ai->id}", [], $this->csrfHeaders())->assertNoContent();
        $this->deleteJson("/api/admin/images/{$image->id}/tags/{$manual->id}", [], $this->csrfHeaders())->assertNoContent();

        $this->assertSame(0, ImageTag::count());
        $this->assertEqualsCanonicalizing([$ai->id, $manual->id], ImageTagSuppression::pluck('tag_id')->all());
        $this->assertSame([$user->id], ImageTagSuppression::distinct()->pluck('created_by')->all());

        $this->deleteJson("/api/admin/images/{$image->id}/tags/{$ai->id}", [], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_TAG_NOT_FOUND');
        $this->deleteJson("/api/admin/images/999/tags/{$ai->id}", [], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
        $this->assertSame(2, ImageTagSuppression::count());
    }

    public function test_remove_then_add_again_restores_manual_link(): void
    {
        $this->loginAsAdmin();
        $image = $this->makeImage();
        $tag = $this->makeTag('Каска');

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $tag->id], $this->csrfHeaders())->assertStatus(201);
        $this->deleteJson("/api/admin/images/{$image->id}/tags/{$tag->id}", [], $this->csrfHeaders())->assertNoContent();
        $this->assertSame(1, ImageTagSuppression::count());

        $this->postJson("/api/admin/images/{$image->id}/tags", ['tag_id' => $tag->id], $this->csrfHeaders())->assertStatus(201);

        $this->assertSame(0, ImageTagSuppression::count());
        $this->assertTrue(ImageTag::where('image_id', $image->id)->where('tag_id', $tag->id)->where('source', 'manual')->exists());
    }
}
