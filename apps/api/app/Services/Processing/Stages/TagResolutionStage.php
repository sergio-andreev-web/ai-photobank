<?php

namespace App\Services\Processing\Stages;

use App\Contracts\Tagging\ResolvedTag;
use App\Contracts\Tagging\TagResolverInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Services\Processing\ImageTagSynchronizer;
use App\Services\Processing\ProcessingStage;
use App\Services\Processing\ProcessingState;
use App\Services\Processing\StageFailure;

/**
 * `tag_resolution` (§36): resolves the latest analysis — the one this run's
 * ai_analysis just created — and replaces the image's AI tag set.
 */
final readonly class TagResolutionStage implements ProcessingStage
{
    public function __construct(
        private TagResolverInterface $resolver,
        private ImageTagSynchronizer $synchronizer,
    ) {}

    /**
     * @return list<ResolvedTag>
     */
    public function execute(Image $image): array
    {
        $analysis = ImageAnalysis::where('image_id', $image->id)->orderByDesc('id')->first();

        if ($analysis === null) {
            throw new StageFailure(
                ProcessingState::ERROR_TAG_RESOLUTION_FAILED,
                'No analysis to resolve tags from',
                retryable: false,
            );
        }

        return $this->resolver->resolve($image, $analysis);
    }

    public function persist(Image $image, mixed $outcome): void
    {
        $this->synchronizer->sync($image, $outcome);
    }
}
