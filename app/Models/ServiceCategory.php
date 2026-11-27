<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    protected $fillable = ['name', 'tax_rate', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['tax_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
