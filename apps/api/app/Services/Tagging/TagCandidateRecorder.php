<?php

namespace App\Services\Tagging;

use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use Illuminate\Support\Facades\DB;

/**
 * Upserts a tag_candidate for an unmatched concept (ТЗ §39, §23, §24;
 * ADR-0008; state-machines.md "Tag candidate"):
 *
 * - one candidate per normalized_name + type, one link per image;
 * - occurrences_count = number of distinct linked images;
 * - closest_tag_id/similarity keep the best value seen;
 * - the status never changes here: a `rejected` candidate stays rejected
 *   (its counter and links still grow), `created`/`merged` stay as they are.
 *
 * Repeating the same detection is a no-op, so pipeline retries and
 * duplicate messages cannot inflate the counter. Safe under concurrent
 * workers: the insert races on the unique key and the row is then locked.
 */
final class TagCandidateRecorder
{
    public function record(
        string $normalizedName,
        string $displayName,
        string $type,
        int $imageId,
        float $confidence,
        ?int $closestTagId,
        ?float $similarity,
    ): TagCandidate {
        // Column scales: similarity DECIMAL(6,5), confidence DECIMAL(5,4) (§23, §24).
        $similarity = $similarity !== null ? round($similarity, 5) : null;
        $closestTagId = $similarity !== null ? $closestTagId : null;
        $confidence = round($confidence, 4);

        return DB::transaction(function () use ($normalizedName, $displayName, $type, $imageId, $confidence, $closestTagId, $similarity): TagCandidate {
            $now = now();

            DB::table('tag_candidates')->insertOrIgnore([
                'display_name' => $displayName,
                'normalized_name' => $normalizedName,
                'type' => $type,
                'closest_tag_id' => $closestTagId,
                'similarity' => $similarity,
                'status' => 'pending',
                'occurrences_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var TagCandidate $candidate */
            $candidate = TagCandidate::where('normalized_name', $normalizedName)
                ->where('type', $type)
                ->lockForUpdate()
                ->firstOrFail();

            $link = TagCandidateImage::where('candidate_id', $candidate->id)->where('image_id', $imageId)->first();

            if ($link === null) {
                TagCandidateImage::create([
                    'candidate_id' => $candidate->id,
                    'image_id' => $imageId,
                    'confidence' => $confidence,
                ]);
            } elseif ((float) $link->confidence < $confidence) {
                $link->confidence = $confidence;
                $link->save();
            }

            $candidate->occurrences_count = TagCandidateImage::where('candidate_id', $candidate->id)->count();

            if ($similarity !== null
                && ($candidate->similarity === null || (float) $candidate->similarity < $similarity)) {
                $candidate->closest_tag_id = $closestTagId;
                $candidate->similarity = $similarity;
            }

            $candidate->save();

            return $candidate;
        }, 3);
    }
}
