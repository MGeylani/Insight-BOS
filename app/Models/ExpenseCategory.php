<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** kind: direct (cost of client work, counts against project profit) | overhead (running the agency). */
class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'kind', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
