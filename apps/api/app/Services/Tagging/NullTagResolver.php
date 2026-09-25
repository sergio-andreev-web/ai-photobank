<?php

namespace App\Services\Tagging;

use App\Contracts\Tagging\TagResolverInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;

/**
 * Placeholder binding until the backend-taxonomy task implements TagResolver
 * (§36). Matches nothing, so the pipeline completes end to end and a run
 * simply yields no AI tags; manual tags and suppressions are unaffected.
 */
final class NullTagResolver implements TagResolverInterface
{
    public function resolve(Image $image, ImageAnalysis $analysis): array
    {
        return [];
    }
}
