<?php

namespace Tests\Feature\Admin;

use App\Contracts\Ai\AiClientInterface;
use App\Jobs\RegenerateTagEmbedding;
use App\Models\ImageTag;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagCandidate;
use App\Models\TagEmbedding;
use App\Services\Tagging\TagEmbeddingRegenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * /api/admin/tags and aliases (ТЗ §16, §17, §21, §50, §55; ADR-0007).
 */
class TagControllerTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const array TAG_KEYS = ['id', 'parent_id', 'name', 'slug', 'normalized_name', 'type', 'status', 'images_count', 'aliases', 'created_at', 'updated_at'];

    private const array ALIAS_KEYS = ['id', 'tag_id', 'alias', 'normalized_alias', 'locale', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/admin/tags')->assertStatus(401);
    }

    public function test_create_tag_returns_contract_shape_and_queues_embedding(): void
    {
        $user = $this->loginAsAdmin();

        $response = $this->postJson('/api/admin/tags', ['name' => ' Каска ', 'type' => 'object', 'parent_id' => null], $this->csrfHeaders());

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Каска')
            ->assertJsonPath('slug', 'kaska')
            ->assertJsonPath('normalized_name', 'каска')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('images_count', 0)
            ->assertJsonPath('aliases', []);
        $this->assertSame(self::TAG_KEYS, array_keys($response->json()));
        $this->assertSame($user->id, Tag::sole()->created_by);

        Queue::assertPushedOn('images.ai', RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $response->json('id'));
    }

    public function test_create_conflicts_follow_collision_rule_a(): void
    {
        $this->loginAsAdmin();
        $helmet = $this->makeTag('Каска');
        $car = $this->makeTag('Автомобиль');
        $this->makeAlias($car, 'машина');

        $this->postJson('/api/admin/tags', ['name' => 'КАСКА', 'type' => 'object'], $this->csrfHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TAG_ALREADY_EXISTS')
            ->assertJsonPath('error.details.existing_tag_id', $helmet->id);

        $this->postJson('/api/admin/tags', ['name' => 'Машина', 'type' => 'concept'], $this->csrfHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TAG_ALREADY_EXISTS')
            ->assertJsonPath('error.details.existing_tag_id', $car->id);

        // Same normalized name under another type is allowed (§16).
        $this->postJson('/api/admin/tags', ['name' => 'Каска', 'type' => 'concept'], $this->csrfHeaders())
            ->assertStatus(201);
    }

    public function test_slug_is_transliterated_and_suffixed_on_collision(): void
    {
        $this->loginAsAdmin();

        $slugs = array_map(
            fn (string $type) => $this->postJson('/api/admin/tags', ['name' => 'Каска', 'type' => $type], $this->csrfHeaders())
                ->assertStatus(201)->json('slug'),
            ['object', 'concept', 'brand'],
        );
        $slugs[] = $this->postJson('/api/admin/tags', ['name' => '!!!', 'type' => 'other'], $this->csrfHeaders())->json('slug');

        $this->assertSame(['kaska', 'kaska-2', 'kaska-3', 'tag'], $slugs);
    }

    public function test_create_validation(): void
    {
        $this->loginAsAdmin();
        $archived = $this->makeTag('Архив', 'other', 'archived');

        foreach ([
            ['type' => 'object'],
            ['name' => '   ', 'type' => 'object'],
            ['name' => 'X', 'type' => 'planet'],
            ['name' => 'X', 'type' => 'object', 'slug' => 'x'],
            ['name' => str_repeat('a', 256), 'type' => 'object'],
        ] as $body) {
            $this->postJson('/api/admin/tags', $body, $this->csrfHeaders())
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->postJson('/api/admin/tags', ['name' => 'X', 'type' => 'object', 'parent_id' => $archived->id], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.code', 'TAG_PARENT_INVALID');
        $this->postJson('/api/admin/tags', ['name' => 'X', 'type' => 'object', 'parent_id' => 999], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.code', 'TAG_PARENT_INVALID');

        $this->assertSame(1, Tag::count());
    }

    public function test_show_and_not_found(): void
    {
        $this->loginAsAdmin();
        $tag = $this->makeTag('Каска');
        $alias = $this->makeAlias($tag, 'helmet');

        $response = $this->getJson("/api/admin/tags/{$tag->id}")->assertOk();
        $this->assertSame(self::ALIAS_KEYS, array_keys($response->json('aliases.0')));
        $response->assertJsonPath('aliases.0.id', $alias->id);

        $this->getJson('/api/admin/tags/999')->assertStatus(404)->assertJsonPath('error.code', 'TAG_NOT_FOUND');
    }

    public function test_list_filters_search_sort_and_counts(): void
    {
        $this->loginAsAdmin();
        $helmet = $this->makeTag('Каска');
        $car = $this->makeTag('Автомобиль');
        $winter = $this->makeTag('Зима', 'season', 'archived');
        $this->makeAlias($car, 'Vehicle');

        $a = $this->makeImage();
        $b = $this->makeImage();
        $gone = $this->makeImage();
        foreach ([[$a, $car], [$b, $car], [$gone, $car], [$a, $helmet]] as [$image, $tag]) {
            ImageTag::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'source' => 'manual', 'locked' => true]);
        }
        $gone->delete();

        $all = $this->getJson('/api/admin/tags')->assertOk();
        $this->assertSame(['Автомобиль', 'Зима', 'Каска'], array_column($all->json('data'), 'name'));
        $all->assertJsonPath('meta', ['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1]);

        $byCount = $this->getJson('/api/admin/tags?sort=-images_count')->json('data');
        $this->assertSame([[$car->id, 2], [$helmet->id, 1], [$winter->id, 0]], array_map(fn ($t) => [$t['id'], $t['images_count']], $byCount));

        $this->assertSame([$car->id], array_column($this->getJson('/api/admin/tags?q=VEHIC')->json('data'), 'id'), 'q matches normalized aliases');
        $this->assertSame([$helmet->id], array_column($this->getJson('/api/admin/tags?q='.rawurlencode('каск'))->json('data'), 'id'));
        $this->assertSame([$winter->id], array_column($this->getJson('/api/admin/tags?status=archived')->json('data'), 'id'));
        $this->assertSame([$winter->id], array_column($this->getJson('/api/admin/tags?type=season')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/admin/tags?q=%25')->json('data'), 'LIKE wildcards are literal');

        $page = $this->getJson('/api/admin/tags?per_page=2&page=2')->assertOk();
        $page->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->assertCount(1, $page->json('data'));

        $this->getJson('/api/admin/tags?per_page=501')->assertStatus(422);
        $this->getJson('/api/admin/tags?sort=images_count')->assertStatus(422);
    }

    public function test_list_filters_by_parent(): void
    {
        $this->loginAsAdmin();
        $parent = $this->makeTag('Транспорт');
        $child = $this->makeTag('Автомобиль', 'object', 'active', $parent->id);

        $this->assertSame([$child->id], array_column($this->getJson("/api/admin/tags?parent_id={$parent->id}")->json('data'), 'id'));
    }

    public function test_rename_updates_normalized_name_and_slug_and_requeues_embedding(): void
    {
        $this->loginAsAdmin();
        $tag = $this->makeTag('Каска');

        $this->patchJson("/api/admin/tags/{$tag->id}", ['name' => 'Защитная  Каска'], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('name', 'Защитная  Каска')
            ->assertJsonPath('normalized_name', 'защитная каска')
            ->assertJsonPath('slug', 'zashhitnaia-kaska');

        Queue::assertPushed(RegenerateTagEmbedding::class, 1);

        $this->patchJson("/api/admin/tags/{$tag->id}", ['type' => 'concept'], $this->csrfHeaders())->assertOk();
        Queue::assertPushed(RegenerateTagEmbedding::class, 1);
    }

    public function test_patch_conflicts_and_validation(): void
    {
        $this->loginAsAdmin();
        $helmet = $this->makeTag('Каска');
        $hat = $this->makeTag('Шляпа');
        $car = $this->makeTag('Автомобиль');
        $this->makeAlias($car, 'машина');
        $this->makeAlias($hat, 'hat');

        $this->patchJson("/api/admin/tags/{$hat->id}", ['name' => 'каска'], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.details.existing_tag_id', $helmet->id);
        $this->patchJson("/api/admin/tags/{$hat->id}", ['name' => 'Машина'], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.details.existing_tag_id', $car->id);

        // Renaming to one of its own aliases is not a collision.
        $this->patchJson("/api/admin/tags/{$hat->id}", ['name' => 'Hat'], $this->csrfHeaders())->assertOk();

        $this->patchJson("/api/admin/tags/{$hat->id}", [], $this->csrfHeaders())->assertStatus(422);
        $this->patchJson("/api/admin/tags/{$hat->id}", ['slug' => 'x'], $this->csrfHeaders())->assertStatus(422);
        $this->patchJson("/api/admin/tags/{$hat->id}", ['status' => 'deleted'], $this->csrfHeaders())->assertStatus(422);
        $this->patchJson('/api/admin/tags/999', ['name' => 'x'], $this->csrfHeaders())->assertStatus(404);
    }

    public function test_parent_cannot_be_self_descendant_or_archived(): void
    {
        $this->loginAsAdmin();
        $root = $this->makeTag('Транспорт');
        $mid = $this->makeTag('Автомобиль', 'object', 'active', $root->id);
        $leaf = $this->makeTag('Грузовик', 'object', 'active', $mid->id);
        $archived = $this->makeTag('Архив', 'other', 'archived');

        foreach ([$root->id, $leaf->id, $archived->id] as $parentId) {
            $this->patchJson("/api/admin/tags/{$root->id}", ['parent_id' => $parentId], $this->csrfHeaders())
                ->assertStatus(422)->assertJsonPath('error.code', 'TAG_PARENT_INVALID');
        }

        $this->patchJson("/api/admin/tags/{$leaf->id}", ['parent_id' => $root->id], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('parent_id', $root->id);
        $this->patchJson("/api/admin/tags/{$leaf->id}", ['parent_id' => null], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('parent_id', null);
    }

    public function test_archive_and_restore_via_status(): void
    {
        $this->loginAsAdmin();
        $tag = $this->makeTag('Каска');

        $this->patchJson("/api/admin/tags/{$tag->id}", ['status' => 'archived'], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('status', 'archived');
        $this->patchJson("/api/admin/tags/{$tag->id}", ['status' => 'active'], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_delete_removes_unused_tag_and_archives_used_one(): void
    {
        $this->loginAsAdmin();
        $unused = $this->makeTag('Лишний');
        $this->makeAlias($unused, 'extra');
        $linked = $this->makeTag('Каска');
        $parent = $this->makeTag('Транспорт');
        $this->makeTag('Автомобиль', 'object', 'active', $parent->id);
        $closest = $this->makeTag('Буровая');
        ImageTag::create(['image_id' => $this->makeImage()->id, 'tag_id' => $linked->id, 'source' => 'ai', 'locked' => false]);
        TagCandidate::create(['display_name' => 'drilling platform', 'normalized_name' => 'drilling platform', 'type' => 'object', 'status' => 'pending', 'closest_tag_id' => $closest->id, 'similarity' => 0.7]);

        $this->deleteJson("/api/admin/tags/{$unused->id}", [], $this->csrfHeaders())->assertNoContent();
        $this->assertNull(Tag::find($unused->id));
        $this->assertSame(0, TagAlias::where('tag_id', $unused->id)->count(), 'aliases go with the tag');

        foreach ([$linked, $parent, $closest] as $tag) {
            $response = $this->deleteJson("/api/admin/tags/{$tag->id}", [], $this->csrfHeaders())
                ->assertOk()->assertJsonPath('status', 'archived');
            $this->assertSame(self::TAG_KEYS, array_keys($response->json()));
        }

        $this->assertSame(1, ImageTag::count(), 'archiving keeps existing links');
        $this->deleteJson('/api/admin/tags/999', [], $this->csrfHeaders())->assertStatus(404);
    }

    public function test_alias_create_conflicts_and_delete(): void
    {
        $this->loginAsAdmin();
        $helmet = $this->makeTag('Каска');
        $car = $this->makeTag('Автомобиль');
        $this->makeAlias($car, 'машина');

        $created = $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => ' Hard  Hat ', 'locale' => 'en'], $this->csrfHeaders());
        $created->assertStatus(201)
            ->assertJsonPath('tag_id', $helmet->id)
            ->assertJsonPath('alias', 'Hard  Hat')
            ->assertJsonPath('normalized_alias', 'hard hat')
            ->assertJsonPath('locale', 'en');
        $this->assertSame(self::ALIAS_KEYS, array_keys($created->json()));
        Queue::assertPushed(RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $helmet->id);

        // Globally unique alias, including the same tag.
        $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => 'МАШИНА'], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'ALIAS_ALREADY_EXISTS')->assertJsonPath('error.details.existing_tag_id', $car->id);
        $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => 'hard hat'], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.details.existing_tag_id', $helmet->id);
        // Not equal to another tag's canonical name.
        $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => 'автомобиль'], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.details.existing_tag_id', $car->id);

        $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => 'x', 'locale' => 'EN'], $this->csrfHeaders())->assertStatus(422);
        $this->postJson("/api/admin/tags/{$helmet->id}/aliases", ['alias' => ' '], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/tags/999/aliases', ['alias' => 'x'], $this->csrfHeaders())->assertStatus(404)->assertJsonPath('error.code', 'TAG_NOT_FOUND');

        $aliasId = $created->json('id');
        $carAliasId = TagAlias::where('tag_id', $car->id)->value('id');

        $this->deleteJson("/api/admin/tags/{$helmet->id}/aliases/{$carAliasId}", [], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'ALIAS_NOT_FOUND');
        $this->deleteJson("/api/admin/tags/{$helmet->id}/aliases/{$aliasId}", [], $this->csrfHeaders())->assertNoContent();
        $this->assertNull(TagAlias::find($aliasId));
    }

    public function test_embedding_job_stores_one_row_per_version_from_name_and_aliases(): void
    {
        $ai = new ScriptedEmbeddingsAiClient;
        $this->app->instance(AiClientInterface::class, $ai);
        $tag = $this->makeTag('Каска');
        $this->makeAlias($tag, 'helmet');

        (new RegenerateTagEmbedding($tag->id))->handle(app(TagEmbeddingRegenerator::class));
        (new RegenerateTagEmbedding($tag->id))->handle(app(TagEmbeddingRegenerator::class));

        $row = TagEmbedding::sole();
        $this->assertSame([$tag->id, 'siglip2-stub', 'fake-embedding-rev-1', 'Каска, helmet'],
            [$row->tag_id, $row->model_key, $row->model_version, $row->source_text]);
        $this->assertSame([['Каска, helmet']], $ai->textCalls, 'unchanged text is not re-embedded');

        $this->makeAlias($tag, 'hard hat');
        (new RegenerateTagEmbedding($tag->id))->handle(app(TagEmbeddingRegenerator::class));

        $this->assertSame('Каска, helmet, hard hat', TagEmbedding::sole()->source_text);
    }
}
