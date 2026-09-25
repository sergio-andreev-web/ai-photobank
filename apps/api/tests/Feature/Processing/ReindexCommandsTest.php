<?php

namespace Tests\Feature\Processing;

use App\Jobs\RegenerateTagEmbedding;
use App\Jobs\RunProcessingTask;
use App\Models\ImageAnalysis;
use App\Models\ImageEmbedding;
use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use App\Models\TagEmbedding;
use App\Models\User;
use App\Services\Ai\FakeAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsProcessingQueue;
use Tests\TestCase;

class ReindexCommandsTest extends TestCase
{
    use RefreshDatabase;
    use RunsProcessingQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRunsProcessingQueue();
    }

    public function test_tags_select_only_missing_active_version_and_obey_dry_run_limit_and_batch(): void
    {
        $first = $this->tag('First');
        $second = $this->tag('Second');
        $third = $this->tag('Third');
        $this->tagEmbedding($third, FakeAiClient::EMBEDDING_REVISION);
        $this->tagEmbedding($second, 'old-revision');

        $this->artisan('photobank:reindex-tags', ['--dry-run' => true, '--batch' => 1])
            ->expectsOutputToContain('Tags: 2 eligible; dry run, none queued')->assertExitCode(0);
        Queue::assertNotPushed(RegenerateTagEmbedding::class);

        $this->artisan('photobank:reindex-tags', ['--limit' => 1, '--batch' => 1])->assertExitCode(0);
        Queue::assertPushed(RegenerateTagEmbedding::class, 1);
        Queue::assertPushed(RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $first->id);

        $this->artisan('photobank:reindex-tags', ['--batch' => 1])->assertExitCode(0);
        Queue::assertPushed(RegenerateTagEmbedding::class, 2);
        Queue::assertPushed(RegenerateTagEmbedding::class, fn ($job) => $job->tagId === $second->id);
        $this->artisan('photobank:reindex-tags', ['--batch' => 1])->assertExitCode(0);
        Queue::assertPushed(RegenerateTagEmbedding::class, 2);
    }

    public function test_images_reprocess_only_ready_missing_active_version_and_keep_history_and_manual_decisions(): void
    {
        config()->set('ai.embedding.active_model_version', 'future-revision');
        $image = $this->processedImage();
        $this->assertSame(1, ImageAnalysis::where('image_id', $image->id)->count());
        $this->assertFalse(ImageEmbedding::where('image_id', $image->id)->sole()->active);

        $tag = $this->tag('Manual');
        $suppressed = $this->tag('Suppressed');
        $admin = User::firstOrFail();
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'source' => 'manual', 'locked' => true]);
        ImageTagSuppression::create(['image_id' => $image->id, 'tag_id' => $suppressed->id, 'created_by' => $admin->id]);
        config()->set('ai.embedding.active_model_version', FakeAiClient::EMBEDDING_REVISION);
        $alreadyCurrent = $this->processedImage();
        $this->assertTrue(ImageEmbedding::where('image_id', $alreadyCurrent->id)->sole()->active);

        $before = Queue::pushed(RunProcessingTask::class)->count();
        $this->artisan('photobank:reindex-images', ['--dry-run' => true, '--batch' => 1])
            ->expectsOutputToContain('Images: 1 eligible; dry run, none queued')->assertExitCode(0);
        $this->assertSame($before, Queue::pushed(RunProcessingTask::class)->count());

        $this->artisan('photobank:reindex-images', ['--limit' => 1, '--batch' => 1])->assertExitCode(0);
        $this->artisan('photobank:reindex-images', ['--batch' => 1])->assertExitCode(0);
        $this->assertSame($before + 1, Queue::pushed(RunProcessingTask::class)->count());

        $this->runQueuedJobs();
        $this->assertSame(2, ImageAnalysis::where('image_id', $image->id)->count());
        $this->assertSame(2, ImageEmbedding::where('image_id', $image->id)->count());
        $this->assertSame(1, ImageEmbedding::where('image_id', $image->id)->where('active', true)->count());
        $this->assertTrue(ImageTag::where('image_id', $image->id)->where('tag_id', $tag->id)->where('source', 'manual')->exists());
        $this->assertTrue(ImageTagSuppression::where('image_id', $image->id)->where('tag_id', $suppressed->id)->exists());

        $after = Queue::pushed(RunProcessingTask::class)->count();
        $this->artisan('photobank:reindex-images')->expectsOutputToContain('Images: 0 eligible')->assertExitCode(0);
        $this->assertSame($after, Queue::pushed(RunProcessingTask::class)->count());
    }

    public function test_commands_reject_unconfigured_version_and_invalid_batch(): void
    {
        config()->set('ai.embedding.active_model_version', null);
        $this->artisan('photobank:reindex-tags')->assertExitCode(1);
        $this->artisan('photobank:reindex-images')->assertExitCode(1);
        config()->set('ai.embedding.active_model_version', FakeAiClient::EMBEDDING_REVISION);
        $this->artisan('photobank:reindex-tags', ['--batch' => 0])->assertExitCode(1);
    }

    public function test_reprocess_keeps_previous_active_embedding_when_ai_returns_another_version(): void
    {
        $image = $this->processedImage();
        $previous = ImageEmbedding::where('image_id', $image->id)->sole();
        $this->assertTrue($previous->active);

        // Laravel already expects a new revision, but the AI still serves the old one: the new row
        // is stored as history and must not take the image out of the index (ADR-0009).
        config()->set('ai.embedding.active_model_version', 'future-revision');
        $this->artisan('photobank:reindex-images')->assertExitCode(0);
        $this->runQueuedJobs();

        $this->assertSame(2, ImageAnalysis::where('image_id', $image->id)->count());
        $this->assertSame(2, ImageEmbedding::where('image_id', $image->id)->count());
        $this->assertSame([$previous->id], ImageEmbedding::where('image_id', $image->id)->where('active', true)->pluck('id')->all());
    }

    private function tag(string $name): Tag
    {
        return Tag::create([
            'name' => $name,
            'slug' => strtolower($name),
            'normalized_name' => strtolower($name),
            'type' => 'object',
            'status' => 'active',
        ]);
    }

    private function tagEmbedding(Tag $tag, string $revision): void
    {
        TagEmbedding::create([
            'tag_id' => $tag->id,
            'model_key' => config('ai.embedding.active_model_key'),
            'model_version' => $revision,
            'source_text' => $tag->name,
            'embedding' => '['.implode(',', array_fill(0, 1152, '0')).']',
        ]);
    }
}
