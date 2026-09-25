<?php

namespace App\Support\Presenters;

use App\Models\Tag;
use App\Models\TagCandidate;
use App\Support\Api;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/TagCandidate and #/CandidateResolution.
 */
final class TagCandidatePresenter
{
    /** ADR-0008: up to 8 sample images, by confidence. */
    public const int SAMPLE_IMAGES = 8;

    /**
     * @param  Collection<int, list<array<string, mixed>>>|null  $samples  From {@see self::samples()}; loaded when null.
     * @return array<string, mixed>
     */
    public static function candidate(TagCandidate $candidate, ?Collection $samples = null): array
    {
        $samples ??= self::samples([$candidate->id]);

        return [
            'id' => $candidate->id,
            'display_name' => $candidate->display_name,
            'normalized_name' => $candidate->normalized_name,
            'type' => $candidate->type,
            'status' => $candidate->status,
            'occurrences_count' => $candidate->occurrences_count,
            'closest_tag' => $candidate->closestTag ? TagPresenter::ref($candidate->closestTag) : null,
            'similarity' => $candidate->similarity !== null ? (float) $candidate->similarity : null,
            'sample_images' => $samples->get($candidate->id, []),
            'created_at' => Api::dateTime($candidate->created_at),
            'updated_at' => Api::dateTime($candidate->updated_at),
            'resolved_at' => Api::dateTime($candidate->resolved_at),
            'resolved_by' => $candidate->resolved_by,
            'resolved_tag_id' => $candidate->resolved_tag_id,
        ];
    }

    /**
     * @param  array{candidate: TagCandidate, tag: Tag|null, attached_images_count: int, alias_created: bool}  $result
     * @return array<string, mixed>
     */
    public static function resolution(array $result): array
    {
        $candidate = $result['candidate']->fresh(['closestTag']);

        return [
            'candidate' => self::candidate($candidate),
            'tag' => $result['tag'] ? TagPresenter::ref($result['tag']->fresh()) : null,
            'attached_images_count' => $result['attached_images_count'],
            'alias_created' => $result['alias_created'],
        ];
    }

    /**
     * Top images per candidate, not deleted, by confidence desc then image id.
     *
     * @param  list<int>  $candidateIds
     * @return Collection<int, list<array{id: int, thumbnail_url: string|null, confidence: float|null}>>
     */
    public static function samples(array $candidateIds): Collection
    {
        if ($candidateIds === []) {
            return collect();
        }

        $ranked = DB::table('tag_candidate_images as tci')
            ->join('images as i', 'i.id', '=', 'tci.image_id')
            ->whereNull('i.deleted_at')
            ->whereIn('tci.candidate_id', $candidateIds)
            ->select([
                'tci.candidate_id', 'tci.image_id', 'tci.confidence', 'i.thumbnail_key',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY tci.candidate_id ORDER BY tci.confidence DESC, tci.image_id) AS rn'),
            ]);

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::SAMPLE_IMAGES)
            ->orderBy('candidate_id')->orderBy('rn')
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $rows) => $rows->map(fn (object $row) => [
                'id' => (int) $row->image_id,
                'thumbnail_url' => $row->thumbnail_key ? "/api/media/images/{$row->image_id}/thumbnail" : null,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            ])->values()->all());
    }
}
