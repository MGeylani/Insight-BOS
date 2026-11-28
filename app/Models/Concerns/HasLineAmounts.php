<?php

namespace App\Models\Concerns;

/**
 * Amounts of a document line (quote or invoice) from quantity, unit price, discount % and tax %.
 * Computed, never stored; each step rounds to cents.
 */
trait HasLineAmounts
{
    public function gross(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    public function discountAmount(): float
    {
        return round($this->gross() * (float) $this->discount_percent / 100, 2);
    }

    /** Line total after discount, before tax. */
    public function net(): float
    {
        return round($this->gross() - $this->discountAmount(), 2);
    }

    public function taxAmount(): float
    {
        return round($this->net() * (float) $this->tax_rate / 100, 2);
    }
}
