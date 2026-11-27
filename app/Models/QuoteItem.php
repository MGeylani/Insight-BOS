<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quote line. Amounts are computed from quantity, price, discount and tax; never stored.
 */
class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id', 'service_id', 'service_category_id', 'name', 'details', 'quantity', 'unit',
        'unit_price', 'discount_percent', 'tax_rate', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2', 'tax_rate' => 'decimal:2',
        ];
    }

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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }
}
