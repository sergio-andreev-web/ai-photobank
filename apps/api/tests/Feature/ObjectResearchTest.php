<?php

namespace Tests\Feature;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Jobs\ResearchImage;
use App\Models\ImageResearchRun;
use App\Models\ResearchSettings;
use App\Services\Processing\Stages\FinalizeStage;
use App\Services\Research\ResearchClient;
use App\Services\Research\ResearchScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

class ObjectResearchTest extends TestCase
{
    use BuildsTaxonomy, InteractsAsAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $this->withHeaders($this->csrfHeaders());
        Storage::fake('photobank');
        Queue::fake();
        Http::fake(['*/ready' => Http::response(['providers' => [['id' => 'codex_cli', 'available' => true], ['id' => 'ollama', 'available' => true]]]), '*/v1/research' => Http::response(['status' => 'uncertain', 'name' => null, 'description' => 'Недостаточно свидетельств.', 'confidence' => 'low', 'sources' => []])]);
    }

    private function image()
    {
        $image = $this->makeImage(['preview_key' => 'variants/test/preview.webp', 'caption' => 'Ручная подпись']);
        app(PhotoStorageInterface::class)->put($image->preview_key, 'preview-bytes');

        return $image;
    }

    public function test_settings_default_off_persist_and_validate(): void
    {
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('object_research_enabled', false)->assertJsonMissingPath('token');
        $this->patchJson('/api/admin/settings', ['object_research_enabled' => true, 'object_research_provider' => 'ollama'])->assertOk()->assertJsonPath('object_research_provider', 'ollama');
        $this->getJson('/api/admin/settings')->assertJsonPath('object_research_enabled', true);
        $this->patchJson('/api/admin/settings', ['object_research_provider' => 'cars'])->assertStatus(422);
        $this->patchJson('/api/admin/settings', ['api_key' => 'secret'])->assertStatus(422);
    }

    public function test_manual_works_when_auto_off_and_active_post_is_idempotent(): void
    {
        $image = $this->image();
        $url = "/api/admin/images/{$image->id}/research";
        $id = $this->postJson($url, ['question' => ''])->assertStatus(202)->assertJsonPath('trigger', 'manual')->json('id');
        $this->postJson($url, ['question' => 'Еще вопрос'])->assertStatus(202)->assertJsonPath('id', $id);
        Queue::assertPushed(ResearchImage::class, 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseCount('image_research_runs', 1);
    }

    public function test_auto_only_on_finalize_once_and_does_not_backfill(): void
    {
        $image = $this->image();
        $stage = app(FinalizeStage::class);
        $stage->persist($image, null);
        $this->assertDatabaseCount('image_research_runs', 0);
        $this->patchJson('/api/admin/settings', ['object_research_enabled' => true])->assertOk();
        $this->assertDatabaseCount('image_research_runs', 0);
        $stage->persist($image, null);
        $stage->persist($image, null);
        $this->assertDatabaseCount('image_research_runs', 1);
        ImageResearchRun::first()->update(['status' => 'succeeded']);
        $stage->persist($image, null);
        $this->assertDatabaseCount('image_research_runs', 1);
    }

    public function test_auto_cancelled_if_disabled_and_deleted_photo_is_never_sent(): void
    {
        ResearchSettings::current()->update(['object_research_enabled' => true]);
        $image = $this->image();
        $run = app(ResearchScheduler::class)->schedule($image, 'automatic');
        ResearchSettings::current()->update(['object_research_enabled' => false]);
        (new ResearchImage($run->id))->handle(app(ResearchClient::class), app(PhotoStorageInterface::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        Http::assertNothingSent();
        $run = app(ResearchScheduler::class)->schedule($image, 'manual');
        $image->delete();
        (new ResearchImage($run->id))->handle(app(ResearchClient::class), app(PhotoStorageInterface::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_job_saves_result_preserves_caption_and_does_not_repeat_on_redelivery(): void
    {
        $image = $this->image();
        $run = app(ResearchScheduler::class)->schedule($image, 'manual', 'Что это?');
        $job = new ResearchImage($run->id);
        $job->handle(app(ResearchClient::class), app(PhotoStorageInterface::class));
        $job->handle(app(ResearchClient::class), app(PhotoStorageInterface::class));
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertSame('Ручная подпись', $image->fresh()->caption);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['image_base64'] === base64_encode('preview-bytes') && ! isset($request['storage_key']));
    }

    public function test_failures_invalid_output_unavailable_provider_and_stale_jobs(): void
    {
        $image = $this->image();
        $url = "/api/admin/images/{$image->id}/research";
        Http::swap(new Factory);
        Http::fake(['*/ready' => Http::response(['providers' => []])]);
        $this->postJson($url, [])->assertStatus(503)->assertJsonPath('error.code', 'WEB_RESEARCH_UNAVAILABLE');
        $run = app(ResearchScheduler::class)->schedule($image, 'manual');
        Http::swap(new Factory);
        Http::fake(['*/v1/research' => Http::response(['status' => 'identified', 'name' => 'Выдумка', 'sources' => []])]);
        (new ResearchImage($run->id))->handle(app(ResearchClient::class), app(PhotoStorageInterface::class));
        $this->assertSame('AGENT_OUTPUT_INVALID', $run->fresh()->error_code);
        $run = app(ResearchScheduler::class)->schedule($image, 'manual');
        $run->update(['updated_at' => now()->subMinutes(6)]);
        $this->getJson($url)->assertJsonPath('data.0.status', 'failed');
    }

    public function test_not_ready_deleted_and_nonadmin(): void
    {
        $image = $this->image();
        $image->update(['status' => 'processing']);
        $this->postJson("/api/admin/images/{$image->id}/research", [])->assertStatus(409);
        $image->delete();
        $this->getJson("/api/admin/images/{$image->id}/research")->assertStatus(404);
        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->getJson('/api/admin/settings')->assertStatus(401);
    }
}
