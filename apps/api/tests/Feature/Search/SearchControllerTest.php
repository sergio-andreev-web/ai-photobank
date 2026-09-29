<?php

namespace Tests\Feature\Search;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Models\Image;
use App\Models\ImageEmbedding;
use App\Models\ImageTag;
use App\Models\Tag;
use App\Services\Processing\EmbeddingVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * POST /api/search (ТЗ §42–§46, ADR-0004, ADR-0009) against deterministic
 * 1152-dim vectors: vector(axis, s) has cosine exactly `s` with the query
 * vector(axis, 1.0), so ranking and semantic_score are asserted exactly.
 *
 * Driver-agnostic: on sqlite the cosine runs in PHP, with
 * `DB_CONNECTION=pgsql` the same assertions run against pgvector.
 */
class SearchControllerTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const int AXIS = 10;

    private const string QUERY = 'рабочие в касках';

    private ScriptedEmbeddingsAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = new ScriptedEmbeddingsAiClient;
        $this->ai->embedAs(self::QUERY, ScriptedEmbeddingsAiClient::vector(self::AXIS));
        $this->app->instance(AiClientInterface::class, $this->ai);

        $this->loginAsAdmin();
    }

    private function search(array $body): TestResponse
    {
        return $this->postJson('/api/search', $body, $this->csrfHeaders());
    }

    /**
     * @param  list<Tag>  $tags
     */
    private function imageWith(array $tags = [], ?float $similarity = null, array $attributes = []): Image
    {
        $image = $this->makeImage($attributes);

        foreach ($tags as $tag) {
            ImageTag::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'source' => 'manual', 'locked' => true]);
        }

        if ($similarity !== null) {
            $this->embedImage($image, ScriptedEmbeddingsAiClient::vector(self::AXIS, $similarity));
        }

        return $image;
    }

    /**
     * @param  list<float>  $vector
     */
    private function embedImage(
        Image $image,
        array $vector,
        string $modelKey = 'siglip2-stub',
        string $modelVersion = 'fake-embedding-rev-1',
        bool $active = true,
    ): void {
        ImageEmbedding::create([
            'image_id' => $image->id,
            'model_key' => $modelKey,
            'model_version' => $modelVersion,
            'embedding' => EmbeddingVersion::toVectorLiteral($vector),
            'active' => $active,
        ]);
    }

    private function setCreatedAt(Image $image, string $at): void
    {
        DB::table('images')->where('id', $image->id)->update(['created_at' => $at]);
    }

    /**
     * @return list<int>
     */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'id');
    }

    // ---- access -----------------------------------------------------------------------------

    public function test_search_requires_authentication(): void
    {
        // Keeps the CSRF session token from setUp(), drops only the logged-in user.
        $this->app['auth']->forgetGuards();

        $this->search(['mode' => 'tags', 'tag_ids' => [1]])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_search_requires_csrf_token(): void
    {
        $tag = $this->makeTag('Каска');

        $this->postJson('/api/search', ['mode' => 'tags', 'tag_ids' => [$tag->id]])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    // ---- tags mode --------------------------------------------------------------------------

    public function test_tags_mode_requires_every_tag_and_sorts_newest_first_without_ai(): void
    {
        $helmet = $this->makeTag('Каска');
        $worker = $this->makeTag('Рабочий');
        $other = $this->makeTag('Лес', 'scene');

        $older = $this->imageWith([$helmet, $worker]);
        $newer = $this->imageWith([$helmet, $worker, $other]);
        $sameTimeHigherId = $this->imageWith([$helmet, $worker]);
        $this->imageWith([$helmet]);   // only one of the two tags
        $this->imageWith([$other]);

        $this->setCreatedAt($older, '2026-09-20 10:00:00');
        $this->setCreatedAt($newer, '2026-09-25 10:00:00');
        $this->setCreatedAt($sameTimeHigherId, '2026-09-25 10:00:00');

        $response = $this->search(['mode' => 'tags', 'tag_ids' => [$helmet->id, $worker->id]]);

        $response->assertOk()
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1, 'mode' => 'tags']);
        // created_at desc, then id desc (ADR-0004).
        $this->assertSame([$sameTimeHigherId->id, $newer->id, $older->id], $this->ids($response));
        $this->assertSame([], $this->ai->textCalls, 'tags mode must not call AI (§45)');
    }

    public function test_tags_mode_keeps_working_while_ai_is_down(): void
    {
        $tag = $this->makeTag('Каска');
        $image = $this->imageWith([$tag]);
        $this->ai->failTextEmbeddingsWith(AiException::unavailable('connection refused'));

        $response = $this->search(['mode' => 'tags', 'tag_ids' => [$tag->id]]);

        $response->assertOk();
        $this->assertSame([$image->id], $this->ids($response));
    }

    public function test_tags_mode_returns_any_status_but_not_deleted_images_and_accepts_archived_tags(): void
    {
        $archived = $this->makeTag('Старый тег', 'object', 'archived');
        $processing = $this->imageWith([$archived], attributes: ['status' => 'processing']);
        $failed = $this->imageWith([$archived], attributes: ['status' => 'failed']);
        $this->imageWith([$archived])->delete();

        $response = $this->search(['mode' => 'tags', 'tag_ids' => [$archived->id]]);

        $response->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$processing->id, $failed->id], $this->ids($response));
    }

    public function test_tags_mode_returns_image_summary_and_never_a_score(): void
    {
        $tag = $this->makeTag('Каска');
        $image = $this->imageWith([$tag], similarity: 0.9);

        $response = $this->search(['mode' => 'tags', 'tag_ids' => [$tag->id], 'debug' => true]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $image->id)
            ->assertJsonPath('data.0.batch_id', $image->batch_id)
            ->assertJsonPath('data.0.status', 'ready')
            ->assertJsonPath('data.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.0.urls.original', "/api/media/images/{$image->id}/original");
        $this->assertArrayNotHasKey('semantic_score', $response->json('data.0'));
        $this->assertArrayNotHasKey('storage_key', $response->json('data.0'));
    }

    public function test_tags_mode_paginates_and_an_out_of_range_page_is_empty(): void
    {
        $tag = $this->makeTag('Каска');
        $images = [];
        foreach (range(1, 5) as $i) {
            $images[] = $image = $this->imageWith([$tag]);
            $this->setCreatedAt($image, "2026-09-2{$i} 10:00:00");
        }

        $page2 = $this->search(['mode' => 'tags', 'tag_ids' => [$tag->id], 'page' => 2, 'per_page' => 2]);
        $page2->assertOk()->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'mode' => 'tags']);
        $this->assertSame([$images[2]->id, $images[1]->id], $this->ids($page2));

        $beyond = $this->search(['mode' => 'tags', 'tag_ids' => [$tag->id], 'page' => 9, 'per_page' => 2]);
        $beyond->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 5);
    }

    public function test_tags_mode_without_matches_is_an_empty_page(): void
    {
        $tag = $this->makeTag('Каска');
        $this->imageWith();

        $this->search(['mode' => 'tags', 'tag_ids' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 50, 'total' => 0, 'last_page' => 1, 'mode' => 'tags']);
    }

    // ---- semantic mode ----------------------------------------------------------------------

    public function test_semantic_mode_ranks_by_similarity_then_id_above_the_relevance_floor(): void
    {
        $low = $this->imageWith(similarity: 0.2);
        $best = $this->imageWith(similarity: 0.95);
        $tieA = $this->imageWith(similarity: 0.6);
        $tieB = $this->imageWith(similarity: 0.6);
        $opposite = $this->imageWith(similarity: -0.5);

        $response = $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'debug' => true]);

        // Floor = max(0.05, 0.5 × 0.95): the unrelated images are not returned (ADR-0004, 2026-09-30).
        $response->assertOk()
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1, 'mode' => 'semantic']);
        $this->assertSame([$best->id, $tieA->id, $tieB->id], $this->ids($response));
        $this->assertNotContains($low->id, $this->ids($response));
        $this->assertNotContains($opposite->id, $this->ids($response));

        $scores = array_column($response->json('data'), 'semantic_score');
        foreach ([0.95, 0.6, 0.6] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $scores[$i], 1e-5);
        }

        $this->assertSame([[self::QUERY]], $this->ai->textCalls);
    }

    public function test_word_matches_in_caption_ocr_or_tags_come_first_even_below_the_floor(): void
    {
        $this->ai->embedAs('жилищная инспекция', ScriptedEmbeddingsAiClient::vector(self::AXIS));
        $plate = $this->makeTag('Табличка');
        $close = $this->imageWith(similarity: 0.9);
        $byOcr = $this->imageWith(similarity: 0.06, attributes: ['ocr_text' => 'ГОСУДАРСТВЕННАЯ ЖИЛИЩНАЯ ИНСПЕКЦИЯ']);
        $byCaption = $this->imageWith(similarity: 0.3, attributes: ['caption' => 'Синяя табличка жилищной инспекции на фасаде.']);
        $byTag = $this->imageWith([$plate], 0.2, ['caption' => 'Вывеска у входа, жилищная служба.']);
        $unrelated = $this->imageWith(similarity: 0.1, attributes: ['caption' => 'Футболисты на поле.']);

        $response = $this->search(['mode' => 'semantic', 'query' => 'жилищная инспекция', 'debug' => true]);

        // Word matches first; with a match only near-best neighbours join (0.8 × 0.9), so the
        // close image stays. Every query word must match: the tag-only image lacks «инспекция».
        $response->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$byCaption->id, $byOcr->id, $close->id], $this->ids($response));
        $this->assertNotContains($byTag->id, $this->ids($response));
        $this->assertNotContains($unrelated->id, $this->ids($response));
        $this->assertEqualsWithDelta(0.3, $response->json('data.0.semantic_score'), 1e-5);
    }

    public function test_file_name_counts_as_words_of_the_image(): void
    {
        $this->ai->embedAs('выезд', ScriptedEmbeddingsAiClient::vector(self::AXIS));
        $named = $this->imageWith(similarity: 0.01, attributes: ['original_name' => 'выезд_на_объект_042.jpg']);
        $this->imageWith(similarity: 0.9);

        $ids = $this->ids($this->search(['mode' => 'semantic', 'query' => 'выезд'])->assertOk());

        $this->assertSame($named->id, $ids[0]);
    }

    public function test_tag_names_count_as_words_of_the_image(): void
    {
        $this->ai->embedAs('таблички', ScriptedEmbeddingsAiClient::vector(self::AXIS));
        $plate = $this->makeTag('Табличка');
        $tagged = $this->imageWith([$plate], 0.01);
        $this->imageWith(similarity: 0.9);

        $ids = $this->ids($this->search(['mode' => 'semantic', 'query' => 'таблички'])->assertOk());

        $this->assertSame($tagged->id, $ids[0]);
    }

    public function test_a_word_match_raises_the_floor_for_the_other_images(): void
    {
        $this->ai->embedAs('жилищная', ScriptedEmbeddingsAiClient::vector(self::AXIS));
        $plate = $this->imageWith(similarity: 0.06, attributes: ['ocr_text' => 'ЖИЛИЩНАЯ ИНСПЕКЦИЯ']);
        $nearBest = $this->imageWith(similarity: 0.1);
        $weaker = $this->imageWith(similarity: 0.07); // above 0.5 × 0.1, below 0.8 × 0.1

        $ids = $this->ids($this->search(['mode' => 'semantic', 'query' => 'жилищная'])->assertOk());

        $this->assertSame([$plate->id, $nearBest->id], $ids);
        $this->assertNotContains($weaker->id, $ids);
    }

    public function test_relevance_floor_comes_from_configuration(): void
    {
        config(['photobank.search.min_similarity' => 0.0, 'photobank.search.relative_similarity' => 0.0, 'photobank.search.relative_similarity_with_word_match' => 0.0]);
        $this->imageWith(similarity: 0.9);
        $this->imageWith(similarity: 0.01);

        $this->search(['mode' => 'semantic', 'query' => self::QUERY])->assertOk()->assertJsonPath('meta.total', 2);

        config(['photobank.search.min_similarity' => 0.95]);
        $this->search(['mode' => 'semantic', 'query' => self::QUERY])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_semantic_score_is_only_returned_in_debug(): void
    {
        $this->imageWith(similarity: 0.9);

        $response = $this->search(['mode' => 'semantic', 'query' => self::QUERY]);

        $response->assertOk();
        $this->assertArrayNotHasKey('semantic_score', $response->json('data.0'));
    }

    public function test_identical_vector_scores_exactly_one_at_most(): void
    {
        $this->imageWith(similarity: 1.0);

        $score = $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'debug' => true])
            ->assertOk()
            ->json('data.0.semantic_score');

        $this->assertEqualsWithDelta(1.0, $score, 1e-6);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_semantic_mode_only_ranks_the_active_embedding_version(): void
    {
        $current = $this->imageWith(similarity: 0.3);

        // Superseded row of the same image: inactive, would otherwise rank first.
        $this->embedImage($current, ScriptedEmbeddingsAiClient::vector(self::AXIS, 0.99), modelVersion: 'fake-embedding-rev-0', active: false);

        // Image whose only embedding is an old inactive version.
        $this->embedImage($this->imageWith(), ScriptedEmbeddingsAiClient::vector(self::AXIS, 0.99), modelVersion: 'fake-embedding-rev-0', active: false);

        // Still flagged active, but from another model (config switched, not yet reprocessed).
        $this->embedImage($this->imageWith(), ScriptedEmbeddingsAiClient::vector(self::AXIS, 0.98), modelKey: 'other-model', modelVersion: 'other-rev');

        // No embedding at all (not analysed yet), and a deleted image.
        $this->imageWith();
        $this->imageWith(similarity: 0.97)->delete();

        $response = $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'debug' => true]);

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$current->id], $this->ids($response));
        $this->assertEqualsWithDelta(0.3, $response->json('data.0.semantic_score'), 1e-5);
    }

    public function test_semantic_mode_paginates_the_ranking(): void
    {
        $ranked = [];
        foreach ([0.9, 0.8, 0.7, 0.6, 0.5] as $similarity) {
            $ranked[] = $this->imageWith(similarity: $similarity)->id;
        }

        $page2 = $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'page' => 2, 'per_page' => 2]);
        $page2->assertOk()->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'mode' => 'semantic']);
        $this->assertSame([$ranked[2], $ranked[3]], $this->ids($page2));

        $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'page' => 4, 'per_page' => 2])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 5);
    }

    public function test_semantic_mode_without_embeddings_is_an_empty_page(): void
    {
        $this->imageWith();

        $this->search(['mode' => 'semantic', 'query' => self::QUERY])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 50, 'total' => 0, 'last_page' => 1, 'mode' => 'semantic']);
    }

    // ---- hybrid mode ------------------------------------------------------------------------

    public function test_hybrid_tags_are_a_hard_filter_and_query_only_ranks(): void
    {
        $helmet = $this->makeTag('Каска');
        $factory = $this->makeTag('Завод', 'scene');

        $bestWithoutTags = $this->imageWith([], 0.99);
        $onlyOneTag = $this->imageWith([$helmet], 0.98);
        $weak = $this->imageWith([$helmet, $factory], 0.4);
        $strong = $this->imageWith([$helmet, $factory], 0.7);
        $this->imageWith([$helmet, $factory]); // matches the tags but has no embedding

        $response = $this->search([
            'mode' => 'hybrid',
            'query' => self::QUERY,
            'tag_ids' => [$helmet->id, $factory->id],
            'debug' => true,
        ]);

        $response->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.mode', 'hybrid');
        $this->assertSame([$strong->id, $weak->id], $this->ids($response));
        $this->assertNotContains($bestWithoutTags->id, $this->ids($response));
        $this->assertNotContains($onlyOneTag->id, $this->ids($response));
        $this->assertEqualsWithDelta(0.7, $response->json('data.0.semantic_score'), 1e-5);
    }

    public function test_hybrid_filter_is_applied_inside_the_ranking_not_after_top_k(): void
    {
        $tag = $this->makeTag('Редкий тег');

        // Far more untagged images closer to the query than an ANN top-K (ef_search = 40) holds.
        foreach (range(1, 60) as $i) {
            $this->imageWith([], 0.9 + $i / 1000);
        }
        $tagged = $this->imageWith([$tag], 0.05);

        $response = $this->search(['mode' => 'hybrid', 'query' => self::QUERY, 'tag_ids' => [$tag->id]]);

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$tagged->id], $this->ids($response));
    }

    public function test_hybrid_without_matching_images_is_an_empty_page(): void
    {
        $tag = $this->makeTag('Каска');
        $this->imageWith([], 0.9);

        $this->search(['mode' => 'hybrid', 'query' => self::QUERY, 'tag_ids' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);
    }

    // ---- AI failures ------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function aiModes(): array
    {
        return ['semantic' => ['semantic'], 'hybrid' => ['hybrid']];
    }

    private function aiBody(string $mode): array
    {
        $tag = $this->makeTag('Каска');
        $this->imageWith([$tag], 0.9);

        return $mode === 'semantic'
            ? ['mode' => 'semantic', 'query' => self::QUERY]
            : ['mode' => 'hybrid', 'query' => self::QUERY, 'tag_ids' => [$tag->id]];
    }

    #[DataProvider('aiModes')]
    public function test_ai_unreachable_returns_503_ai_service_unavailable(string $mode): void
    {
        $body = $this->aiBody($mode);
        $this->ai->failTextEmbeddingsWith(AiException::unavailable('connection refused'));

        $response = $this->search($body);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.trace_id', $response->headers->get('X-Request-ID'));
        $this->assertFalse($response->headers->has('Retry-After'));
        $this->assertIsArray($response->json('error.details'));
    }

    #[DataProvider('aiModes')]
    public function test_ai_not_ready_forwards_retry_after(string $mode): void
    {
        $body = $this->aiBody($mode);
        $this->ai->failTextEmbeddingsWith(AiException::fromErrorCode('MODEL_NOT_READY', 'Loading', 503, retryAfter: 30));

        $this->search($body)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE')
            ->assertHeader('Retry-After', '30');
    }

    public function test_ai_rejection_and_invalid_ai_response_are_also_503(): void
    {
        $body = $this->aiBody('semantic');
        $this->ai->failTextEmbeddingsWith(
            AiException::fromErrorCode('UNAUTHORIZED', 'Bad key', 401),
            new InvalidAiResponseException(['/embeddings/0: expected 1152 items']),
        );

        $this->search($body)->assertStatus(503)->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
        $this->search($body)->assertStatus(503)->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
    }

    #[DataProvider('aiModes')]
    public function test_text_embedding_of_another_version_is_never_ranked(string $mode): void
    {
        $body = $this->aiBody($mode);
        $this->ai->revision = 'fake-embedding-rev-2';

        $this->search($body)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'EMBEDDING_VERSION_MISMATCH');
    }

    public function test_text_embedding_of_another_model_is_never_ranked(): void
    {
        $body = $this->aiBody('semantic');
        $this->ai->model = 'google/siglip2-so400m-patch14-384';

        $this->search($body)->assertStatus(503)->assertJsonPath('error.code', 'EMBEDDING_VERSION_MISMATCH');
    }

    public function test_search_passes_its_trace_id_to_ai(): void
    {
        $response = $this->search($this->aiBody('semantic'))->assertOk();

        $this->assertSame([$response->headers->get('X-Request-ID')], $this->ai->requestIds);
    }

    // ---- validation -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'mode missing' => [['query' => 'каска'], 'mode'],
            'mode unknown' => [['mode' => 'fuzzy', 'query' => 'каска'], 'mode'],
            'mode not a string' => [['mode' => 1, 'query' => 'каска'], 'mode'],
            'tags without tag_ids' => [['mode' => 'tags'], 'tag_ids'],
            'tags with query' => [['mode' => 'tags', 'tag_ids' => [1], 'query' => 'каска'], 'query'],
            'tags with empty query' => [['mode' => 'tags', 'tag_ids' => [1], 'query' => ''], 'query'],
            'tags with null query' => [['mode' => 'tags', 'tag_ids' => [1], 'query' => null], 'query'],
            'semantic without query' => [['mode' => 'semantic'], 'query'],
            'semantic with tag_ids' => [['mode' => 'semantic', 'query' => 'каска', 'tag_ids' => [1]], 'tag_ids'],
            'hybrid without tag_ids' => [['mode' => 'hybrid', 'query' => 'каска'], 'tag_ids'],
            'hybrid without query' => [['mode' => 'hybrid', 'tag_ids' => [1]], 'query'],
            'empty tag_ids' => [['mode' => 'tags', 'tag_ids' => []], 'tag_ids'],
            'tag_ids object' => [['mode' => 'tags', 'tag_ids' => ['a' => 1]], 'tag_ids'],
            'duplicate tag_ids' => [['mode' => 'tags', 'tag_ids' => [1, 1]], 'tag_ids.0'],
            'too many tag_ids' => [['mode' => 'tags', 'tag_ids' => range(1, 21)], 'tag_ids'],
            'string tag id' => [['mode' => 'tags', 'tag_ids' => ['1']], 'tag_ids.0'],
            'zero tag id' => [['mode' => 'tags', 'tag_ids' => [0]], 'tag_ids.0'],
            'whitespace query' => [['mode' => 'semantic', 'query' => "  \t\n "], 'query'],
            'query too long' => [['mode' => 'semantic', 'query' => str_repeat('я', 1001)], 'query'],
            'query not a string' => [['mode' => 'semantic', 'query' => ['каска']], 'query'],
            'page zero' => [['mode' => 'semantic', 'query' => 'каска', 'page' => 0], 'page'],
            'page as string' => [['mode' => 'semantic', 'query' => 'каска', 'page' => '1'], 'page'],
            'per_page over 100' => [['mode' => 'semantic', 'query' => 'каска', 'per_page' => 101], 'per_page'],
            'debug as string' => [['mode' => 'semantic', 'query' => 'каска', 'debug' => 'true'], 'debug'],
            'unknown field' => [['mode' => 'semantic', 'query' => 'каска', 'sort' => 'id'], '_unexpected'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_body_is_422_before_ai_is_called(array $body, string $field): void
    {
        $this->makeTag('Каска');

        $response = $this->search($body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey($field, $response->json('error.details.fields'));
        $this->assertSame([], $this->ai->textCalls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tagFilterModes(): array
    {
        return ['tags' => ['tags'], 'hybrid' => ['hybrid']];
    }

    #[DataProvider('tagFilterModes')]
    public function test_unknown_tag_id_is_a_validation_error_on_tag_ids(string $mode): void
    {
        $tag = $this->makeTag('Каска');
        $body = ['mode' => $mode, 'tag_ids' => [$tag->id, 999999]];
        if ($mode !== 'tags') {
            $body['query'] = self::QUERY;
        }

        $this->search($body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['tag_ids']]]]);
        $this->assertSame([], $this->ai->textCalls);
    }

    public function test_query_of_exactly_1000_characters_is_accepted(): void
    {
        $query = str_repeat('я', 1000);

        $this->search(['mode' => 'semantic', 'query' => $query])->assertOk();
        $this->assertSame([[$query]], $this->ai->textCalls);
    }
}
