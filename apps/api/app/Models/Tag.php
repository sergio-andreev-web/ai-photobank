<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'normalized_name',
        'type',
        'status',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Tag::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(Image::class, 'image_tags')
            ->withPivot(['source', 'confidence', 'locked'])
            ->withTimestamps();
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(TagEmbedding::class);
    }

    /**
     * `images_count` = not deleted images with this tag (§55); Image soft deletes
     * are excluded by the related model's scope.
     */
    public function scopeWithImagesCount(Builder $query): Builder
    {
        return $query->withCount('images');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(TagCandidate::class, 'closest_tag_id');
    }
}
