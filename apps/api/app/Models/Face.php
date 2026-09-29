<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A face found on an image (ADR-0015). `embedding` is biometric data (152-ФЗ): it never
 * leaves the API and is deleted with the person it belongs to.
 */
class Face extends Model
{
    public const string UNASSIGNED = 'unassigned';

    public const string SUGGESTED = 'suggested';

    public const string AUTO = 'auto';

    public const string CONFIRMED = 'confirmed';

    /** Statuses in which the face counts as «this person is on the image». */
    public const array IDENTIFIED = [self::AUTO, self::CONFIRMED];

    protected $fillable = [
        'image_id', 'person_id', 'box_x', 'box_y', 'box_width', 'box_height', 'size_px', 'score',
        'model_revision', 'status', 'similarity', 'rejected_person_ids', 'embedding',
    ];

    protected $hidden = ['embedding'];

    protected $casts = [
        'box_x' => 'float',
        'box_y' => 'float',
        'box_width' => 'float',
        'box_height' => 'float',
        'size_px' => 'integer',
        'score' => 'float',
        'similarity' => 'float',
        'rejected_person_ids' => 'array',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class)->withTrashed();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return list<int>
     */
    public function rejectedPeople(): array
    {
        return array_map(intval(...), $this->rejected_person_ids ?? []);
    }
}
