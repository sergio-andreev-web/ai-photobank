<?php

namespace Tests\Support;

use App\Contracts\Tagging\ResolvedTag;
use App\Contracts\Tagging\TagResolverInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;
use Throwable;

/**
 * Stands in for the backend-taxonomy TagResolver: returns a scripted result
 * so pipeline tests can check how image_tags are applied without depending
 * on the matching algorithm.
 */
final class StubTagResolver implements TagResolverInterface
{
    /** @var list<ResolvedTag> */
    public array $result = [];

    /** @var list<Throwable> */
    public array $failures = [];

    /** @var list<int> Analysis ids passed in, in call order. */
    public array $analysisIds = [];

    public function resolve(Image $image, ImageAnalysis $analysis): array
    {
        $this->analysisIds[] = $analysis->id;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return $this->result;
    }
}
