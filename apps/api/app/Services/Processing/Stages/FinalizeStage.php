<?php

namespace App\Services\Processing\Stages;

use App\Models\Image;
use App\Services\Processing\ProcessingStage;
use App\Services\Processing\ProcessingState;

/**
 * `finalize` (§28): the image becomes `ready`; the pipeline then refreshes
 * the batch in the same transaction.
 */
final class FinalizeStage implements ProcessingStage
{
    public function execute(Image $image): mixed
    {
        return null;
    }

    public function persist(Image $image, mixed $outcome): void
    {
        $image->status = ProcessingState::IMAGE_READY;
        $image->error_code = null;
        $image->error_message = null;
        $image->save();
    }
}
