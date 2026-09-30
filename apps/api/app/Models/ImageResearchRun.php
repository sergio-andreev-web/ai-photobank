<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageResearchRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
