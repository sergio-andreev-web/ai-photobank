<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Models\Face;
use App\Models\Person;
use App\Support\Api;
use App\Support\Presenters\FacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * /api/admin/people (ADR-0015): the directory of people faces are matched against.
 */
class PersonController extends Controller
{
    use RejectsUnknownFields;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = Person::query()->orderBy('name')->orderBy('id');
        if (($q = trim((string) ($validated['q'] ?? ''))) !== '') {
            $query->where('name_normalized', 'like', '%'.addcslashes(mb_strtolower($q), '%_\\').'%');
        }
        $total = (clone $query)->count();

        return response()->json([
            'data' => $query->forPage($page, $perPage)->get()->map(fn (Person $person) => FacePresenter::person($person))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->rejectUnknownFields($request, ['name', 'note']);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $person = Person::create([
            'name' => trim($validated['name']),
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(FacePresenter::person($person), 201);
    }

    public function show(int $person): JsonResponse
    {
        return response()->json(FacePresenter::person($this->findPerson($person)));
    }

    public function update(Request $request, int $person): JsonResponse
    {
        $model = $this->findPerson($person);
        $this->rejectUnknownFields($request, ['name', 'note'], requireOne: true);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('name', $validated)) {
            $model->name = trim($validated['name']);
        }
        if (array_key_exists('note', $validated)) {
            $model->note = $validated['note'];
        }
        $model->save();

        return response()->json(FacePresenter::person($model));
    }

    /**
     * 152-ФЗ: the person goes together with every face vector attached to them.
     */
    public function destroy(int $person): Response
    {
        $model = $this->findPerson($person);

        $removed = DB::transaction(function () use ($model): int {
            $faces = Face::where('person_id', $model->id)->delete();
            $model->delete();

            return $faces;
        });
        Log::info('faces.person.deleted', ['person_id' => $person, 'faces_deleted' => $removed]);

        return response()->noContent();
    }

    public function faces(Request $request, int $person): JsonResponse
    {
        $model = $this->findPerson($person);
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'status' => ['string', 'in:unassigned,suggested,auto,confirmed'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = Face::with(['image', 'person'])
            ->where('person_id', $model->id)
            ->whereHas('image', fn ($q) => $q->whereNull('deleted_at'));
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $query->orderByRaw('similarity IS NULL')->orderByDesc('similarity')->orderByDesc('id');
        $total = (clone $query)->count();

        return response()->json([
            'data' => $query->forPage($page, $perPage)->get()->map(fn (Face $face) => FacePresenter::face($face))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    private function findPerson(int $id): Person
    {
        return Person::find($id) ?? throw new ApiException('PERSON_NOT_FOUND', 404, 'Person not found');
    }
}
