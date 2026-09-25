<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageAnalysis extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'image_id',
        'vision_model',
        'vision_model_version',
        'embedding_model',
        'embedding_model_version',
        'prompt_version',
        'caption',
        'concepts',
        'ocr',
        'raw_response',
        'processing_ms',
    ];

    protected $casts = [
        'concepts' => 'array',
        'ocr' => 'array',
        'raw_response' => 'array',
        'processing_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }
}
