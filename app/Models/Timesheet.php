<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timesheet extends Model
{
    public const STATUSES = ['draft' => 'مسودة', 'submitted' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض'];

    protected $fillable = ['user_id', 'week_start', 'status', 'note'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['submitted', 'approved'], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
