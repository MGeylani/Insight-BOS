<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A supplier or freelancer. What we owe is computed from approved credit expenses minus vendor payments. */
class Vendor extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'phone', 'email', 'tax_number', 'payment_details', 'notes', 'created_by'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }
}
