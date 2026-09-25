<?php

namespace Tests\Feature\Models;

use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin+'.uniqid().'@example.test',
            'password' => 'secret',
            'role' => 'admin',
        ]);
    }

    private function makeImage(User $user): Image
    {
        $batch = Batch::create(['status' => 'created', 'created_by' => $user->id]);

        return Image::create([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/'.uniqid().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', uniqid('', true)),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ]);
    }

    public function test_tag_parent_child_relation(): void
    {
        $parent = Tag::create([
            'name' => 'Транспорт', 'slug' => 'transport', 'normalized_name' => 'транспорт',
            'type' => 'object', 'status' => 'active',
        ]);
        $child = Tag::create([
            'parent_id' => $parent->id,
            'name' => 'Автомобиль', 'slug' => 'avtomobil', 'normalized_name' => 'автомобиль',
            'type' => 'object', 'status' => 'active',
        ]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_tag_alias_global_uniqueness(): void
    {
        $tag = Tag::create([
            'name' => 'Автомобиль', 'slug' => 'avtomobil', 'normalized_name' => 'автомобиль',
            'type' => 'object', 'status' => 'active',
        ]);
        TagAlias::create(['tag_id' => $tag->id, 'alias' => 'car', 'normalized_alias' => 'car']);

        $other = Tag::create([
            'name' => 'Каска', 'slug' => 'kaska', 'normalized_name' => 'каска',
            'type' => 'object', 'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        TagAlias::create(['tag_id' => $other->id, 'alias' => 'car', 'normalized_alias' => 'car']);
    }

    public function test_tag_candidate_upsert_key_and_resolved_tag_audit(): void
    {
        $tag = Tag::create([
            'name' => 'Буровая', 'slug' => 'burovaya', 'normalized_name' => 'буровая',
            'type' => 'object', 'status' => 'active',
        ]);
        $admin = $this->makeUser();

        $candidate = TagCandidate::create([
            'display_name' => 'Буровая платформа',
            'normalized_name' => 'буровая платформа',
            'type' => 'object',
            'status' => 'pending',
            'occurrences_count' => 1,
        ]);

        $this->expectException(QueryException::class);
        TagCandidate::create([
            'display_name' => 'Буровая платформа (2)',
            'normalized_name' => 'буровая платформа',
            'type' => 'object',
            'status' => 'pending',
            'occurrences_count' => 1,
        ]);

        // Approve as a merge into an existing tag: resolved_tag_id records the audit trail
        // (ADR-0008) independently of closest_tag_id, which only ever holds the resolver's guess.
        $candidate->update([
            'status' => 'merged',
            'resolved_tag_id' => $tag->id,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);

        $this->assertEquals($tag->id, $candidate->fresh()->resolvedTag->id);
    }

    public function test_tag_candidate_image_unique_per_pair(): void
    {
        $candidate = TagCandidate::create([
            'display_name' => 'Буровая платформа', 'normalized_name' => 'буровая платформа',
            'type' => 'object', 'status' => 'pending', 'occurrences_count' => 1,
        ]);
        $admin = $this->makeUser();
        $image = $this->makeImage($admin);

        TagCandidateImage::create(['candidate_id' => $candidate->id, 'image_id' => $image->id, 'confidence' => '0.7000']);

        $this->expectException(QueryException::class);
        TagCandidateImage::create(['candidate_id' => $candidate->id, 'image_id' => $image->id, 'confidence' => '0.7500']);
    }

    public function test_image_tag_suppression_unique_and_cascades_with_image(): void
    {
        $admin = $this->makeUser();
        $image = $this->makeImage($admin);
        $tag = Tag::create([
            'name' => 'Каска', 'slug' => 'kaska', 'normalized_name' => 'каска',
            'type' => 'object', 'status' => 'active',
        ]);

        ImageTagSuppression::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'created_by' => $admin->id]);

        $this->expectException(QueryException::class);
        ImageTagSuppression::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'created_by' => $admin->id]);
    }

    public function test_reprocess_keeps_history_of_image_analyses(): void
    {
        $admin = $this->makeUser();
        $image = $this->makeImage($admin);

        $first = ImageAnalysis::create([
            'image_id' => $image->id,
            'vision_model' => 'Qwen/Qwen3-VL-4B-Instruct',
            'vision_model_version' => 'rev-1',
            'embedding_model' => 'google/siglip2-so400m-patch14-384',
            'embedding_model_version' => 'rev-1',
            'prompt_version' => 'image-analysis-v1',
            'caption' => 'first pass',
            'concepts' => [],
            'ocr' => [],
            'raw_response' => ['ok' => true],
        ]);

        $second = ImageAnalysis::create([
            'image_id' => $image->id,
            'vision_model' => 'Qwen/Qwen3-VL-4B-Instruct',
            'vision_model_version' => 'rev-1',
            'embedding_model' => 'google/siglip2-so400m-patch14-384',
            'embedding_model_version' => 'rev-1',
            'prompt_version' => 'image-analysis-v1',
            'caption' => 'reprocessed',
            'concepts' => [],
            'ocr' => [],
            'raw_response' => ['ok' => true],
        ]);

        $this->assertNotEquals($first->id, $second->id);
        $this->assertCount(2, $image->analyses);
    }
}
