<?php

namespace App\Services\Processing;

use App\Contracts\Tagging\ResolvedTag;
use App\Models\Image;
use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use App\Models\Tag;

/**
 * Applies a resolver result to image_tags (contracts/state-machines.md, Image
 * tag link; ADR-0006, §41, §76):
 *
 * - the set of `source=ai, locked=false` links is replaced by the new result;
 * - manual, locked and import links are never modified or removed;
 * - suppressed (image, tag) pairs are never linked again;
 * - archived tags get no new links (ADR-0007).
 *
 * Upserts by image_id + tag_id (§28), so repeating a run is a no-op.
 * Must run inside the caller's transaction.
 */
final class ImageTagSynchronizer
{
    /**
     * @param  list<ResolvedTag>  $resolved
     */
    public function sync(Image $image, array $resolved): void
    {
        $wanted = [];
        foreach ($resolved as $tag) {
            $current = $wanted[$tag->tagId] ?? null;
            if ($current === null || ($tag->confidence ?? -1) > ($current->confidence ?? -1)) {
                $wanted[$tag->tagId] = $tag;
            }
        }

        $suppressed = ImageTagSuppression::where('image_id', $image->id)->pluck('tag_id')->all();
        $existing = ImageTag::where('image_id', $image->id)->lockForUpdate()->get()->keyBy('tag_id');
        $active = Tag::whereIn('id', array_keys($wanted))->where('status', 'active')->pluck('id')->all();

        $wanted = array_intersect_key($wanted, array_flip(array_diff($active, $suppressed)));

        foreach ($existing as $tagId => $link) {
            if ($this->isReplaceable($link) && ! isset($wanted[$tagId])) {
                $link->delete();
            }
        }

        foreach ($wanted as $tagId => $tag) {
            $link = $existing->get($tagId);

            if ($link === null) {
                ImageTag::create([
                    'image_id' => $image->id,
                    'tag_id' => $tagId,
                    'source' => 'ai',
                    'confidence' => $tag->confidence,
                    'locked' => false,
                ]);
            } elseif ($this->isReplaceable($link)) {
                $link->confidence = $tag->confidence;
                $link->save();
            }
        }
    }

    private function isReplaceable(ImageTag $link): bool
    {
        return $link->source === 'ai' && ! $link->locked;
    }
}
