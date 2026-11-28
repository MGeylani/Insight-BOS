<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomField extends Model
{
    use SoftDeletes;

    public const TYPES = ['text', 'number', 'money', 'dropdown', 'labels', 'date', 'checkbox', 'people', 'url', 'rating', 'percent', 'file', 'formula'];

    protected $fillable = ['name', 'type', 'options', 'scope_type', 'scope_id', 'sort', 'created_by'];

    protected function casts(): array
    {
        return ['options' => 'array', 'sort' => 'integer'];
    }

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(TaskType::class, 'task_type_fields');
    }

    /** Fields whose scope covers a location: everywhere, the client, the project or the list. */
    public function scopeForLocation(Builder $q, ?int $clientId, ?int $projectId, ?int $listId): Builder
    {
        return $q->where(fn ($w) => $w->where('scope_type', 'all')
            ->when($clientId, fn ($x) => $x->orWhere(fn ($y) => $y->where('scope_type', 'client')->where('scope_id', $clientId)))
            ->when($projectId, fn ($x) => $x->orWhere(fn ($y) => $y->where('scope_type', 'project')->where('scope_id', $projectId)))
            ->when($listId, fn ($x) => $x->orWhere(fn ($y) => $y->where('scope_type', 'list')->where('scope_id', $listId))));
    }

    /** @return array<string, mixed> */
    public function toUi(): array
    {
        return [
            ...$this->only('id', 'name', 'type', 'scope_type', 'scope_id', 'sort'),
            'options' => $this->options ?? (object) [],
            'type_ids' => $this->relationLoaded('types') ? $this->types->pluck('id')->all() : [],
        ];
    }
}
