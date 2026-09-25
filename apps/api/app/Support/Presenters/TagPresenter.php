<?php

namespace App\Support\Presenters;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Support\Api;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/TagRef, #/Tag and #/TagAlias.
 */
final class TagPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function ref(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'type' => $tag->type,
            'status' => $tag->status,
        ];
    }

    /**
     * Expects `aliases` loaded and `images_count` from {@see Tag::scopeWithImagesCount()}.
     *
     * @return array<string, mixed>
     */
    public static function full(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'parent_id' => $tag->parent_id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'normalized_name' => $tag->normalized_name,
            'type' => $tag->type,
            'status' => $tag->status,
            'images_count' => (int) ($tag->images_count ?? 0),
            'aliases' => $tag->aliases->sortBy('id')->values()->map(fn (TagAlias $alias) => self::alias($alias))->all(),
            'created_at' => Api::dateTime($tag->created_at),
            'updated_at' => Api::dateTime($tag->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function alias(TagAlias $alias): array
    {
        return [
            'id' => $alias->id,
            'tag_id' => $alias->tag_id,
            'alias' => $alias->alias,
            'normalized_alias' => $alias->normalized_alias,
            'locale' => $alias->locale,
            'created_at' => Api::dateTime($alias->created_at),
            'updated_at' => Api::dateTime($alias->updated_at),
        ];
    }
}
