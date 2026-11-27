<?php

namespace App\Models;

use App\Models\Concerns\NormalizesPhone;
use App\Models\Concerns\VisibleByScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, NormalizesPhone, SoftDeletes, VisibleByScope;

    public const CONVERTED = 'converted';

    protected $fillable = [
        'name', 'company_name', 'job_title', 'email', 'phone', 'source', 'stage', 'rating',
        'expected_value', 'currency', 'interest', 'owner_id', 'position', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['expected_value' => 'decimal:2', 'converted_at' => 'datetime'];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('stage', '!=', self::CONVERTED)->whereNull('merged_into_id');
    }

    public function isConverted(): bool
    {
        return $this->stage === self::CONVERTED;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'merged_into_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }

    public function displayName(): string
    {
        return $this->company_name ? "{$this->company_name} — {$this->name}" : $this->name;
    }
}
