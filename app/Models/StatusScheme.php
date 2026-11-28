<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusScheme extends Model
{
    protected $fillable = ['name', 'is_template', 'is_default'];

    protected function casts(): array
    {
        return ['is_template' => 'boolean', 'is_default' => 'boolean'];
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class)->orderBy('sort')->orderBy('id');
    }

    /** The template new lists start from. */
    public static function defaultTemplate(): ?self
    {
        return self::where('is_template', true)->orderByDesc('is_default')->orderBy('id')->first();
    }
}
