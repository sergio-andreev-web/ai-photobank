<?php

namespace App\Services\Tagging;

use App\Exceptions\ApiException;
use App\Models\Image;
use App\Models\ImageTag;
use App\Models\ImageTagSuppression;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Manual tag edits on an image (ТЗ §41; ADR-0006; state-machines.md "Image
 * tag link"):
 *
 * - add: the link becomes `source=manual, locked=true` (an existing AI link
 *   is converted) and a suppression of that tag is lifted;
 * - remove: the link is deleted and a suppression is recorded, whatever the
 *   link's source, so no later AI run brings the tag back.
 *
 * Both lock the image row first — the same order as the pipeline's
 * tag_resolution persist — so an edit and a concurrent AI run serialize and
 * the later one sees the other's result.
 */
final class ImageTagEditor
{
    /**
     * @return array{0: ImageTag, 1: bool} The link and whether it changed.
     *
     * @throws ApiException IMAGE_NOT_FOUND, TAG_NOT_FOUND, TAG_ARCHIVED
     */
    public function add(int $imageId, int $tagId): array
    {
        return DB::transaction(function () use ($imageId, $tagId): array {
            $image = $this->lockImage($imageId);
            $tag = Tag::find($tagId);

            if ($tag === null) {
                throw new ApiException('TAG_NOT_FOUND', 404, 'Tag not found');
            }

            if ($tag->status !== Taxonomy::TAG_ACTIVE) {
                throw new ApiException('TAG_ARCHIVED', 409, 'Archived tags cannot be assigned');
            }

            $link = ImageTag::where('image_id', $image->id)->where('tag_id', $tag->id)->lockForUpdate()->first();

            if ($link !== null && $link->source === Taxonomy::SOURCE_MANUAL && $link->locked) {
                return [$link, false];
            }

            if ($link === null) {
                $link = ImageTag::create([
                    'image_id' => $image->id,
                    'tag_id' => $tag->id,
                    'source' => Taxonomy::SOURCE_MANUAL,
                    'confidence' => null,
                    'locked' => true,
                ]);
            } else {
                $link->source = Taxonomy::SOURCE_MANUAL;
                $link->locked = true;
                $link->save();
            }

            ImageTagSuppression::where('image_id', $image->id)->where('tag_id', $tag->id)->delete();

            return [$link, true];
        }, 3);
    }

    /**
     * @throws ApiException IMAGE_NOT_FOUND, IMAGE_TAG_NOT_FOUND
     */
    public function remove(int $imageId, int $tagId, int $userId): void
    {
        DB::transaction(function () use ($imageId, $tagId, $userId): void {
            $image = $this->lockImage($imageId);

            $deleted = ImageTag::where('image_id', $image->id)->where('tag_id', $tagId)->delete();

            if ($deleted === 0) {
                throw new ApiException('IMAGE_TAG_NOT_FOUND', 404, 'Image has no such tag');
            }

            DB::table('image_tag_suppressions')->insertOrIgnore([
                'image_id' => $image->id,
                'tag_id' => $tagId,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function lockImage(int $imageId): Image
    {
        $image = Image::lockForUpdate()->find($imageId);

        if ($image === null) {
            throw new ApiException('IMAGE_NOT_FOUND', 404, 'Image not found');
        }

        return $image;
    }
}
