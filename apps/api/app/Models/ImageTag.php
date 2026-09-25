<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageTag extends Model
{
    use HasFactory;

    protected $table = 'image_tags';

    protected $fillable = [
        'image_id',
        'tag_id',
        'source',
        'confidence',
        'locked',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'locked' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
