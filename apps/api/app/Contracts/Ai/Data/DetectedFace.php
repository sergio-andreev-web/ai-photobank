<?php

namespace App\Contracts\Ai\Data;

/**
 * One face of a FaceDetectionResult: box in fractions of the image, detector score and the
 * L2-normalized 512-dim ArcFace vector.
 */
final readonly class DetectedFace
{
    /**
     * @param  list<float>  $embedding
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $score,
        public array $embedding,
    ) {}
}
