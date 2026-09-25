<?php

namespace App\Contracts\Tagging;

/**
 * One canonical tag the resolver matched for an image. `confidence` is the
 * concept's uncalibrated AI confidence (§38), stored on image_tags for
 * debug/sorting only.
 */
final readonly class ResolvedTag
{
    public function __construct(
        public int $tagId,
        public ?float $confidence = null,
    ) {}
}
