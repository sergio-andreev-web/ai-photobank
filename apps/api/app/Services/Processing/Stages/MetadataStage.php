<?php

namespace App\Services\Processing\Stages;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Image;
use App\Services\Processing\GdImageProcessor;
use App\Services\Processing\ProcessingStage;
use App\Services\Processing\ProcessingState;
use App\Services\Processing\StageFailure;

/**
 * `metadata` (§25): fills width/height, which stay null until this runs
 * (ImageDetail contract). Re-running overwrites the same columns.
 */
final readonly class MetadataStage implements ProcessingStage
{
    public function __construct(
        private PhotoStorageInterface $storage,
        private GdImageProcessor $processor,
    ) {}

    /**
     * @return array{width: int, height: int}
     */
    public function execute(Image $image): array
    {
        if (! $this->storage->exists($image->storage_key)) {
            throw new StageFailure(
                ProcessingState::ERROR_METADATA_EXTRACTION_FAILED,
                'Original file is missing',
                retryable: false,
            );
        }

        return $this->processor->inspect($this->storage->get($image->storage_key));
    }

    public function persist(Image $image, mixed $outcome): void
    {
        $image->width = $outcome['width'];
        $image->height = $outcome['height'];
        $image->save();
    }
}
