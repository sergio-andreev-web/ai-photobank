<?php

namespace App\Services\Tagging;

use App\Exceptions\ApiException;
use App\Jobs\RegenerateTagEmbedding;
use App\Models\Image;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use App\Models\TagCandidate;
use App\Models\TagCandidateImage;
use Illuminate\Support\Facades\DB;

/**
 * Admin decisions on tag_candidates (ТЗ §40, §50; ADR-0008;
 * state-machines.md "Tag candidate"). Each action is one transaction with
 * the candidate row locked and is allowed only from `pending`; a repeat is
 * 409 TAG_CANDIDATE_ALREADY_RESOLVED, so double submits change nothing.
 *
 * approve/merge link the tag to every (not deleted) image of the candidate
 * as `source=ai, locked=false` with the candidate link's confidence,
 * skipping suppressed pairs and existing links.
 */
final readonly class CandidateModeration
{
    public function __construct(private TaxonomyService $taxonomy) {}

    /**
     * @param  array{name?: string, type?: string, parent_id?: int|null}  $overrides
     * @return array{candidate: TagCandidate, tag: Tag, attached_images_count: int, alias_created: bool}
     *
     * @throws ApiException TAG_CANDIDATE_NOT_FOUND, TAG_CANDIDATE_ALREADY_RESOLVED, TAG_ALREADY_EXISTS, TAG_PARENT_INVALID
     */
    public function approve(int $candidateId, array $overrides, int $userId): array
    {
        $result = DB::transaction(function () use ($candidateId, $overrides, $userId): array {
            $candidate = $this->lockPending($candidateId);

            $tag = $this->taxonomy->createTag(
                name: $overrides['name'] ?? $candidate->display_name,
                type: $overrides['type'] ?? $candidate->type,
                parentId: $overrides['parent_id'] ?? null,
                createdBy: $userId,
            );

            $attached = $this->attach($candidate, $tag);
            $this->resolve($candidate, Taxonomy::CANDIDATE_CREATED, $tag, $userId);

            return ['candidate' => $candidate, 'tag' => $tag, 'attached_images_count' => $attached, 'alias_created' => false];
        }, 3);

        return $result;
    }

    /**
     * @return array{candidate: TagCandidate, tag: Tag, attached_images_count: int, alias_created: bool}
     *
     * @throws ApiException TAG_CANDIDATE_NOT_FOUND, TAG_CANDIDATE_ALREADY_RESOLVED, TAG_NOT_FOUND, TAG_ARCHIVED, ALIAS_ALREADY_EXISTS
     */
    public function merge(int $candidateId, int $tagId, bool $createAlias, int $userId): array
    {
        $result = DB::transaction(function () use ($candidateId, $tagId, $createAlias, $userId): array {
            $candidate = $this->lockPending($candidateId);
            $tag = Tag::lockForUpdate()->find($tagId);

            if ($tag === null) {
                throw new ApiException('TAG_NOT_FOUND', 404, 'Tag not found');
            }

            if ($tag->status !== Taxonomy::TAG_ACTIVE) {
                throw new ApiException('TAG_ARCHIVED', 409, 'Cannot merge into an archived tag');
            }

            $aliasCreated = $createAlias && $this->taxonomy->ensureAlias($tag, $candidate->display_name);

            $attached = $this->attach($candidate, $tag);
            $this->resolve($candidate, Taxonomy::CANDIDATE_MERGED, $tag, $userId);

            return ['candidate' => $candidate, 'tag' => $tag, 'attached_images_count' => $attached, 'alias_created' => $aliasCreated];
        }, 3);

        if ($result['alias_created']) {
            RegenerateTagEmbedding::dispatch($result['tag']->id);
        }

        return $result;
    }

    /**
     * @return array{candidate: TagCandidate, tag: null, attached_images_count: int, alias_created: bool}
     *
     * @throws ApiException TAG_CANDIDATE_NOT_FOUND, TAG_CANDIDATE_ALREADY_RESOLVED
     */
    public function reject(int $candidateId, int $userId): array
    {
        return DB::transaction(function () use ($candidateId, $userId): array {
            $candidate = $this->lockPending($candidateId);
            $this->resolve($candidate, Taxonomy::CANDIDATE_REJECTED, null, $userId);

            return ['candidate' => $candidate, 'tag' => null, 'attached_images_count' => 0, 'alias_created' => false];
        }, 3);
    }

    private function lockPending(int $candidateId): TagCandidate
    {
        $candidate = TagCandidate::lockForUpdate()->find($candidateId);

        if ($candidate === null) {
            throw new ApiException('TAG_CANDIDATE_NOT_FOUND', 404, 'Tag candidate not found');
        }

        if ($candidate->status !== Taxonomy::CANDIDATE_PENDING) {
            throw new ApiException('TAG_CANDIDATE_ALREADY_RESOLVED', 409, 'Candidate is already resolved', [
                'status' => $candidate->status,
            ]);
        }

        return $candidate;
    }

    private function resolve(TagCandidate $candidate, string $status, ?Tag $tag, int $userId): void
    {
        $candidate->fill([
            'status' => $status,
            'resolved_tag_id' => $tag?->id,
            'resolved_by' => $userId,
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * Images are locked in id order, after the candidate — the pipeline and
     * manual edits lock an image before its image_tags, so a concurrent
     * suppression is either visible here or applied after this link exists.
     */
    private function attach(TagCandidate $candidate, Tag $tag): int
    {
        $links = TagCandidateImage::where('candidate_id', $candidate->id)->get()->keyBy('image_id');
        if ($links->isEmpty()) {
            return 0;
        }

        $imageIds = Image::whereIn('id', $links->keys())->orderBy('id')->lockForUpdate()->pluck('id');
        $suppressed = ImageTagSuppression::where('tag_id', $tag->id)->whereIn('image_id', $imageIds)->pluck('image_id')->flip();

        $attached = 0;
        $now = now();

        foreach ($imageIds as $imageId) {
            if ($suppressed->has($imageId)) {
                continue;
            }

            $attached += DB::table('image_tags')->insertOrIgnore([
                'image_id' => $imageId,
                'tag_id' => $tag->id,
                'source' => Taxonomy::SOURCE_AI,
                'confidence' => $links[$imageId]->confidence,
                'locked' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $attached;
    }
}
