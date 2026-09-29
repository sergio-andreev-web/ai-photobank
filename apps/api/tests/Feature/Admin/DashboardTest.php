<?php

namespace Tests\Feature\Admin;

use App\Models\TagCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * GET /api/admin/dashboard (ТЗ §52, ADR-0003).
 */
class DashboardTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    public function test_counts_follow_the_contract_definitions(): void
    {
        $this->loginAsAdmin();
        $this->makeImage(['status' => 'ready']);
        $this->makeImage(['status' => 'queued']);
        $this->makeImage(['status' => 'processing']);
        $this->makeImage(['status' => 'uploaded']);
        $this->makeImage(['status' => 'failed']);
        $this->makeImage(['status' => 'failed'])->delete(); // soft-deleted: not counted
        $this->makeTag('Каска');
        $this->makeTag('Старое')->update(['status' => 'archived']);
        TagCandidate::create(['display_name' => 'rig', 'normalized_name' => 'rig', 'type' => 'object', 'status' => 'pending', 'occurrences_count' => 1]);
        TagCandidate::create(['display_name' => 'old', 'normalized_name' => 'old', 'type' => 'object', 'status' => 'rejected', 'occurrences_count' => 1]);

        $this->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'images_count' => 5,
            'tags_count' => 1,
            'pending_candidates_count' => 1,
            'processing_images_count' => 3,
            'failed_images_count' => 1,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }
}
