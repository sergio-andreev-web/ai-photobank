<?php

namespace Tests\Feature\Admin;

use App\Jobs\RegenerateTagEmbedding;
use App\Models\Image;
use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * /api/admin/tag-candidates* (ТЗ §40, §50, §56; ADR-0008;
 * state-machines.md "Tag candidate").
 */
class TagCandidateControllerTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const array CANDIDATE_KEYS = ['id', 'display_name', 'normalized_name', 'type', 'status', 'occurrences_count', 'closest_tag', 'similarity', 'sample_images', 'created_at', 'updated_at', 'resolved_at', 'resolved_by', 'resolved_tag_id'];

    private const array RESOLUTION_KEYS = ['candidate', 'tag', 'attached_images_count', 'alias_created'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @param  list<array{0: Image, 1: float}>  $images
     */
    private function candidate(string $name, array $images = [], array $attributes = []): TagCandidate
    {
        $candidate = TagCandidate::create(array_merge([
            'display_name' => $name,
            'normalized_name' => mb_strtolower($name),
            'type' => 'object',
            'status' => 'pending',
            'occurrences_count' => count($images),
        ], $attributes));

        foreach ($images as [$image, $confidence]) {
            TagCandidateImage::create(['candidate_id' => $candidate->id, 'image_id' => $image->id, 'confidence' => $confidence]);
        }

        return $candidate;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/admin/tag-candidates')->assertStatus(401);
    }

    public function test_list_defaults_to_pending_by_occurrences_with_samples(): void
    {
        $this->loginAsAdmin();
        $drill = $this->makeTag('Буровая');
        $images = [];
        for ($i = 0; $i < 10; $i++) {
            $images[] = $this->makeImage(['thumbnail_key' => $i === 0 ? null : "variants/{$i}/thumbnail.webp"]);
        }
        $deleted = $this->makeImage(['thumbnail_key' => 'variants/x/thumbnail.webp']);

        $links = array_map(fn (int $i) => [$images[$i], round(0.5 + $i / 100, 2)], range(0, 9));
        $links[] = [$deleted, 0.99];
        $big = $this->candidate('drilling platform', $links, ['closest_tag_id' => $drill->id, 'similarity' => 0.71, 'occurrences_count' => 11]);
        $small = $this->candidate('seagull', [[$images[0], 0.4]]);
        $this->candidate('rejected thing', [], ['status' => 'rejected']);
        $deleted->delete();

        $response = $this->getJson('/api/admin/tag-candidates')->assertOk();

        $this->assertSame([$big->id, $small->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 2);
        $this->assertSame(self::CANDIDATE_KEYS, array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.closest_tag', ['id' => $drill->id, 'name' => 'Буровая', 'type' => 'object', 'status' => 'active']);
        $response->assertJsonPath('data.0.similarity', 0.71);
        $response->assertJsonPath('data.1.closest_tag', null);

        $samples = $response->json('data.0.sample_images');
        $this->assertCount(8, $samples);
        $this->assertSame(array_map(fn ($i) => $images[$i]->id, range(9, 2)), array_column($samples, 'id'), 'by confidence, deleted excluded');
        $this->assertSame("/api/media/images/{$images[9]->id}/thumbnail", $samples[0]['thumbnail_url']);
        $this->assertSame(0.59, $samples[0]['confidence']);

        $response = $this->getJson('/api/admin/tag-candidates?status=pending&sort=-similarity')->assertOk();
        $this->assertSame([$big->id, $small->id], array_column($response->json('data'), 'id'), 'null similarity last');
        $this->assertNull($this->getJson('/api/admin/tag-candidates?status=pending&sort=-similarity')->json('data.1.similarity'));

        $this->assertSame(['rejected thing'], array_column($this->getJson('/api/admin/tag-candidates?status=rejected')->json('data'), 'display_name'));
        $this->assertSame([$small->id], array_column($this->getJson('/api/admin/tag-candidates?q=GULL')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/admin/tag-candidates?type=person')->json('data'));
        $this->getJson('/api/admin/tag-candidates?status=open')->assertStatus(422);
        $this->getJson('/api/admin/tag-candidates?per_page=101')->assertStatus(422);
    }

    public function test_approve_creates_tag_and_links_candidate_images_except_deleted(): void
    {
        $user = $this->loginAsAdmin();
        [$a, $b, $c, $d] = [$this->makeImage(), $this->makeImage(), $this->makeImage(), $this->makeImage()];
        $candidate = $this->candidate('Drilling platform', [[$a, 0.9], [$b, 0.8], [$c, 0.7], [$d, 0.6]]);
        $d->delete();

        $response = $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve", [], $this->csrfHeaders());

        $response->assertOk();
        $this->assertSame(self::RESOLUTION_KEYS, array_keys($response->json()));
        $tag = Tag::where('normalized_name', 'drilling platform')->sole();
        $response->assertJsonPath('tag', ['id' => $tag->id, 'name' => 'Drilling platform', 'type' => 'object', 'status' => 'active'])
            ->assertJsonPath('attached_images_count', 3)
            ->assertJsonPath('alias_created', false)
            ->assertJsonPath('candidate.status', 'created')
            ->assertJsonPath('candidate.resolved_by', $user->id)
            ->assertJsonPath('candidate.resolved_tag_id', $tag->id);
        $this->assertNotNull($response->json('candidate.resolved_at'));
        $this->assertSame($user->id, $tag->created_by);

        $links = ImageTag::where('tag_id', $tag->id)->orderBy('image_id')->get();
        $this->assertSame([$a->id, $b->id, $c->id], $links->pluck('image_id')->all());
        $this->assertSame([['ai', false, '0.9000']], [[$links[0]->source, $links[0]->locked, $links[0]->confidence]]);

        Queue::assertPushed(RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $tag->id);

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve", [], $this->csrfHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TAG_CANDIDATE_ALREADY_RESOLVED')
            ->assertJsonPath('error.details.status', 'created');
        $this->assertSame(1, Tag::count());
    }

    public function test_approve_and_merge_skip_suppressed_pairs_and_keep_existing_links(): void
    {
        $user = $this->loginAsAdmin();
        $target = $this->makeTag('Буровая');
        [$suppressed, $manual, $fresh] = [$this->makeImage(), $this->makeImage(), $this->makeImage()];
        ImageTagSuppression::create(['image_id' => $suppressed->id, 'tag_id' => $target->id, 'created_by' => $user->id]);
        ImageTag::create(['image_id' => $manual->id, 'tag_id' => $target->id, 'source' => 'manual', 'locked' => true]);
        $candidate = $this->candidate('drilling platform', [[$suppressed, 0.9], [$manual, 0.8], [$fresh, 0.7]]);

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/merge", ['tag_id' => $target->id], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('attached_images_count', 1)
            ->assertJsonPath('alias_created', true)
            ->assertJsonPath('candidate.status', 'merged')
            ->assertJsonPath('candidate.resolved_tag_id', $target->id)
            ->assertJsonPath('tag.id', $target->id);

        $this->assertFalse(ImageTag::where('image_id', $suppressed->id)->exists(), 'suppression is respected');
        $this->assertSame(['manual', true], [ImageTag::where('image_id', $manual->id)->value('source'), (bool) ImageTag::where('image_id', $manual->id)->value('locked')]);
        $this->assertSame('ai', ImageTag::where('image_id', $fresh->id)->value('source'));

        $alias = TagAlias::sole();
        $this->assertSame([$target->id, 'drilling platform', 'drilling platform'], [$alias->tag_id, $alias->alias, $alias->normalized_alias]);
        Queue::assertPushed(RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $target->id);
    }

    public function test_approve_with_overrides_and_conflicts(): void
    {
        $this->loginAsAdmin();
        $parent = $this->makeTag('Нефтегаз');
        $existing = $this->makeTag('Буровая платформа');
        $image = $this->makeImage();
        $candidate = $this->candidate('drilling platform', [[$image, 0.9]]);

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve", ['name' => 'Буровая платформа'], $this->csrfHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TAG_ALREADY_EXISTS')
            ->assertJsonPath('error.details.existing_tag_id', $existing->id);
        $this->assertSame('pending', $candidate->refresh()->status, 'failed approve leaves the candidate pending');
        $this->assertSame(0, ImageTag::count());

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve", ['status' => 'x'], $this->csrfHeaders())
            ->assertStatus(422);
        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve", ['parent_id' => $candidate->id + 1000], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.code', 'TAG_PARENT_INVALID');

        $response = $this->postJson("/api/admin/tag-candidates/{$candidate->id}/approve",
            ['name' => 'Морская платформа', 'type' => 'scene', 'parent_id' => $parent->id], $this->csrfHeaders());

        $response->assertOk()->assertJsonPath('tag.name', 'Морская платформа')->assertJsonPath('tag.type', 'scene');
        $this->assertSame($parent->id, Tag::find($response->json('tag.id'))->parent_id);

        $this->postJson('/api/admin/tag-candidates/999/approve', [], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'TAG_CANDIDATE_NOT_FOUND');
    }

    public function test_merge_errors_leave_candidate_pending(): void
    {
        $this->loginAsAdmin();
        $target = $this->makeTag('Буровая');
        $archived = $this->makeTag('Старое', 'object', 'archived');
        $other = $this->makeTag('Вышка');
        $this->makeAlias($other, 'drilling platform');
        $candidate = $this->candidate('drilling platform', [[$this->makeImage(), 0.9]]);
        $url = "/api/admin/tag-candidates/{$candidate->id}/merge";

        $this->postJson($url, ['tag_id' => 999], $this->csrfHeaders())->assertStatus(404)->assertJsonPath('error.code', 'TAG_NOT_FOUND');
        $this->postJson($url, ['tag_id' => $archived->id], $this->csrfHeaders())->assertStatus(409)->assertJsonPath('error.code', 'TAG_ARCHIVED');
        $this->postJson($url, ['tag_id' => $target->id], $this->csrfHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALIAS_ALREADY_EXISTS')
            ->assertJsonPath('error.details.existing_tag_id', $other->id);
        $this->postJson($url, [], $this->csrfHeaders())->assertStatus(422);
        $this->postJson($url, ['tag_id' => $target->id, 'alias' => 'x'], $this->csrfHeaders())->assertStatus(422);

        $this->assertSame('pending', $candidate->refresh()->status);
        $this->assertSame(0, ImageTag::count());

        $this->postJson($url, ['tag_id' => $target->id, 'create_alias' => false], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('alias_created', false)
            ->assertJsonPath('attached_images_count', 1);
        $this->assertSame(1, TagAlias::count(), 'no alias added');

        $this->postJson($url, ['tag_id' => $target->id], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'TAG_CANDIDATE_ALREADY_RESOLVED');
    }

    public function test_merge_does_not_duplicate_alias_that_already_resolves_to_target(): void
    {
        $this->loginAsAdmin();
        $target = $this->makeTag('drilling platform', 'scene');
        $candidate = $this->candidate('Drilling Platform');

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/merge", ['tag_id' => $target->id], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('alias_created', false)
            ->assertJsonPath('attached_images_count', 0);

        $this->assertSame(0, TagAlias::count());
    }

    public function test_reject(): void
    {
        $user = $this->loginAsAdmin();
        $image = $this->makeImage();
        $candidate = $this->candidate('seagull', [[$image, 0.4]]);

        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/reject", [], $this->csrfHeaders())
            ->assertOk()
            ->assertJsonPath('tag', null)
            ->assertJsonPath('attached_images_count', 0)
            ->assertJsonPath('alias_created', false)
            ->assertJsonPath('candidate.status', 'rejected')
            ->assertJsonPath('candidate.resolved_by', $user->id)
            ->assertJsonPath('candidate.resolved_tag_id', null);

        $this->assertSame(0, ImageTag::count());
        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/reject", [], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.details.status', 'rejected');
        $this->postJson("/api/admin/tag-candidates/{$candidate->id}/merge", ['tag_id' => $this->makeTag('Чайка')->id], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'TAG_CANDIDATE_ALREADY_RESOLVED');
        $this->postJson('/api/admin/tag-candidates/999/reject', [], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'TAG_CANDIDATE_NOT_FOUND');
    }
}
