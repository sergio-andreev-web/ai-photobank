<?php

namespace App\Services\Processing\Stages;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Image;
use App\Services\Processing\GdImageProcessor;
use App\Services\Processing\ProcessingStage;
use App\Services\Processing\ProcessingState;
use App\Services\Processing\StageFailure;

/**
 * `variants` (§12, §25): thumbnail + preview WebP at the fixed keys
 * variants/<image-id>/<name>.webp (§11), so a repeat overwrites instead of
 * duplicating (§28). The original is only read.
 */
final readonly class VariantsStage implements ProcessingStage
{
    public function __construct(
        private PhotoStorageInterface $storage,
        private GdImageProcessor $processor,
    ) {}

    /**
     * @return array{thumbnail_key: string, preview_key: string}
     */
    public function execute(Image $image): array
    {
        if (! $this->storage->exists($image->storage_key)) {
            throw new StageFailure(
                ProcessingState::ERROR_VARIANT_GENERATION_FAILED,
                'Original file is missing',
                retryable: false,
            );
        }

        $variants = $this->processor->webpVariants(
            $this->storage->get($image->storage_key),
            [
                'thumbnail' => config('photobank.variants.thumbnail'),
                'preview' => config('photobank.variants.preview'),
            ],
        );

        $keys = [];
        foreach ($variants as $name => $bytes) {
            $key = self::key($image->id, $name);
            $this->storage->put($key, $bytes);
            $keys["{$name}_key"] = $key;
        }

        return $keys;
    }

    public function persist(Image $image, mixed $outcome): void
    {
        $image->thumbnail_key = $outcome['thumbnail_key'];
        $image->preview_key = $outcome['preview_key'];
        $image->save();
    }

    public static function key(int $imageId, string $variant): string
    {
        return "variants/{$imageId}/{$variant}.webp";
    }
}
