<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Models\Face;
use App\Models\Image;
use App\Models\Person;
use App\Services\Faces\FaceClusters;
use App\Services\Faces\FaceMatcher;
use App\Support\Presenters\FacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faces on images (ADR-0015): listing, «remember this person» and «this is not them».
 */
class FaceController extends Controller
{
    use RejectsUnknownFields;

    public function imageFaces(int $image): JsonResponse
    {
        if (Image::find($image) === null) {
            throw new ApiException('IMAGE_NOT_FOUND', 404, 'Image not found');
        }

        $faces = Face::with(['image', 'person'])->where('image_id', $image)->orderByDesc('size_px')->orderBy('id')->get();

        return response()->json(['data' => $faces->map(fn (Face $face) => FacePresenter::face($face))->all()]);
    }

    public function clusters(Request $request, FaceClusters $clusters): JsonResponse
    {
        $validated = $request->validate(['limit' => ['integer', 'min:1', 'max:50']]);
        $groups = $clusters->find((int) ($validated['limit'] ?? 20));

        $faces = Face::with(['image', 'person'])
            ->whereIn('id', array_merge([], ...array_column($groups, 'face_ids')))
            ->get()
            ->keyBy('id');

        return response()->json(['data' => array_map(static fn (array $group): array => [
            'faces' => array_values(array_map(static fn (int $id): array => FacePresenter::face($faces[$id]), $group['face_ids'])),
            'images_count' => $group['images_count'],
        ], $groups)]);
    }

    /**
     * «Remember this person»: the faces become references of an existing or a new person,
     * then every undecided face is matched again.
     */
    public function assign(Request $request, FaceMatcher $matcher): JsonResponse
    {
        $this->rejectUnknownFields($request, ['face_ids', 'person_id', 'name']);
        $validated = $request->validate([
            'face_ids' => ['required', 'array', 'min:1', 'max:200'],
            'face_ids.*' => ['integer', 'min:1', 'distinct'],
            'person_id' => ['integer', 'min:1', 'required_without:name', 'prohibits:name'],
            'name' => ['string', 'min:1', 'max:255', 'required_without:person_id'],
        ]);
        $ids = array_map(intval(...), $validated['face_ids']);

        $missing = array_values(array_diff($ids, Face::whereIn('id', $ids)->pluck('id')->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'face_ids' => [__('validation.unknown_face_ids', ['ids' => implode(', ', $missing)])],
            ]);
        }

        $person = isset($validated['person_id'])
            ? (Person::find($validated['person_id']) ?? throw new ApiException('PERSON_NOT_FOUND', 404, 'Person not found'))
            : null;

        $person = DB::transaction(function () use ($person, $validated, $ids, $request): Person {
            $person ??= Person::create(['name' => trim($validated['name']), 'created_by' => $request->user()?->id]);
            Face::whereIn('id', $ids)->update([
                'person_id' => $person->id,
                'status' => Face::CONFIRMED,
                'similarity' => null,
            ]);

            return $person;
        });

        $changes = $matcher->rematch();

        return response()->json([
            'person' => FacePresenter::person($person),
            'assigned_count' => count($ids),
            'auto_count' => $changes[$person->id]['auto'] ?? 0,
            'suggested_count' => $changes[$person->id]['suggested'] ?? 0,
        ]);
    }

    /**
     * «This is not them»: the person is taken off the face and never suggested for it again.
     */
    public function reject(int $face, FaceMatcher $matcher): JsonResponse
    {
        $model = Face::find($face) ?? throw new ApiException('FACE_NOT_FOUND', 404, 'Face not found');
        $wasReference = $model->status === Face::CONFIRMED;

        if ($model->person_id !== null) {
            $model->rejected_person_ids = array_values(array_unique([...$model->rejectedPeople(), $model->person_id]));
        }
        $model->person_id = null;
        $model->status = Face::UNASSIGNED;
        $model->similarity = null;
        $model->save();

        if ($wasReference) {
            // A reference is gone: faces matched through it may change.
            $matcher->rematch();
        }

        return response()->json(FacePresenter::face($model->fresh(['image', 'person'])));
    }
}
