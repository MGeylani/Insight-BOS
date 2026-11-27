<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = ['currency_code', 'date', 'rate', 'entered_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'rate' => 'decimal:6'];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
