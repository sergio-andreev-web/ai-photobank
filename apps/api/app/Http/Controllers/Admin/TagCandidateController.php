<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Models\TagCandidate;
use App\Services\Tagging\CandidateModeration;
use App\Services\Tagging\TagNormalizer;
use App\Services\Tagging\Taxonomy;
use App\Support\Api;
use App\Support\Presenters\TagCandidatePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * contracts/public-api.openapi.yaml — tag candidate moderation (ТЗ §40,
 * §50, §56; ADR-0008). State transitions live in CandidateModeration.
 */
class TagCandidateController extends Controller
{
    use RejectsUnknownFields;

    public function __construct(private readonly CandidateModeration $moderation) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'status' => ['string', Rule::in(Taxonomy::CANDIDATE_STATUSES)],
            'type' => ['string', Rule::in(Taxonomy::TAG_TYPES)],
            'q' => ['string', 'min:1', 'max:255'],
            'sort' => ['string', 'in:-occurrences_count,-created_at,-similarity'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = TagCandidate::query()->where('status', $validated['status'] ?? Taxonomy::CANDIDATE_PENDING);

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (isset($validated['q']) && ($q = TagNormalizer::normalize($validated['q'])) !== '') {
            $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", [Api::likeContains($q)]);
        }

        $total = (clone $query)->count();

        match ($validated['sort'] ?? '-occurrences_count') {
            '-occurrences_count' => $query->orderByDesc('occurrences_count')->orderBy('id'),
            '-created_at' => $query->orderByDesc('created_at')->orderByDesc('id'),
            // Candidates without a closest tag have no similarity; list them last.
            '-similarity' => $query->orderByRaw('CASE WHEN similarity IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('similarity')->orderBy('id'),
        };

        $candidates = $query->with('closestTag')->forPage($page, $perPage)->get();
        $samples = TagCandidatePresenter::samples($candidates->modelKeys());

        return response()->json([
            'data' => $candidates->map(fn (TagCandidate $c) => TagCandidatePresenter::candidate($c, $samples))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    public function approve(Request $request, int $candidate): JsonResponse
    {
        $this->rejectUnknownFields($request, ['name', 'type', 'parent_id']);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/u'],
            'type' => ['sometimes', 'required', 'string', Rule::in(Taxonomy::TAG_TYPES)],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['parent_id'])) {
            $validated['parent_id'] = (int) $validated['parent_id'];
        }

        $result = $this->moderation->approve($candidate, $validated, $request->user()->id);

        return response()->json(TagCandidatePresenter::resolution($result));
    }

    public function merge(Request $request, int $candidate): JsonResponse
    {
        $this->rejectUnknownFields($request, ['tag_id', 'create_alias']);

        $validated = $request->validate([
            'tag_id' => ['required', 'integer', 'min:1'],
            'create_alias' => ['sometimes', 'boolean'],
        ]);

        $result = $this->moderation->merge(
            $candidate,
            (int) $validated['tag_id'],
            (bool) ($validated['create_alias'] ?? true),
            $request->user()->id,
        );

        return response()->json(TagCandidatePresenter::resolution($result));
    }

    public function reject(Request $request, int $candidate): JsonResponse
    {
        $result = $this->moderation->reject($candidate, $request->user()->id);

        return response()->json(TagCandidatePresenter::resolution($result));
    }
}
