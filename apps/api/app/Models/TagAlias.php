<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_id',
        'alias',
        'normalized_alias',
        'locale',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
