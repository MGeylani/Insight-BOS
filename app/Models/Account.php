<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A cash box, bank account or e-wallet. Its balance is computed (App\Services\Finance\Accounts::balance). */
class Account extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'currency', 'opening_balance', 'opening_date', 'details', 'is_active', 'sort', 'created_by'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'opening_date' => 'date', 'is_active' => 'boolean'];
    }

    public function label(): string
    {
        return "{$this->name} ({$this->currency})";
    }
}
