<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Models\Image;
use App\Models\Tag;
use App\Services\Search\ImageSearch;
use App\Services\Search\SearchPage;
use App\Support\Api;
use App\Support\Presenters\ImagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * contracts/public-api.openapi.yaml — POST /api/search (ТЗ §42–§46, ADR-0004).
 *
 * The body is a oneOf on the required `mode` with `additionalProperties:
 * false`: tags → tag_ids only, semantic → query only, hybrid → both. Every
 * validation error (including an unknown tag ID) is returned before AI is
 * called. Ranking and filtering live in App\Services\Search\ImageSearch.
 */
class SearchController extends Controller
{
    use RejectsUnknownFields;

    private const array MODES = ['tags', 'semantic', 'hybrid'];

    public function __invoke(Request $request, ImageSearch $search): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $mode = $validated['mode'];
        $tagIds = $validated['tag_ids'] ?? [];
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 50;
        $debug = $validated['debug'] ?? false;

        $this->assertTagsExist($tagIds);

        $result = $mode === 'tags'
            ? $search->byTags($tagIds, $page, $perPage)
            : $search->semantic($validated['query'], $tagIds, $page, $perPage, ErrorResponse::traceId($request));

        return response()->json([
            'data' => $this->present($result, $debug && $mode !== 'tags'),
            'meta' => Api::paginationMeta($page, $perPage, $result->total) + ['mode' => $mode],
        ]);
    }

    /**
     * @return array{mode: string, query?: string, tag_ids?: list<int>, page?: int, per_page?: int, debug?: bool}
     */
    private function validatePayload(Request $request): array
    {
        // Only the JSON body counts: query-string parameters are not part of this operation.
        $payload = $request->json()->all();

        $mode = Validator::make($payload, [
            'mode' => ['required', 'string', Rule::in(self::MODES)],
        ])->validate()['mode'];

        $this->rejectUnknownFields($request, ['mode', 'query', 'tag_ids', 'page', 'per_page', 'debug']);

        $query = ['required', 'string', 'max:1000', 'regex:/\S/u'];
        $tagIds = ['required', 'list', 'min:1', 'max:20'];

        $rules = [
            'mode' => ['required'],
            'page' => ['integer:strict', 'min:1'],
            'per_page' => ['integer:strict', 'min:1', 'max:100'],
            'debug' => ['boolean:strict'],
            // Forbidden in this mode: present at all (even null or "") is invalid.
            'query' => $mode === 'tags' ? ['missing'] : $query,
            'tag_ids' => $mode === 'semantic' ? ['missing'] : $tagIds,
        ];

        if ($mode !== 'semantic') {
            $rules['tag_ids.*'] = ['integer:strict', 'min:1', 'distinct'];
        }

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * Archived tags are valid filters; an ID that does not exist is a
     * VALIDATION_ERROR on `tag_ids` (ADR-0004).
     *
     * @param  list<int>  $tagIds
     */
    private function assertTagsExist(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $unknown = array_values(array_diff($tagIds, Tag::whereIn('id', $tagIds)->pluck('id')->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'tag_ids' => [__('validation.unknown_tag_ids', ['ids' => implode(', ', $unknown)])],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(SearchPage $result, bool $withScores): array
    {
        $images = Image::with('tags')->whereIn('id', $result->imageIds)->get()->keyBy('id');

        $data = [];
        foreach ($result->imageIds as $id) {
            // Deleted between ranking and loading: drop it rather than fail the page.
            if (! $images->has($id)) {
                continue;
            }

            $item = ImagePresenter::summary($images[$id]);
            if ($withScores) {
                $item['semantic_score'] = $result->scores[$id];
            }

            $data[] = $item;
        }

        return $data;
    }
}
