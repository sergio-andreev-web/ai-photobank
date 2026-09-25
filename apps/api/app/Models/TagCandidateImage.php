<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagCandidateImage extends Model
{
    use HasFactory;

    protected $table = 'tag_candidate_images';

    const UPDATED_AT = null;

    protected $fillable = [
        'candidate_id',
        'image_id',
        'confidence',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(TagCandidate::class, 'candidate_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }
}
