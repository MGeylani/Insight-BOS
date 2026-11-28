<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatus extends Model
{
    public const GROUPS = ['not_started', 'active', 'done', 'closed'];

    public const COMPLETE_GROUPS = ['done', 'closed'];

    protected $fillable = ['status_scheme_id', 'name', 'color', 'group', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function isComplete(): bool
    {
        return in_array($this->group, self::COMPLETE_GROUPS, true);
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(StatusScheme::class, 'status_scheme_id');
    }

    /** @return array<string, mixed> */
    public function toUi(): array
    {
        return $this->only('id', 'name', 'color', 'group', 'sort');
    }
}
