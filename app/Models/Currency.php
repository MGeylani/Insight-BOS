<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return ['is_base' => 'boolean', 'is_active' => 'boolean', 'decimals' => 'integer'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency_code', 'code');
    }

    public static function base(): self
    {
        return static::where('is_base', true)->firstOrFail();
    }
}
