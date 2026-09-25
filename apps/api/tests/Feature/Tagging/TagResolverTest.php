<?php

namespace Tests\Feature\Tagging;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Tagging\ResolvedTag;
use App\Models\Image;
use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use App\Services\Processing\StageFailure;
use App\Services\Tagging\TagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * TagResolver stages 1–3 and candidate upsert (ТЗ §35–§39; ADR-0007,
 * ADR-0008, ADR-0009; state-machines.md "Tag candidate", "Tag").
 */
class TagResolverTest extends TestCase
{
    use BuildsTaxonomy;
    use RefreshDatabase;

    private ScriptedEmbeddingsAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = new ScriptedEmbeddingsAiClient;
        $this->app->instance(AiClientInterface::class, $this->ai);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float}>  $concepts
     * @return array<int, float|null> tag_id => confidence
     */
    private function resolve(Image $image, array $concepts): array
    {
        $result = app(TagResolver::class)->resolve($image, $this->makeAnalysis($image, $concepts));

        $map = [];
        foreach ($result as $tag) {
            $this->assertInstanceOf(ResolvedTag::class, $tag);
            $map[$tag->tagId] = $tag->confidence;
        }

        return $map;
    }

    public function test_stage1_exact_match_is_normalized_and_needs_no_ai(): void
    {
        $helmet = $this->makeTag('Protective Helmet');

        $result = $this->resolve($this->makeImage(), [['object', "  PROTECTIVE\u{00A0} helmet ", 0.91]]);

        $this->assertSame([$helmet->id => 0.91], $result);
        $this->assertSame([], $this->ai->textCalls, 'stage 1 must not call AI');
        $this->assertSame(0, TagCandidate::count());
    }

    public function test_stage1_prefers_tag_of_the_concept_type(): void
    {
        $keyObject = $this->makeTag('ключ', 'object');
        $keyConcept = $this->makeTag('ключ', 'concept');

        $this->assertSame([$keyConcept->id => 0.7], $this->resolve($this->makeImage(), [['concept', 'Ключ', 0.7]]));
        $this->assertSame([$keyObject->id => 0.6], $this->resolve($this->makeImage(), [['object', 'ключ', 0.6]]));
    }

    public function test_stage1_single_match_of_another_type_is_assigned(): void
    {
        $winter = $this->makeTag('winter', 'season');

        $this->assertSame([$winter->id => 0.8], $this->resolve($this->makeImage(), [['weather', 'Winter', 0.8]]));
    }

    public function test_stage1_ambiguous_other_types_create_candidate_with_closest_by_embedding(): void
    {
        $object = $this->makeTag('bank', 'object');
        $location = $this->makeTag('bank', 'location');
        $this->embedTag($object, ScriptedEmbeddingsAiClient::vector(1));
        $this->embedTag($location, ScriptedEmbeddingsAiClient::vector(2));
        $this->ai->embedAs('bank', ScriptedEmbeddingsAiClient::vector(2, 0.6));

        $image = $this->makeImage();
        $result = $this->resolve($image, [['concept', 'Bank', 0.66]]);

        $this->assertSame([], $result, 'ambiguous name is not assigned');
        $candidate = TagCandidate::sole();
        $this->assertSame(['bank', 'Bank', 'concept', 'pending', 1, $location->id],
            [$candidate->normalized_name, $candidate->display_name, $candidate->type, $candidate->status, $candidate->occurrences_count, $candidate->closest_tag_id]);
        $this->assertEqualsWithDelta(0.6, (float) $candidate->similarity, 1e-5);
    }

    public function test_stage2_alias_match_assigns_canonical_tag(): void
    {
        $car = $this->makeTag('Автомобиль');
        $this->makeAlias($car, 'Car');

        $this->assertSame([$car->id => 0.9], $this->resolve($this->makeImage(), [['object', 'car', 0.9]]));
        $this->assertSame([], $this->ai->textCalls);
    }

    public function test_archived_match_on_stage1_or_stage2_drops_the_concept(): void
    {
        $this->makeTag('Old factory', 'scene', 'archived');
        $archived = $this->makeTag('Телега', 'object', 'archived');
        $this->makeAlias($archived, 'cart');

        $result = $this->resolve($this->makeImage(), [['scene', 'old factory', 0.9], ['object', 'Cart', 0.9]]);

        $this->assertSame([], $result);
        $this->assertSame(0, TagCandidate::count(), 'no candidate for an archived match');
        $this->assertSame([], $this->ai->textCalls);
    }

    public function test_stage3_thresholds_assign_offer_closest_or_create_new_candidate(): void
    {
        $helmet = $this->makeTag('Каска');
        $drill = $this->makeTag('Буровая');
        $this->embedTag($helmet, ScriptedEmbeddingsAiClient::vector(1));
        $this->embedTag($drill, ScriptedEmbeddingsAiClient::vector(2));

        $this->ai->embedAs('hard hat', ScriptedEmbeddingsAiClient::vector(1, 0.91));         // ≥ 0.82
        $this->ai->embedAs('drilling platform', ScriptedEmbeddingsAiClient::vector(2, 0.71)); // [0.68, 0.82)
        $this->ai->embedAs('seagull', ScriptedEmbeddingsAiClient::vector(2, 0.5));            // < 0.68

        $image = $this->makeImage();
        $result = $this->resolve($image, [
            ['object', 'Hard hat', 0.8],
            ['object', 'drilling platform', 0.88],
            ['object', 'seagull', 0.4],
        ]);

        $this->assertSame([$helmet->id => 0.8], $result);
        $this->assertSame([['hard hat', 'drilling platform', 'seagull']], $this->ai->textCalls, 'one batched AI call');

        $platform = TagCandidate::where('normalized_name', 'drilling platform')->sole();
        $this->assertSame($drill->id, $platform->closest_tag_id);
        $this->assertEqualsWithDelta(0.71, (float) $platform->similarity, 1e-5);

        $gull = TagCandidate::where('normalized_name', 'seagull')->sole();
        $this->assertNull($gull->closest_tag_id);
        $this->assertNull($gull->similarity);
        $this->assertSame(0, TagCandidate::where('normalized_name', 'hard hat')->count(), 'auto-attach makes no candidate');

        $this->assertEqualsWithDelta(0.88, (float) TagCandidateImage::where('candidate_id', $platform->id)->sole()->confidence, 1e-4);
    }

    public function test_thresholds_come_from_configuration(): void
    {
        config(['photobank.tagging.auto_attach_threshold' => 0.7]);
        $drill = $this->makeTag('Буровая');
        $this->embedTag($drill, ScriptedEmbeddingsAiClient::vector(2));
        $this->ai->embedAs('drilling platform', ScriptedEmbeddingsAiClient::vector(2, 0.71));

        $this->assertSame([$drill->id => 0.5], $this->resolve($this->makeImage(), [['object', 'drilling platform', 0.5]]));
    }

    public function test_stage3_ignores_archived_tags_and_other_embedding_versions(): void
    {
        $archived = $this->makeTag('Каска', 'object', 'archived');
        $otherVersion = $this->makeTag('Шлем');
        $this->embedTag($archived, ScriptedEmbeddingsAiClient::vector(1));
        $this->embedTag($otherVersion, ScriptedEmbeddingsAiClient::vector(1), modelVersion: 'old-rev');
        $this->ai->embedAs('hard hat', ScriptedEmbeddingsAiClient::vector(1, 0.99));

        $result = $this->resolve($this->makeImage(), [['object', 'hard hat', 0.8]]);

        $this->assertSame([], $result);
        $candidate = TagCandidate::sole();
        $this->assertNull($candidate->closest_tag_id, 'no cross-version or archived closest tag');
    }

    public function test_stage3_rejects_inactive_embedding_version_as_retryable_failure(): void
    {
        $this->ai->revision = 'unexpected-rev';

        try {
            $this->resolve($this->makeImage(), [['object', 'seagull', 0.4]]);
            $this->fail('StageFailure expected');
        } catch (StageFailure $e) {
            $this->assertSame('TAG_RESOLUTION_FAILED', $e->errorCode);
            $this->assertTrue($e->retryable);
        }

        $this->assertSame(0, TagCandidate::count(), 'nothing recorded from a mismatched version');
    }

    public function test_ai_unavailable_propagates_for_the_pipeline_to_retry(): void
    {
        $this->ai->failTextEmbeddingsWith(AiException::fromErrorCode('SERVICE_BUSY', 'busy', 503));

        $this->expectException(AiException::class);
        $this->resolve($this->makeImage(), [['object', 'seagull', 0.4]]);
    }

    public function test_repeated_resolution_is_idempotent_and_counts_distinct_images(): void
    {
        $first = $this->makeImage();
        $second = $this->makeImage();
        $concepts = [['object', 'Drilling platform', 0.7], ['object', 'drilling  PLATFORM', 0.9]];

        $this->resolve($first, $concepts);
        $this->resolve($first, $concepts);

        $candidate = TagCandidate::sole();
        $this->assertSame(1, $candidate->occurrences_count);
        $this->assertSame('Drilling platform', $candidate->display_name, 'first label is kept');
        $this->assertEqualsWithDelta(0.9, (float) TagCandidateImage::sole()->confidence, 1e-4, 'duplicates in one analysis keep max confidence');

        $this->resolve($second, [['object', 'drilling platform', 0.5]]);

        $this->assertSame(2, $candidate->refresh()->occurrences_count);
        $this->assertSame(2, TagCandidateImage::where('candidate_id', $candidate->id)->count());
    }

    public function test_same_name_different_type_is_a_different_candidate(): void
    {
        $image = $this->makeImage();

        $this->resolve($image, [['object', 'crane', 0.7], ['activity', 'crane', 0.6]]);

        $this->assertEqualsCanonicalizing(['object', 'activity'], TagCandidate::pluck('type')->all());
    }

    public function test_rejected_candidate_stays_rejected_but_keeps_counting(): void
    {
        $this->resolve($this->makeImage(), [['object', 'seagull', 0.4]]);
        TagCandidate::query()->update(['status' => 'rejected']);

        $this->resolve($this->makeImage(), [['object', 'seagull', 0.4]]);

        $candidate = TagCandidate::sole();
        $this->assertSame(['rejected', 2], [$candidate->status, $candidate->occurrences_count]);
    }

    public function test_closest_tag_keeps_best_similarity_seen(): void
    {
        $drill = $this->makeTag('Буровая');
        $rig = $this->makeTag('Вышка');
        $this->embedTag($drill, ScriptedEmbeddingsAiClient::vector(2));
        $this->embedTag($rig, ScriptedEmbeddingsAiClient::vector(3));

        $this->ai->embedAs('drilling platform', ScriptedEmbeddingsAiClient::vector(2, 0.75));
        $this->resolve($this->makeImage(), [['object', 'drilling platform', 0.7]]);

        $this->ai->embedAs('drilling platform', ScriptedEmbeddingsAiClient::vector(3, 0.70));
        $this->resolve($this->makeImage(), [['object', 'drilling platform', 0.7]]);

        $candidate = TagCandidate::sole();
        $this->assertSame($drill->id, $candidate->closest_tag_id);
        $this->assertEqualsWithDelta(0.75, (float) $candidate->similarity, 1e-5);
    }

    public function test_invalid_concepts_are_skipped(): void
    {
        $helmet = $this->makeTag('helmet');
        $image = $this->makeImage();
        $analysis = $this->makeAnalysis($image, [['object', 'helmet', 0.9]]);
        $analysis->concepts = [
            ['type' => 'object', 'label' => 'helmet', 'confidence' => 0.9],
            ['type' => 'unknown-type', 'label' => 'x', 'confidence' => 0.9],
            ['type' => 'object', 'label' => " \u{00A0} ", 'confidence' => 0.9],
            'garbage',
        ];
        $analysis->save();

        $result = app(TagResolver::class)->resolve($image, $analysis);

        $this->assertEquals([new ResolvedTag($helmet->id, 0.9)], $result);
        $this->assertSame(0, TagCandidate::count());
    }
}
