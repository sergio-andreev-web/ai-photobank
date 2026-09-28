<?php

namespace Tests\Feature\Database;

use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageEmbedding;
use App\Models\ImageTag;
use App\Models\ProcessingTask;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
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

    private function makeBatch(User $user): Batch
    {
        return Batch::create([
            'name' => 'Batch',
            'status' => 'created',
            'created_by' => $user->id,
        ]);
    }

    private function makeImage(Batch $batch, User $user, array $overrides = []): Image
    {
        return Image::create(array_merge([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/'.uniqid().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', uniqid('', true)),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ], $overrides));
    }

    public function test_all_domain_tables_exist(): void
    {
        $tables = [
            'images', 'tags', 'tag_aliases', 'image_tags', 'image_tag_suppressions',
            'image_embeddings', 'tag_embeddings', 'image_analyses',
            'tag_candidates', 'tag_candidate_images', 'processing_tasks',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist");
        }
    }

    public function test_images_soft_delete_and_sha256_uniqueness(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);

        $this->expectException(QueryException::class);
        $this->makeImage($batch, $user, ['sha256' => $image->sha256]);
    }

    public function test_image_soft_delete_hides_row_from_default_queries(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);

        $image->delete();

        $this->assertNull(Image::find($image->id));
        $this->assertNotNull(Image::withTrashed()->find($image->id));
        $this->assertTrue(Image::withTrashed()->find($image->id)->trashed());
    }

    public function test_tag_unique_normalized_name_and_type(): void
    {
        Tag::create([
            'name' => 'Каска',
            'slug' => 'kaska',
            'normalized_name' => 'каска',
            'type' => 'object',
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        Tag::create([
            'name' => 'Каска дубль',
            'slug' => 'kaska-2',
            'normalized_name' => 'каска',
            'type' => 'object',
            'status' => 'active',
        ]);
    }

    public function test_image_tags_composite_unique(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);
        $tag = Tag::create([
            'name' => 'Каска', 'slug' => 'kaska', 'normalized_name' => 'каска',
            'type' => 'object', 'status' => 'active',
        ]);

        ImageTag::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'source' => 'ai', 'locked' => false]);

        $this->expectException(QueryException::class);
        ImageTag::create(['image_id' => $image->id, 'tag_id' => $tag->id, 'source' => 'manual', 'locked' => true]);
    }

    public function test_image_belongs_to_batch_and_batch_has_many_images(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);

        $this->assertTrue($batch->images->contains($image));
        $this->assertEquals($batch->id, $image->batch->id);
    }

    public function test_image_tags_relation_carries_pivot_columns(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);
        $tag = Tag::create([
            'name' => 'Каска', 'slug' => 'kaska', 'normalized_name' => 'каска',
            'type' => 'object', 'status' => 'active',
        ]);

        $image->tags()->attach($tag->id, ['source' => 'ai', 'confidence' => '0.9600', 'locked' => false]);

        $pivot = $image->tags()->first()->pivot;
        $this->assertEquals('ai', $pivot->source);
        $this->assertFalse((bool) $pivot->locked);
    }

    public function test_image_embedding_active_flag_and_relation(): void
    {
        $user = $this->makeUser();
        $batch = $this->makeBatch($user);
        $image = $this->makeImage($batch, $user);

        $embedding = ImageEmbedding::create([
            'image_id' => $image->id,
            'model_key' => 'siglip2-so400m-patch14-384',
            'model_version' => 'testrev1',
            // vector(1152) NOT NULL on Postgres; opaque nullable text on sqlite.
            'embedding' => '['.implode(',', array_fill(0, 1152, 0.1)).']',
            'active' => true,
        ]);

        $this->assertTrue($image->activeEmbedding()->exists());
        $this->assertEquals($embedding->id, $image->activeEmbedding->id);
    }

    public function test_processing_task_requires_image(): void
    {
        $this->expectException(QueryException::class);
        ProcessingTask::create([
            'image_id' => 999999,
            'type' => 'metadata',
            'status' => 'queued',
            'max_attempts' => 3,
        ]);
    }
}
