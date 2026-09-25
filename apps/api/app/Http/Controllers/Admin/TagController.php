<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\Tagging\TagNormalizer;
use App\Services\Tagging\Taxonomy;
use App\Services\Tagging\TaxonomyService;
use App\Support\Api;
use App\Support\Presenters\TagPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * contracts/public-api.openapi.yaml — canonical tags and aliases (ТЗ §16,
 * §17, §50, §55; ADR-0007). Business rules live in TaxonomyService.
 */
class TagController extends Controller
{
    use RejectsUnknownFields;

    public function __construct(private readonly TaxonomyService $taxonomy) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:500'],
            'q' => ['string', 'min:1', 'max:255'],
            'type' => ['string', Rule::in(Taxonomy::TAG_TYPES)],
            'status' => ['string', Rule::in([Taxonomy::TAG_ACTIVE, Taxonomy::TAG_ARCHIVED])],
            'parent_id' => ['integer', 'min:1'],
            'sort' => ['string', 'in:name,-name,-images_count,-created_at'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = Tag::query();

        if (isset($validated['q']) && ($q = TagNormalizer::normalize($validated['q'])) !== '') {
            $pattern = Api::likeContains($q);
            $query->where(fn ($where) => $where
                ->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('aliases', fn ($alias) => $alias->whereRaw("normalized_alias LIKE ? ESCAPE '!'", [$pattern])));
        }

        foreach (['type', 'status', 'parent_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $total = (clone $query)->count();

        match ($validated['sort'] ?? 'name') {
            'name' => $query->orderBy('name')->orderBy('id'),
            '-name' => $query->orderByDesc('name')->orderByDesc('id'),
            '-images_count' => $query->orderByDesc('images_count')->orderBy('id'),
            '-created_at' => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $tags = $query->withImagesCount()->with('aliases')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $tags->map(fn (Tag $tag) => TagPresenter::full($tag))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->rejectUnknownFields($request, ['name', 'type', 'parent_id']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'type' => ['required', 'string', Rule::in(Taxonomy::TAG_TYPES)],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $tag = $this->taxonomy->createTag(
            $validated['name'],
            $validated['type'],
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            $request->user()->id,
        );

        return response()->json($this->present($tag->id), 201);
    }

    public function show(int $tag): JsonResponse
    {
        return response()->json($this->present($this->findTag($tag)->id));
    }

    public function update(Request $request, int $tag): JsonResponse
    {
        $tagModel = $this->findTag($tag);

        $this->rejectUnknownFields($request, ['name', 'type', 'parent_id', 'status'], requireOne: true);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/u'],
            'type' => ['sometimes', 'required', 'string', Rule::in(Taxonomy::TAG_TYPES)],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'string', Rule::in([Taxonomy::TAG_ACTIVE, Taxonomy::TAG_ARCHIVED])],
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null) {
            $validated['parent_id'] = (int) $validated['parent_id'];
        }

        $this->taxonomy->updateTag($tagModel, $validated);

        return response()->json($this->present($tagModel->id));
    }

    public function destroy(int $tag): JsonResponse|Response
    {
        $archived = $this->taxonomy->deleteTag($this->findTag($tag));

        return $archived === null
            ? response()->noContent()
            : response()->json($this->present($archived->id));
    }

    public function storeAlias(Request $request, int $tag): JsonResponse
    {
        $tagModel = $this->findTag($tag);

        $this->rejectUnknownFields($request, ['alias', 'locale']);

        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'locale' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);

        $alias = $this->taxonomy->addAlias($tagModel, $validated['alias'], $validated['locale'] ?? null);

        return response()->json(TagPresenter::alias($alias), 201);
    }

    public function destroyAlias(int $tag, int $alias): Response
    {
        $this->taxonomy->removeAlias($this->findTag($tag), $alias);

        return response()->noContent();
    }

    private function findTag(int $id): Tag
    {
        return Tag::find($id) ?? throw new ApiException('TAG_NOT_FOUND', 404, 'Tag not found');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(int $id): array
    {
        return TagPresenter::full(Tag::withImagesCount()->with('aliases')->findOrFail($id));
    }
}
