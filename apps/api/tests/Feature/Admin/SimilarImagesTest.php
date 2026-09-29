<?php

namespace Tests\Feature\Admin;

use App\Contracts\Ai\AiClientInterface;
use App\Models\Image;
use App\Models\ImageEmbedding;
use App\Services\Processing\EmbeddingVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * GET /api/admin/images/{image}/similar (ADR-0012): vector(axis, s) has cosine exactly `s`
 * with vector(axis, 1.0), the source image's embedding.
 */
class SimilarImagesTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const int AXIS = 10;

    private ScriptedEmbeddingsAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new ScriptedEmbeddingsAiClient;
        $this->app->instance(AiClientInterface::class, $this->ai);
        $this->loginAsAdmin();
    }

    private function imageAt(?float $similarity, array $attributes = [], string $version = 'fake-embedding-rev-1'): Image
    {
        $image = $this->makeImage($attributes);
        if ($similarity !== null) {
            ImageEmbedding::create([
                'image_id' => $image->id,
                'model_key' => 'siglip2-stub',
                'model_version' => $version,
                'embedding' => EmbeddingVersion::toVectorLiteral(ScriptedEmbeddingsAiClient::vector(self::AXIS, $similarity)),
                'active' => true,
            ]);
        }

        return $image;
    }

    public function test_returns_neighbours_above_the_threshold_best_first_without_the_image_itself(): void
    {
        $source = $this->imageAt(1.0);
        $series = $this->imageAt(0.91);
        $sameSubject = $this->imageAt(0.73);
        $unrelated = $this->imageAt(0.6);
        $this->imageAt(0.95)->delete();
        $this->imageAt(0.99, version: 'old-rev');
        $this->imageAt(null); // not processed yet

        $response = $this->getJson("/api/admin/images/{$source->id}/similar")->assertOk();

        $this->assertSame([$series->id, $sameSubject->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('meta.page', 1);
        $this->assertArrayNotHasKey('semantic_score', $response->json('data.0'));
        $this->assertNotContains($unrelated->id, array_column($response->json('data'), 'id'));
        // No AI call: the image's own embedding is the query.
        $this->assertSame([], $this->ai->textCalls);
    }

    public function test_threshold_comes_from_configuration_and_pages_work(): void
    {
        config(['photobank.search.similar_min_similarity' => 0.5]);
        $source = $this->imageAt(1.0);
        $a = $this->imageAt(0.9);
        $b = $this->imageAt(0.8);
        $c = $this->imageAt(0.6);

        $response = $this->getJson("/api/admin/images/{$source->id}/similar?per_page=2&page=2")->assertOk();

        $this->assertSame([$c->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->assertNotContains($a->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains($b->id, array_column($response->json('data'), 'id'));
    }

    public function test_an_image_without_an_active_embedding_has_an_empty_page(): void
    {
        $source = $this->imageAt(null);
        $this->imageAt(0.99);

        $this->getJson("/api/admin/images/{$source->id}/similar")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unknown_or_deleted_image_is_not_found(): void
    {
        $deleted = $this->imageAt(1.0);
        $deleted->delete();

        $this->getJson('/api/admin/images/999999/similar')->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
        $this->getJson("/api/admin/images/{$deleted->id}/similar")->assertStatus(404)->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_requires_authentication_and_validates_paging(): void
    {
        $source = $this->imageAt(1.0);

        $this->getJson("/api/admin/images/{$source->id}/similar?per_page=500")->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/images/{$source->id}/similar")->assertStatus(401);
    }
}
