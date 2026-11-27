<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    protected $fillable = ['type', 'label', 'prefix', 'padding', 'yearly_reset', 'current_year', 'last_number'];

    protected function casts(): array
    {
        return ['yearly_reset' => 'boolean', 'padding' => 'integer', 'last_number' => 'integer', 'current_year' => 'integer'];
    }

    public function format(int $number, int $year): string
    {
        $parts = [$this->prefix];
        if ($this->yearly_reset) {
            $parts[] = (string) $year;
        }
        $parts[] = str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }
}
