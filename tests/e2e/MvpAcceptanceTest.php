<?php

namespace Tests\E2E;

use App\Contracts\Ai\Data\Concept;
use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Tagging\TagResolverInterface;
use App\Models\ImageAnalysis;
use App\Models\ImageTagSuppression;
use App\Models\User;
use App\Services\Tagging\TagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Concerns\RunsProcessingQueue;
use Tests\TestCase;

/**
 * Public HTTP -> storage -> queued stages -> real resolver/search, with only
 * the AI boundary replaced by FakeAiClient (§75, §76, contracts 1.2.0).
 * The queue is drained explicitly so upload's asynchronous boundary is tested.
 */
class MvpAcceptanceTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;
    use RunsProcessingQueue;

    private const string QUERY = 'мужчина в каске на промышленном объекте';

    protected function setUp(): void
    {
        parent::setUp();
        // Two analyses can be written inside one database timestamp tick.
        // Keep that case deterministic so latest_analysis must break ties by ID.
        Carbon::setTestNow('2026-09-25 12:00:00');

        // RunsProcessingQueue installs a stub resolver for pipeline unit tests.
        // This suite exercises the production resolver with deterministic AI.
        $this->app->forgetInstance(TagResolverInterface::class);
        $this->app->bind(TagResolverInterface::class, TagResolver::class);
        $this->ai->withConcepts([
            new Concept(ConceptType::Object, 'protective helmet', 0.93),
            new Concept(ConceptType::Object, 'drilling platform', 0.81),
            new Concept(ConceptType::Activity, 'equipment inspection', 0.78),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_section_75_from_login_through_moderation_and_ranked_search(): void
    {
        $user = User::create([
            'name' => 'QA admin',
            'email' => 'qa-admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
        // Login rotates the session token; use a known token for later test requests.
        $this->withSession(['_token' => $this->csrfToken()]);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('role', 'admin');

        $helmet = $this->createTag('Каска', 'object');
        $this->postJson("/api/admin/tags/{$helmet}/aliases", ['alias' => 'protective helmet'], $this->csrfHeaders())
            ->assertCreated();
        $winter = $this->createTag('Зима', 'season');

        $batch = $this->postJson('/api/admin/batches', ['name' => 'QA §75'], $this->csrfHeaders())
            ->assertCreated()->assertJsonPath('status', 'created');
        $batchId = $batch->json('id');
        $imageIds = [];
        $firstBytes = null;
        for ($index = 0; $index < 20; $index++) {
            $file = UploadedFile::fake()->image("qa-{$index}.jpg", 32 + $index, 24);
            $firstBytes ??= file_get_contents($file->getRealPath());
            $upload = $this->post("/api/admin/batches/{$batchId}/images", ['image' => $file], $this->csrfHeaders());
            $upload->assertCreated()->assertJsonPath('status', 'uploaded')->assertJsonPath('batch_id', $batchId);
            $upload->assertHeader('Location', "/api/admin/images/{$upload->json('id')}");
            $imageIds[] = $upload->json('id');
        }
        $this->assertCount(20, array_unique($imageIds));

        $duplicate = UploadedFile::fake()->createWithContent('same-bytes.jpg', $firstBytes);
        $this->post("/api/admin/batches/{$batchId}/images", ['image' => $duplicate], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'IMAGE_ALREADY_EXISTS')
            ->assertJsonPath('error.details.existing_image_id', $imageIds[0]);
        $this->getJson("/api/admin/batches/{$batchId}")->assertOk()
            ->assertJsonPath('progress.total', 20)->assertJsonPath('progress.processed', 0);
        $this->postJson("/api/admin/batches/{$batchId}/complete", [], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('status', 'processing');

        $this->assertSame(100, $this->runQueuedJobs(), '20 uploads × 5 pipeline stages');
        $this->getJson("/api/admin/batches/{$batchId}")->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('progress.total', 20)
            ->assertJsonPath('progress.processed', 20)->assertJsonPath('progress.failed', 0);

        $imageId = $imageIds[0];
        $detail = $this->getJson("/api/admin/images/{$imageId}")->assertOk()
            ->assertJsonPath('status', 'ready');
        $this->assertNotEmpty($detail->json('caption'));
        $this->assertNotEmpty($detail->json('latest_analysis.concepts'));
        $this->assertSame('ai', $this->tagLink($detail, $helmet)['source']);
        $oldAnalysisId = $detail->json('latest_analysis.id');

        $this->deleteJson("/api/admin/images/{$imageId}/tags/{$helmet}", [], $this->csrfHeaders())
            ->assertNoContent();
        $this->assertTrue(ImageTagSuppression::where('image_id', $imageId)->where('tag_id', $helmet)->exists());
        $this->postJson("/api/admin/images/{$imageId}/tags", ['tag_id' => $winter], $this->csrfHeaders())
            ->assertCreated()->assertJsonPath('source', 'manual')->assertJsonPath('locked', true);

        $candidates = $this->getJson('/api/admin/tag-candidates')->assertOk();
        $candidate = collect($candidates->json('data'))->firstWhere('normalized_name', 'drilling platform');
        $this->assertNotNull($candidate, 'Unknown AI concept must reach moderation');
        $this->assertSame(20, $candidate['occurrences_count']);
        $approved = $this->postJson("/api/admin/tag-candidates/{$candidate['id']}/approve", [], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('candidate.status', 'created');
        $this->assertSame(20, $approved->json('attached_images_count'));
        $inspectionCandidate = collect($candidates->json('data'))->firstWhere('normalized_name', 'equipment inspection');
        $this->assertNotNull($inspectionCandidate);
        $inspection = $this->createTag('Осмотр', 'activity');
        $this->postJson("/api/admin/tag-candidates/{$inspectionCandidate['id']}/merge",
            ['tag_id' => $inspection], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('candidate.status', 'merged')
            ->assertJsonPath('attached_images_count', 20)->assertJsonPath('alias_created', true);

        $tagSearch = $this->search(['mode' => 'tags', 'tag_ids' => [$winter]])->assertOk();
        $tagSearch->assertJsonPath('meta.total', 1)->assertJsonPath('meta.mode', 'tags');
        $this->assertSame([$imageId], array_column($tagSearch->json('data'), 'id'));

        $this->postJson("/api/admin/images/{$imageId}/reprocess", [], $this->csrfHeaders())
            ->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertSame(3, $this->runQueuedJobs(), 'reprocess runs AI, tag resolution, finalize');
        $after = $this->getJson("/api/admin/images/{$imageId}")->assertOk()
            ->assertJsonPath('status', 'ready');
        $this->assertSame(2, ImageAnalysis::where('image_id', $imageId)->count());
        $this->assertNull($this->tagLink($after, $helmet), 'suppressed AI tag must stay absent');
        $this->assertSame('manual', $this->tagLink($after, $winter)['source']);
        $this->assertTrue($this->tagLink($after, $winter)['locked']);
        $this->assertSame('ai', $this->tagLink($after, $inspection)['source']);
        $this->assertTrue(ImageTagSuppression::where('image_id', $imageId)->where('tag_id', $helmet)->exists());

        $semantic = $this->search(['mode' => 'semantic', 'query' => self::QUERY, 'debug' => true])->assertOk();
        $semantic->assertJsonPath('meta.total', 20)->assertJsonPath('meta.mode', 'semantic');
        $scores = array_column($semantic->json('data'), 'semantic_score');
        $this->assertCount(20, $scores);
        $sorted = $scores;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $scores, 'semantic results are sorted by similarity');
        $hybrid = $this->search(['mode' => 'hybrid', 'query' => self::QUERY, 'tag_ids' => [$winter]])->assertOk();
        $hybrid->assertJsonPath('meta.total', 1);
        $this->assertSame([$imageId], array_column($hybrid->json('data'), 'id'), 'tag filter is hard');

        $newestAnalysisId = ImageAnalysis::where('image_id', $imageId)->max('id');
        $this->assertGreaterThan($oldAnalysisId, $newestAnalysisId);
        $this->assertSame($newestAnalysisId, $after->json('latest_analysis.id'),
            'image detail must return the newest analysis even when timestamps match');
    }

    public function test_ai_outage_keeps_upload_and_tag_search_available_then_retry_recovers(): void
    {
        $this->loginAsAdmin();
        $tagId = $this->createTag('Ручной тег', 'object');
        $batchId = $this->postJson('/api/admin/batches', [], $this->csrfHeaders())->assertCreated()->json('id');
        $this->ai->failAnalyzeWith(...array_fill(0, 4, AiException::unavailable('mock AI offline')));

        $imageId = $this->post("/api/admin/batches/{$batchId}/images", [
            'image' => UploadedFile::fake()->image('outage.jpg', 40, 30),
        ], $this->csrfHeaders())->assertCreated()->assertJsonPath('status', 'uploaded')->json('id');
        $this->postJson("/api/admin/images/{$imageId}/tags", ['tag_id' => $tagId], $this->csrfHeaders())
            ->assertCreated();
        $this->postJson("/api/admin/batches/{$batchId}/complete", [], $this->csrfHeaders())->assertOk();

        $this->runQueuedJobs();
        $this->getJson("/api/admin/images/{$imageId}")->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'AI_SERVICE_UNAVAILABLE');
        $this->getJson("/api/admin/batches/{$batchId}")->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('progress.failed', 1);

        $this->ai->failTextEmbeddingsWith(AiException::unavailable('mock AI offline'));
        $this->search(['mode' => 'tags', 'tag_ids' => [$tagId]])->assertOk()
            ->assertJsonPath('meta.total', 1);
        $semantic = $this->search(['mode' => 'semantic', 'query' => self::QUERY])
            ->assertStatus(503)->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
        $this->assertSame($semantic->headers->get('X-Request-ID'), $semantic->json('error.trace_id'));

        $this->postJson("/api/admin/images/{$imageId}/retry", [], $this->csrfHeaders())
            ->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->runQueuedJobs();
        $this->getJson("/api/admin/images/{$imageId}")->assertOk()->assertJsonPath('status', 'ready');
        $this->getJson("/api/admin/batches/{$batchId}")->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('progress.failed', 0);
        $this->assertSame(1, ImageAnalysis::where('image_id', $imageId)->count());
        $this->postJson("/api/admin/images/{$imageId}/retry", [], $this->csrfHeaders())
            ->assertStatus(409)->assertJsonPath('error.code', 'IMAGE_NOT_RETRYABLE');
    }

    private function createTag(string $name, string $type): int
    {
        return $this->postJson('/api/admin/tags', ['name' => $name, 'type' => $type], $this->csrfHeaders())
            ->assertCreated()->json('id');
    }

    private function search(array $body): TestResponse
    {
        return $this->postJson('/api/search', $body, $this->csrfHeaders());
    }

    private function tagLink(TestResponse $detail, int $tagId): ?array
    {
        return collect($detail->json('image_tags'))->first(fn (array $link): bool => $link['tag']['id'] === $tagId);
    }
}
