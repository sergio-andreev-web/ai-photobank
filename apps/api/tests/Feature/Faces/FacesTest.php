<?php

namespace Tests\Feature\Faces;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\DetectedFace;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Face;
use App\Models\Image;
use App\Models\ImageEmbedding;
use App\Models\Person;
use App\Services\Ai\FakeAiClient;
use App\Services\Faces\FaceScanner;
use App\Services\Processing\EmbeddingVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\Support\ScriptedEmbeddingsAiClient;
use Tests\TestCase;

/**
 * ADR-0015 backend: scanning, matching, «remember this person», rejections, unknown people,
 * deleting a person with their biometrics, the person filter and search by name.
 * vec(axis, s) has cosine exactly `s` with vec(axis, 1.0).
 */
class FacesTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photobank');
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClientInterface::class, $this->ai);
        $this->loginAsAdmin();
    }

    private static int $offAxis = 300;

    /**
     * Cosine `s` with vec(axis, 1.0); each call has its own off-axis, so two vectors of the same
     * axis have cosine s1 × s2.
     *
     * @return list<float>
     */
    private static function vec(int $axis, float $similarity = 1.0): array
    {
        $v = array_fill(0, 512, 0.0);
        $v[$axis] = $similarity;
        $v[self::$offAxis++] = sqrt(max(0.0, 1 - $similarity * $similarity));

        return $v;
    }

    private function face(Image $image, array $vector, string $status = Face::UNASSIGNED, ?Person $person = null, int $size = 120): Face
    {
        return Face::create([
            'image_id' => $image->id, 'person_id' => $person?->id, 'box_x' => 0.1, 'box_y' => 0.1,
            'box_width' => 0.2, 'box_height' => 0.2, 'size_px' => $size, 'score' => 0.9,
            'model_revision' => FakeAiClient::FACE_MODEL_REVISION, 'status' => $status,
            'rejected_person_ids' => [], 'embedding' => EmbeddingVersion::toVectorLiteral($vector),
        ]);
    }

    private function imageWithPreview(string $bytes): Image
    {
        $image = $this->makeImage(['preview_key' => 'variants/'.uniqid().'/preview.webp']);
        app(PhotoStorageInterface::class)->put($image->preview_key, $bytes);

        return $image;
    }

    // ---- scanning --------------------------------------------------------------------------

    public function test_scan_stores_faces_labels_known_people_and_runs_once(): void
    {
        $person = Person::create(['name' => 'Юлия']);
        $this->face($this->makeImage(), self::vec(1), Face::CONFIRMED, $person);

        $image = $this->imageWithPreview('photo-bytes');
        $this->ai->withFacesFor('photo-bytes', [
            new DetectedFace(0.1, 0.2, 0.1, 0.12, 0.9, self::vec(1, 0.8)),   // Юлия, sure
            new DetectedFace(0.5, 0.2, 0.05, 0.06, 0.8, self::vec(1, 0.45)), // looks like Юлия
            new DetectedFace(0.7, 0.2, 0.05, 0.06, 0.7, self::vec(2)),       // someone else
        ]);

        $this->assertTrue(app(FaceScanner::class)->scan($image));

        $faces = Face::where('image_id', $image->id)->orderBy('id')->get();
        $this->assertSame([Face::AUTO, Face::SUGGESTED, Face::UNASSIGNED], $faces->pluck('status')->all());
        $this->assertSame([$person->id, $person->id, null], $faces->pluck('person_id')->all());
        $this->assertSame(100, $faces[0]->size_px); // min(0.1 × 1000, 0.12 × 1000)
        $this->assertSame(FakeAiClient::FACE_MODEL_REVISION, $image->fresh()->faces_revision);

        app(FaceScanner::class)->scan($image->fresh());
        $this->assertCount(1, $this->ai->faceRequests, 'an image is scanned once per model revision');
        $this->assertSame(3, Face::where('image_id', $image->id)->count());
    }

    public function test_a_rescan_replaces_the_old_faces(): void
    {
        $image = $this->imageWithPreview('rescan-bytes');
        $this->face($image, self::vec(30));
        $this->ai->withFacesFor('rescan-bytes', [new DetectedFace(0.1, 0.1, 0.2, 0.2, 0.9, self::vec(31))]);

        app(FaceScanner::class)->scan($image);

        $this->assertSame(1, Face::where('image_id', $image->id)->count());
    }

    public function test_unavailable_faces_leave_the_image_unscanned(): void
    {
        $this->ai->failFacesWith(new AiException('MODEL_NOT_READY', 'Models are loading', 503, ['stage' => 'faces_unavailable'], null, true));
        $image = $this->imageWithPreview('bytes');

        $this->assertFalse(app(FaceScanner::class)->scan($image));
        $this->assertNull($image->fresh()->faces_revision);
        $this->assertSame(0, Face::count());
    }

    // ---- remember a person, reject -------------------------------------------------------

    public function test_assign_creates_a_person_from_faces_and_labels_the_rest(): void
    {
        $a = $this->face($this->makeImage(), self::vec(3));
        $b = $this->face($this->makeImage(), self::vec(3, 0.9));
        $sure = $this->face($this->makeImage(), self::vec(3, 0.7));
        $maybe = $this->face($this->makeImage(), self::vec(3, 0.42));
        $other = $this->face($this->makeImage(), self::vec(4));

        $response = $this->postJson('/api/admin/faces/assign', ['face_ids' => [$a->id, $b->id], 'name' => ' Иван Петров '], $this->csrfHeaders());

        $response->assertOk()
            ->assertJsonPath('person.name', 'Иван Петров')
            ->assertJsonPath('person.faces_count', 2)
            ->assertJsonPath('assigned_count', 2)
            ->assertJsonPath('auto_count', 1)
            ->assertJsonPath('suggested_count', 1);
        $this->assertSame(Face::CONFIRMED, $a->fresh()->status);
        $this->assertSame(Face::AUTO, $sure->fresh()->status);
        $this->assertSame(Face::SUGGESTED, $maybe->fresh()->status);
        $this->assertSame(Face::UNASSIGNED, $other->fresh()->status);
        $this->assertNull($other->fresh()->person_id);
        $response->assertJsonPath('person.images_count', 3); // two references + one auto
    }

    public function test_assign_validates_faces_and_person(): void
    {
        $face = $this->face($this->makeImage(), self::vec(3));

        $this->postJson('/api/admin/faces/assign', ['face_ids' => [$face->id, 9999], 'name' => 'X'], $this->csrfHeaders())
            ->assertStatus(422)->assertJsonPath('error.details.fields.face_ids.0', 'Лица не найдены: 9999.');
        $this->postJson('/api/admin/faces/assign', ['face_ids' => [$face->id]], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/faces/assign', ['face_ids' => [$face->id], 'name' => 'X', 'person_id' => 1], $this->csrfHeaders())->assertStatus(422);
        $this->postJson('/api/admin/faces/assign', ['face_ids' => [$face->id], 'person_id' => 9999], $this->csrfHeaders())
            ->assertStatus(404)->assertJsonPath('error.code', 'PERSON_NOT_FOUND');
    }

    public function test_reject_remembers_the_refusal(): void
    {
        $person = Person::create(['name' => 'Иван']);
        $this->face($this->makeImage(), self::vec(5), Face::CONFIRMED, $person);
        $face = $this->face($this->makeImage(), self::vec(5, 0.7), Face::AUTO, $person);

        $this->postJson("/api/admin/faces/{$face->id}/reject", [], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('status', 'unassigned')->assertJsonPath('person', null);

        // Another reference of the same person arrives: the refused face is still not theirs.
        $this->postJson('/api/admin/faces/assign', ['face_ids' => [$this->face($this->makeImage(), self::vec(5, 0.99))->id], 'person_id' => $person->id], $this->csrfHeaders())->assertOk();
        $this->assertSame(Face::UNASSIGNED, $face->fresh()->status);
        $this->assertSame([$person->id], $face->fresh()->rejectedPeople());

        $this->postJson('/api/admin/faces/9999/reject', [], $this->csrfHeaders())->assertStatus(404)->assertJsonPath('error.code', 'FACE_NOT_FOUND');
    }

    public function test_rejecting_a_reference_rematches_its_faces(): void
    {
        $person = Person::create(['name' => 'Иван']);
        $reference = $this->face($this->makeImage(), self::vec(6), Face::CONFIRMED, $person);
        $labelled = $this->face($this->makeImage(), self::vec(6, 0.8), Face::AUTO, $person);

        $this->postJson("/api/admin/faces/{$reference->id}/reject", [], $this->csrfHeaders())->assertOk();

        $this->assertSame(Face::UNASSIGNED, $labelled->fresh()->status, 'no reference left');
    }

    // ---- unknown people --------------------------------------------------------------------

    public function test_clusters_group_well_visible_unassigned_faces_seen_on_two_images(): void
    {
        $friend = [$this->face($this->makeImage(), self::vec(7)), $this->face($this->makeImage(), self::vec(7, 0.7)), $this->face($this->makeImage(), self::vec(7, 0.6))];
        $this->face($this->makeImage(), self::vec(8)); // seen once
        $this->face($this->makeImage(), self::vec(9), size: 20); // too small
        $this->face($this->makeImage(), self::vec(9, 0.9), size: 20);

        $response = $this->getJson('/api/admin/faces/clusters')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.images_count', 3);
        $this->assertEqualsCanonicalizing(array_map(fn (Face $f) => $f->id, $friend), array_column($response->json('data.0.faces'), 'id'));
        $this->assertArrayNotHasKey('embedding', $response->json('data.0.faces.0'));
    }

    // ---- people ----------------------------------------------------------------------------

    public function test_people_crud_and_deleting_a_person_deletes_their_biometrics(): void
    {
        $created = $this->postJson('/api/admin/people', ['name' => 'Анна', 'note' => 'актриса'], $this->csrfHeaders())
            ->assertCreated()->assertJsonPath('faces_count', 0)->assertJsonPath('cover', null);
        $id = $created->json('id');
        $person = Person::find($id);

        $image = $this->makeImage();
        $this->face($image, self::vec(10), Face::CONFIRMED, $person);
        $this->face($this->makeImage(), self::vec(10, 0.45), Face::SUGGESTED, $person);
        $this->face($this->makeImage(), self::vec(11));

        $this->getJson('/api/admin/people?q=анн')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.suggested_count', 1)->assertJsonPath('data.0.cover.image.id', $image->id);
        $this->patchJson("/api/admin/people/{$id}", ['name' => 'Анна К.'], $this->csrfHeaders())->assertOk()->assertJsonPath('name', 'Анна К.');
        $this->getJson("/api/admin/people/{$id}/faces?status=suggested")->assertOk()->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/admin/people/{$id}", [], $this->csrfHeaders())->assertNoContent();

        $this->assertNull(Person::find($id));
        $this->assertSame(1, Face::count(), 'faces of the person are gone, others stay');
        $this->assertTrue(Image::whereKey($image->id)->exists(), 'images stay');
        $this->getJson("/api/admin/people/{$id}")->assertStatus(404)->assertJsonPath('error.code', 'PERSON_NOT_FOUND');
    }

    public function test_image_faces_person_filter_and_search_by_name(): void
    {
        $person = Person::create(['name' => 'Иван Петров']);
        $withHim = $this->makeImage();
        $suggestedOnly = $this->makeImage();
        $this->face($withHim, self::vec(12), Face::CONFIRMED, $person);
        $this->face($suggestedOnly, self::vec(12, 0.45), Face::SUGGESTED, $person);
        $this->makeImage();

        $this->getJson("/api/admin/images/{$withHim->id}/faces")->assertOk()
            ->assertJsonPath('data.0.person.name', 'Иван Петров')->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.similarity', null);
        $ids = array_column($this->getJson("/api/admin/images?person_id={$person->id}")->assertOk()->json('data'), 'id');
        $this->assertSame([$withHim->id], $ids);

        // Search by the person's name (word match, ADR-0004 + ADR-0015).
        $scripted = new ScriptedEmbeddingsAiClient;
        $this->app->instance(AiClientInterface::class, $scripted);
        foreach ([$withHim, $suggestedOnly] as $image) {
            ImageEmbedding::create([
                'image_id' => $image->id, 'model_key' => 'siglip2-stub', 'model_version' => 'fake-embedding-rev-1',
                'embedding' => EmbeddingVersion::toVectorLiteral(ScriptedEmbeddingsAiClient::vector(20, 0.01)), 'active' => true,
            ]);
        }
        $found = $this->postJson('/api/search', ['mode' => 'semantic', 'query' => 'Петров'], $this->csrfHeaders())->assertOk();
        $this->assertSame($withHim->id, $found->json('data.0.id'));
    }
}
