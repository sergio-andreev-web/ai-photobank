<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Services\Tagging\ImageTagEditor;
use App\Support\Presenters\ImagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * contracts/public-api.openapi.yaml — manual image tags (ТЗ §41, ADR-0006).
 */
class ImageTagController extends Controller
{
    use RejectsUnknownFields;

    public function __construct(private readonly ImageTagEditor $editor) {}

    public function store(Request $request, int $image): JsonResponse
    {
        $this->rejectUnknownFields($request, ['tag_id']);

        $validated = $request->validate([
            'tag_id' => ['required', 'integer', 'min:1'],
        ]);

        [$link, $changed] = $this->editor->add($image, (int) $validated['tag_id']);

        return response()->json(ImagePresenter::imageTag($link->fresh('tag')), $changed ? 201 : 200);
    }

    public function destroy(Request $request, int $image, int $tag): Response
    {
        $this->editor->remove($image, $tag, $request->user()->id);

        return response()->noContent();
    }
}
