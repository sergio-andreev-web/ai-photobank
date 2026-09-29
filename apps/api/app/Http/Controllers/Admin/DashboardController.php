<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Tag;
use App\Models\TagCandidate;
use App\Services\Tagging\Taxonomy;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/dashboard (ТЗ §52, ADR-0003): the counters of the Dashboard screen in
 * one request. Soft-deleted images are not counted (Image's SoftDeletes scope).
 */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'images_count' => Image::count(),
            'tags_count' => Tag::where('status', Taxonomy::TAG_ACTIVE)->count(),
            'pending_candidates_count' => TagCandidate::where('status', Taxonomy::CANDIDATE_PENDING)->count(),
            'processing_images_count' => Image::whereIn('status', ['uploaded', 'queued', 'processing'])->count(),
            'failed_images_count' => Image::where('status', 'failed')->count(),
        ]);
    }
}
