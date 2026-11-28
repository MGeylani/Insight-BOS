<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaskType extends Model
{
    protected $fillable = ['name', 'icon', 'color', 'is_milestone', 'is_default', 'sort'];

    protected function casts(): array
    {
        return ['is_milestone' => 'boolean', 'is_default' => 'boolean', 'sort' => 'integer'];
    }

    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'task_type_fields');
    }

    /** @return array<string, mixed> */
    public function toUi(): array
    {
        return [...$this->only('id', 'name', 'icon', 'color', 'is_milestone', 'is_default'), 'field_ids' => $this->relationLoaded('fields') ? $this->fields->pluck('id')->all() : []];
    }
}
