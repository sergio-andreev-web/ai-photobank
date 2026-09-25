<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $table = 'upload_batches';

    protected $fillable = [
        'name',
        'status',
        'total_images',
        'processed_images',
        'failed_images',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
