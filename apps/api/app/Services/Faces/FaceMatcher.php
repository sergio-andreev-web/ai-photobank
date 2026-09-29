<?php

namespace App\Services\Faces;

use App\Models\Face;
use App\Support\Vectors;
use Illuminate\Support\Facades\DB;

/**
 * Who a face belongs to (ADR-0015): cosine to the confirmed faces (references) of each
 * person, same model revision only (§77). Only `confirmed` faces are references, so an
 * automatic label never teaches the matcher anything.
 *
 * Matching runs in PHP over the references: a directory of people × ~10 confirmed faces is
 * small. With thousands of people this should move to a pgvector query.
 */
final class FaceMatcher
{
    /** @var array<string, list<array{0: int, 1: list<float>}>> revision → [person_id, vector] */
    private array $references = [];

    /**
     * @return list<array{0: int, 1: list<float>}>
     */
    public function references(string $revision): array
    {
        return $this->references[$revision] ??= DB::table('faces')
            ->join('images', 'images.id', '=', 'faces.image_id')
            ->whereNull('images.deleted_at')
            ->where('faces.status', Face::CONFIRMED)
            ->where('faces.model_revision', $revision)
            ->whereNotNull('faces.person_id')
            ->get(['faces.person_id', 'faces.embedding'])
            ->map(static fn (object $row): array => [(int) $row->person_id, Vectors::decode($row->embedding) ?? []])
            ->all();
    }

    public function forget(): void
    {
        $this->references = [];
    }

    /**
     * @param  list<float>  $vector
     * @param  list<int>  $rejected  People the admin said this face is not.
     * @return array{0: string, 1: ?int, 2: ?float} status, person_id, similarity
     */
    public function classify(array $vector, string $revision, array $rejected = []): array
    {
        $best = [];
        foreach ($this->references($revision) as [$personId, $reference]) {
            if (in_array($personId, $rejected, true) || count($reference) !== count($vector)) {
                continue;
            }
            $score = Vectors::cosine($vector, $reference);
            if (! isset($best[$personId]) || $score > $best[$personId]) {
                $best[$personId] = $score;
            }
        }
        if ($best === []) {
            return [Face::UNASSIGNED, null, null];
        }
        arsort($best);
        $personId = (int) array_key_first($best);
        $score = (float) $best[$personId];

        return match (true) {
            $score >= (float) config('photobank.faces.auto_assign_threshold') => [Face::AUTO, $personId, $score],
            $score >= (float) config('photobank.faces.match_threshold') => [Face::SUGGESTED, $personId, $score],
            default => [Face::UNASSIGNED, null, null],
        };
    }

    /**
     * Re-derives every undecided face (unassigned/suggested/auto) after the references changed.
     *
     * @param  list<int>|null  $imageIds  Limit to these images; null — all images.
     * @return array<int, array{auto: int, suggested: int}> per person: faces that now point at them
     */
    public function rematch(?array $imageIds = null): array
    {
        $this->forget();
        $changes = [];
        $query = Face::query()->whereIn('status', [Face::UNASSIGNED, Face::SUGGESTED, Face::AUTO]);
        if ($imageIds !== null) {
            $query->whereIn('image_id', $imageIds);
        }

        $query->select(['id', 'status', 'person_id', 'similarity', 'model_revision', 'rejected_person_ids', 'embedding'])
            ->chunkById(500, function ($faces) use (&$changes): void {
                foreach ($faces as $face) {
                    $vector = Vectors::decode($face->getRawOriginal('embedding')) ?? [];
                    [$status, $personId, $similarity] = $this->classify($vector, $face->model_revision, $face->rejectedPeople());
                    if ($status === $face->status && $personId === $face->person_id && abs(($similarity ?? 0) - ($face->similarity ?? 0)) < 1e-6) {
                        continue;
                    }
                    Face::whereKey($face->id)->update(['status' => $status, 'person_id' => $personId, 'similarity' => $similarity]);
                    if ($personId !== null) {
                        $changes[$personId] ??= ['auto' => 0, 'suggested' => 0];
                        $changes[$personId][$status === Face::AUTO ? 'auto' : 'suggested']++;
                    }
                }
            });

        return $changes;
    }
}
