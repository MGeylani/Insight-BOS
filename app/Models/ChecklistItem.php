<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $fillable = ['checklist_id', 'parent_id', 'text', 'assignee_id', 'done', 'sort'];

    protected function casts(): array
    {
        return ['done' => 'boolean', 'done_at' => 'datetime', 'sort' => 'integer'];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    /** 0 for a top-level item. */
    public function depth(): int
    {
        $d = 0;
        $p = $this->parent;
        while ($p && $d < 5) {
            $d++;
            $p = $p->parent;
        }

        return $d;
    }
}
