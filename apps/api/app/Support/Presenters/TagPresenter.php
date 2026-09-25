<?php

namespace App\Support\Presenters;

use App\Models\Tag;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/TagRef.
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
}
