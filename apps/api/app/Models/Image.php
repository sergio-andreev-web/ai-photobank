<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Image extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'batch_id',
        'original_name',
        'storage_key',
        'preview_key',
        'thumbnail_key',
        'mime_type',
        'extension',
        'width',
        'height',
        'file_size',
        'sha256',
        'caption',
        'ocr_text',
        'status',
        'error_code',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'image_tags')
            ->withPivot(['source', 'confidence', 'locked'])
            ->withTimestamps();
    }

    public function imageTags(): HasMany
    {
        return $this->hasMany(ImageTag::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(ImageTagSuppression::class);
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(ImageEmbedding::class);
    }

    public function activeEmbedding(): HasOne
    {
        return $this->hasOne(ImageEmbedding::class)->where('active', true);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(ImageAnalysis::class);
    }

    public function processingTasks(): HasMany
    {
        return $this->hasMany(ProcessingTask::class);
    }

    public function candidateLinks(): HasMany
    {
        return $this->hasMany(TagCandidateImage::class);
    }
}
