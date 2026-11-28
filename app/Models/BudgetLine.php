<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = ['budget_id', 'kind', 'category_id', 'planned', 'alert_level', 'sort'];

    protected function casts(): array
    {
        return ['planned' => 'decimal:2'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id')->withTrashed();
    }
}
