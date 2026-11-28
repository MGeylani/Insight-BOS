<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Planned spend (USD) for a project, a cost center, or the company when both are empty. Actuals: App\Services\Finance\Budgets. */
class Budget extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'project_id', 'cost_center_id', 'period_start', 'period_end', 'alert_percent', 'notes', 'owner_id', 'created_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date'];
    }

    public function targetLabel(): string
    {
        return $this->project ? 'المشروع '.$this->project->label() : ($this->costCenter ? 'مركز '.$this->costCenter->name : 'الشركة');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('sort')->orderBy('id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }
}
