<?php

namespace Tests\Feature\Processing;

use App\Contracts\Tagging\ResolvedTag;
use App\Models\Image;
use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\Processing\ProcessingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsProcessingQueue;
use Tests\TestCase;

/**
 * Manual decisions survive AI runs (ТЗ §41, §76; ADR-0006, ADR-0007;
 * state-machines.md "Image tag link"): the tag_resolution stage replaces
 * only `source=ai, locked=false` links.
 */
class ReprocessTagInvariantsTest extends TestCase
{
    use RefreshDatabase;
    use RunsProcessingQueue;

    private int $seq = 0;

    public function test_first_run_links_resolved_tags_as_unlocked_ai(): void
    {
        $helmet = $this->tag('Каска');
        $this->resolver->result = [new ResolvedTag($helmet->id, 0.97)];

        $image = $this->processedImage();

        $link = ImageTag::where('image_id', $image->id)->sole();
        $this->assertSame([$helmet->id, 'ai', false, '0.9700'], [$link->tag_id, $link->source, $link->locked, $link->confidence]);
    }

    public function test_reprocess_replaces_ai_tags_but_keeps_manual_locked_and_suppressions(): void
    {
        [$aiOld, $aiKept, $manual, $locked, $suppressed, $aiNew] = [
            $this->tag('Завод'), $this->tag('Инженер'), $this->tag('Зима'),
            $this->tag('Улица'), $this->tag('Автомобиль'), $this->tag('Каска'),
        ];
        $this->resolver->result = [new ResolvedTag($aiOld->id, 0.8), new ResolvedTag($aiKept->id, 0.6)];
        $image = $this->processedImage();
        $admin = User::first();

        // Admin decisions between runs.
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $manual->id, 'source' => 'manual', 'locked' => true]);
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $locked->id, 'source' => 'ai', 'locked' => true, 'confidence' => 0.5]);
        ImageTagSuppression::create(['image_id' => $image->id, 'tag_id' => $suppressed->id, 'created_by' => $admin->id]);

        // The new AI result mentions everything, including the suppressed and manual tags.
        $this->resolver->result = [
            new ResolvedTag($aiKept->id, 0.9),
            new ResolvedTag($aiNew->id, 0.7),
            new ResolvedTag($manual->id, 0.99),
            new ResolvedTag($locked->id, 0.99),
            new ResolvedTag($suppressed->id, 0.99),
        ];
        app(ProcessingPipeline::class)->reprocess($image->id);
        $this->runQueuedJobs();

        $links = $this->links($image);
        $this->assertSame([
            $aiKept->id => ['ai', false, '0.9000'],
            $manual->id => ['manual', true, null],
            $locked->id => ['ai', true, '0.5000'],
            $aiNew->id => ['ai', false, '0.7000'],
        ], $links);
        $this->assertArrayNotHasKey($aiOld->id, $links, 'AI tag absent from the new result is replaced');
        $this->assertArrayNotHasKey($suppressed->id, $links, 'suppression wins over AI (§41)');
        $this->assertTrue(ImageTagSuppression::where('image_id', $image->id)->where('tag_id', $suppressed->id)->exists());
    }

    public function test_archived_tags_get_no_new_links(): void
    {
        $archived = $this->tag('Архив', 'archived');
        $this->resolver->result = [new ResolvedTag($archived->id, 0.9)];

        $image = $this->processedImage();

        $this->assertSame([], $this->links($image));
    }

    public function test_repeating_tag_resolution_is_idempotent(): void
    {
        $tag = $this->tag('Инженер');
        $this->resolver->result = [new ResolvedTag($tag->id, 0.4), new ResolvedTag($tag->id, 0.9)];

        $image = $this->processedImage();
        $firstLinkId = ImageTag::where('image_id', $image->id)->sole()->id;

        app(ProcessingPipeline::class)->reprocess($image->id);
        $this->runQueuedJobs();

        $link = ImageTag::where('image_id', $image->id)->sole();
        $this->assertSame([$firstLinkId, '0.9000'], [$link->id, $link->confidence], 'upsert by image_id + tag_id, highest confidence kept');
    }

    private function tag(string $name, string $status = 'active'): Tag
    {
        $this->seq++;

        return Tag::create([
            'name' => $name,
            'slug' => "tag-{$this->seq}",
            'normalized_name' => mb_strtolower($name),
            'type' => 'object',
            'status' => $status,
        ]);
    }

    /**
     * @return array<int, array{0: string, 1: bool, 2: string|null}>
     */
    private function links(Image $image): array
    {
        return ImageTag::where('image_id', $image->id)->orderBy('id')->get()
            ->mapWithKeys(fn (ImageTag $l) => [$l->tag_id => [$l->source, $l->locked, $l->confidence]])
            ->all();
    }
}
