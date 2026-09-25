<?php

namespace Tests\Concerns;

use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\User;
use App\Services\Tagging\TagEmbeddingIndex;
use App\Services\Tagging\TagNormalizer;
use Illuminate\Support\Str;

/**
 * Direct DB fixtures for taxonomy tests (no HTTP, no queue).
 */
trait BuildsTaxonomy
{
    protected function taxonomyUser(): User
    {
        return User::where('email', 'taxonomy@example.test')->first() ?? User::create([
            'name' => 'Taxonomy admin',
            'email' => 'taxonomy@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);
    }

    protected function makeTag(string $name, string $type = 'object', string $status = 'active', ?int $parentId = null): Tag
    {
        return Tag::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'normalized_name' => TagNormalizer::normalize($name),
            'type' => $type,
            'status' => $status,
            'parent_id' => $parentId,
        ]);
    }

    protected function makeAlias(Tag $tag, string $alias): TagAlias
    {
        return TagAlias::create([
            'tag_id' => $tag->id,
            'alias' => $alias,
            'normalized_alias' => TagNormalizer::normalize($alias),
        ]);
    }

    /**
     * @param  list<float>  $vector
     */
    protected function embedTag(Tag $tag, array $vector, string $modelKey = 'siglip2-stub', string $modelVersion = 'fake-embedding-rev-1'): void
    {
        app(TagEmbeddingIndex::class)->store($tag->id, $modelKey, $modelVersion, $tag->name, $vector);
    }

    protected function makeImage(array $attributes = []): Image
    {
        $user = $this->taxonomyUser();
        $batch = Batch::create(['status' => 'processing', 'created_by' => $user->id]);

        return Image::create(array_merge([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => 'originals/2026/09/'.$batch->id.'/'.Str::random(16).'.jpg',
            'thumbnail_key' => null,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => 1024,
            'sha256' => hash('sha256', Str::random(32)),
            'status' => 'ready',
            'created_by' => $user->id,
        ], $attributes));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float}>  $concepts  [type, label, confidence]
     */
    protected function makeAnalysis(Image $image, array $concepts): ImageAnalysis
    {
        return ImageAnalysis::create([
            'image_id' => $image->id,
            'vision_model' => 'fake/qwen3-vl-stub',
            'vision_model_version' => 'fake-vision-rev-1',
            'embedding_model' => 'fake/siglip2-stub',
            'embedding_model_version' => 'fake-embedding-rev-1',
            'prompt_version' => 'fake-analysis-v1',
            'caption' => null,
            'concepts' => array_map(fn (array $c) => ['type' => $c[0], 'label' => $c[1], 'confidence' => $c[2]], $concepts),
            'ocr' => [],
            'raw_response' => [],
            'processing_ms' => 1,
        ]);
    }
}
