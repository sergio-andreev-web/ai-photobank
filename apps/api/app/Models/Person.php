<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person of the face directory (ADR-0015). Named only by an admin.
 */
class Person extends Model
{
    protected $table = 'people';

    protected $fillable = ['name', 'note', 'created_by'];

    protected static function booted(): void
    {
        static::saving(static function (Person $person): void {
            $person->name_normalized = mb_strtolower($person->name);
        });
    }

    public function faces(): HasMany
    {
        return $this->hasMany(Face::class);
    }
}
