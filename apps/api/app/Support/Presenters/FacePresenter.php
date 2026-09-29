<?php

namespace App\Support\Presenters;

use App\Models\Face;
use App\Models\Person;
use App\Support\Api;
use Illuminate\Support\Facades\DB;

/**
 * contracts Face / Person (ADR-0015). The face vector is never exposed.
 */
final class FacePresenter
{
    /**
     * Expects `image` and `person` loaded.
     *
     * @return array<string, mixed>
     */
    public static function face(Face $face): array
    {
        $image = $face->image;

        return [
            'id' => $face->id,
            'image' => [
                'id' => $image->id,
                'original_name' => $image->original_name,
                'urls' => ImagePresenter::urls($image),
            ],
            'box' => [
                'x' => $face->box_x,
                'y' => $face->box_y,
                'width' => $face->box_width,
                'height' => $face->box_height,
            ],
            'score' => $face->score,
            'status' => $face->status,
            'person' => $face->person === null ? null : ['id' => $face->person->id, 'name' => $face->person->name],
            'similarity' => in_array($face->status, [Face::SUGGESTED, Face::AUTO], true) ? $face->similarity : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function person(Person $person): array
    {
        $faces = DB::table('faces')
            ->join('images', 'images.id', '=', 'faces.image_id')
            ->whereNull('images.deleted_at')
            ->where('faces.person_id', $person->id);

        $cover = Face::with(['image', 'person'])
            ->where('person_id', $person->id)
            ->where('status', Face::CONFIRMED)
            ->whereHas('image', fn ($q) => $q->whereNull('deleted_at'))
            ->orderByDesc('size_px')
            ->orderBy('id')
            ->first();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'note' => $person->note,
            'faces_count' => (clone $faces)->where('faces.status', Face::CONFIRMED)->count(),
            'images_count' => (clone $faces)->whereIn('faces.status', Face::IDENTIFIED)->distinct()->count('faces.image_id'),
            'suggested_count' => (clone $faces)->where('faces.status', Face::SUGGESTED)->count(),
            'cover' => $cover === null ? null : self::face($cover),
            'created_at' => Api::dateTime($person->created_at),
            'updated_at' => Api::dateTime($person->updated_at),
        ];
    }
}
