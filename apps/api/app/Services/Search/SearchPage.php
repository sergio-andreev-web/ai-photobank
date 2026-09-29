<?php

namespace App\Services\Search;

/**
 * One page of image IDs in result order, plus the total of images the query
 * returns (after the relevance floor, ADR-0004 2026-09-30 addendum / ADR-0012).
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
