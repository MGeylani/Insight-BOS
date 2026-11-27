<?php

namespace App\Models;

use App\Models\Concerns\NormalizesPhone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, NormalizesPhone, SoftDeletes;

    protected $fillable = ['client_id', 'name', 'job_title', 'email', 'phone', 'whatsapp', 'is_primary', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Only one primary contact per client.
        static::saved(function (Contact $c) {
            if ($c->is_primary) {
                static::where('client_id', $c->client_id)->whereKeyNot($c->id)->update(['is_primary' => false]);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
