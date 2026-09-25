<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagEmbedding extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'tag_id',
        'model_key',
        'model_version',
        'source_text',
        'embedding',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
