<?php

namespace App\Contracts\Tagging;

use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Services\Processing\StageFailure;

/**
 * Backend-internal seam between the processing pipeline (tag_resolution
 * stage) and the TagResolver algorithm (ТЗ §35–§39, backend-taxonomy task).
 *
 * The resolver maps the analysis concepts to canonical tags (exact → alias →
 * semantic) and may upsert tag_candidates idempotently (§39). It must not
 * write image_tags: the pipeline applies the returned set itself so that
 * manual/locked links and suppressions survive every run (§41, §76, ADR-0006).
 *
 * Runs outside a DB transaction and may call AI (stage 3). Throw
 * {@see StageFailure} for a classified failure;
 * AiException / InvalidAiResponseException are classified by the pipeline.
 */
interface TagResolverInterface
{
    /**
     * @return list<ResolvedTag> Canonical tags to link with source=ai, locked=false.
     */
    public function resolve(Image $image, ImageAnalysis $analysis): array;
}
