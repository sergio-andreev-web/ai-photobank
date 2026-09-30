<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchSettings extends Model
{
    protected $table = 'research_settings';

    protected $attributes = ['object_research_enabled' => false, 'object_research_provider' => 'codex_cli'];

    protected $fillable = ['id', 'object_research_enabled', 'object_research_provider'];

    protected function casts(): array
    {
        return ['object_research_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
