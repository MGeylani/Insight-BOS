<?php

namespace App\Models;

use App\Models\Concerns\HasLineAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One invoice line. May bill a contract instalment (contract_schedule_id) or tracked time (time_entries.invoice_item_id). */
class InvoiceItem extends Model
{
    use HasLineAmounts;

    protected $fillable = [
        'invoice_id', 'service_id', 'service_category_id', 'contract_schedule_id', 'name', 'details', 'quantity', 'unit',
        'unit_price', 'discount_percent', 'tax_rate', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2', 'tax_rate' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ContractSchedule::class, 'contract_schedule_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }
}
