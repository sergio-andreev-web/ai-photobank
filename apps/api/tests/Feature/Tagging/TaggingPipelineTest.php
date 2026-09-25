<?php

namespace Tests\Feature\Tagging;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\Concept;
use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Tagging\TagResolverInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\ImageTag;
use App\Models\ProcessingTask;
use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use App\Services\Processing\ProcessingPipeline;
use App\Services\Tagging\CandidateModeration;
use App\Services\Tagging\ImageTagEditor;
use App\Services\Tagging\TagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\RunsProcessingQueue;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * TagResolver inside the real pipeline (§28, §36, §41, §76; ADR-0006,
 * ADR-0008): manual decisions and suppressions survive reprocess, candidate
 * counters are not inflated by re-runs, moderation outcomes are picked up by
 * the next run, and an AI outage during stage 3 is retried.
 */
class TaggingPipelineTest extends TestCase
{
    use BuildsTaxonomy;
    use RefreshDatabase;
    use RunsProcessingQueue;

    private ScriptedEmbeddingsAiClient $scripted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scripted = new ScriptedEmbeddingsAiClient;
        $this->scripted->fake = $this->ai;
        $this->app->instance(AiClientInterface::class, $this->scripted);
        $this->app->forgetInstance(TagResolverInterface::class);
        $this->app->bind(TagResolverInterface::class, TagResolver::class);

        $this->ai->withConcepts([
            new Concept(ConceptType::Object, 'Protective helmet', 0.93),
            new Concept(ConceptType::Object, 'drilling platform', 0.81),
            new Concept(ConceptType::Person, 'Engineer', 0.77),
        ]);
    }

    /**
     * @return array<int, array{0: string, 1: bool}> tag_id => [source, locked]
     */
    private function links(Image $image): array
    {
        return ImageTag::where('image_id', $image->id)->orderBy('tag_id')->get()
            ->mapWithKeys(fn (ImageTag $l) => [$l->tag_id => [$l->source, $l->locked]])->all();
    }

    private function reprocess(Image $image): Image
    {
        app(ProcessingPipeline::class)->reprocess($image->id);
        $this->runQueuedJobs();

        return $image->refresh();
    }

    public function test_manual_decisions_and_suppressions_survive_reprocess(): void
    {
        $helmet = $this->makeTag('Каска');
        $this->makeAlias($helmet, 'protective helmet');
        $engineer = $this->makeTag('Инженер', 'person');
        $this->makeAlias($engineer, 'engineer');
        $winter = $this->makeTag('Зима', 'season');

        $image = $this->processedImage();

        $this->assertSame('ready', $image->status);
        $this->assertSame([$helmet->id => ['ai', false], $engineer->id => ['ai', false]], $this->links($image));
        $candidate = TagCandidate::where('normalized_name', 'drilling platform')->sole();
        $this->assertSame(['pending', 1], [$candidate->status, $candidate->occurrences_count]);

        // Admin: remove an AI tag (→ suppression), add a manual one, pin another AI one as manual.
        $editor = app(ImageTagEditor::class);
        $editor->remove($image->id, $helmet->id, $this->taxonomyUser()->id);
        $editor->add($image->id, $winter->id);
        $editor->add($image->id, $engineer->id);

        // The next AI run does not see the engineer at all.
        $this->ai->withConcepts([
            new Concept(ConceptType::Object, 'protective helmet', 0.99),
            new Concept(ConceptType::Object, 'Drilling Platform', 0.85),
        ]);
        $image = $this->reprocess($image);

        $this->assertSame('ready', $image->status);
        $this->assertSame([$engineer->id => ['manual', true], $winter->id => ['manual', true]], $this->links($image),
            'suppressed tag not returned, manual tags kept');
        $this->assertSame(2, ImageAnalysis::where('image_id', $image->id)->count(), 'analysis history kept');
        $this->assertSame(1, $candidate->refresh()->occurrences_count, 're-run of the same image does not inflate the counter');
        $this->assertSame(1, TagCandidateImage::count());
        $this->assertEqualsWithDelta(0.85, (float) TagCandidateImage::sole()->confidence, 1e-4);
    }

    public function test_approved_candidate_is_matched_by_the_next_run_without_a_new_candidate(): void
    {
        $image = $this->processedImage();
        $candidate = TagCandidate::where('normalized_name', 'drilling platform')->sole();

        $result = app(CandidateModeration::class)->approve($candidate->id, [], $this->taxonomyUser()->id);
        $tagId = $result['tag']->id;
        $this->assertSame([$tagId => ['ai', false]], array_intersect_key($this->links($image), [$tagId => true]));

        $image = $this->reprocess($image);
        $second = $this->processedImage();

        $this->assertArrayHasKey($tagId, $this->links($image), 'stage 1 now matches the approved tag');
        $this->assertArrayHasKey($tagId, $this->links($second));
        $candidate->refresh();
        $this->assertSame(['created', 1], [$candidate->status, $candidate->occurrences_count], 'no new detections recorded');
    }

    public function test_merge_alias_routes_next_detection_to_stage2(): void
    {
        $drill = $this->makeTag('Буровая');
        $this->processedImage();
        $candidate = TagCandidate::where('normalized_name', 'drilling platform')->sole();

        app(CandidateModeration::class)->merge($candidate->id, $drill->id, true, $this->taxonomyUser()->id);

        $next = $this->processedImage();

        $this->assertSame(['ai', false], $this->links($next)[$drill->id]);
        $this->assertSame(1, $candidate->refresh()->occurrences_count);
    }

    public function test_rejected_candidate_keeps_counting_but_is_not_requeued(): void
    {
        $this->processedImage();
        $candidate = TagCandidate::where('normalized_name', 'drilling platform')->sole();
        app(CandidateModeration::class)->reject($candidate->id, $this->taxonomyUser()->id);

        $this->processedImage();

        $candidate->refresh();
        $this->assertSame(['rejected', 2], [$candidate->status, $candidate->occurrences_count]);
    }

    public function test_ai_outage_during_stage3_is_retried_then_succeeds(): void
    {
        $this->scripted->failTextEmbeddingsWith(AiException::fromErrorCode('SERVICE_BUSY', 'busy', 503));

        $image = $this->processedImage();

        $this->assertSame('ready', $image->status);
        $task = ProcessingTask::where('image_id', $image->id)->where('type', 'tag_resolution')->sole();
        $this->assertSame(['succeeded', 2], [$task->status, $task->attempts]);
        $this->assertSame(1, TagCandidate::where('normalized_name', 'drilling platform')->sole()->occurrences_count);
    }

    public function test_embedding_version_mismatch_fails_the_image_after_retries(): void
    {
        $this->scripted->revision = 'other-rev';

        $image = $this->processedImage();

        $this->assertSame(['failed', 'TAG_RESOLUTION_FAILED'], [$image->status, $image->error_code]);
        $task = ProcessingTask::where('image_id', $image->id)->where('type', 'tag_resolution')->sole();
        $this->assertSame($task->max_attempts, $task->attempts);
        $this->assertSame(0, TagCandidate::count());
    }
}
