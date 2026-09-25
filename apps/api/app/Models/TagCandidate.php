<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'display_name',
        'normalized_name',
        'type',
        'closest_tag_id',
        'similarity',
        'status',
        'occurrences_count',
        'resolved_tag_id',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'similarity' => 'decimal:5',
        'occurrences_count' => 'integer',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function closestTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'closest_tag_id');
    }

    public function resolvedTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'resolved_tag_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(TagCandidateImage::class, 'candidate_id');
    }
}
