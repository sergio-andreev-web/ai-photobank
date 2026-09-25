<?php

namespace App\Services\Search;

/**
 * One page of image IDs in result order, plus the total of matching images
 * (ADR-0004: no similarity threshold, so total is every image that passes the
 * filters).
 */
final readonly class SearchPage
{
    /**
     * @param  list<int>  $imageIds  Ranked, best first.
     * @param  array<int, float>  $scores  image_id → `1 - cosine distance`; empty for tags mode.
     */
    public function __construct(
        public array $imageIds,
        public array $scores,
        public int $total,
    ) {}
}
