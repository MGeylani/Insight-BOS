<?php

namespace App\Models;

use App\Models\Concerns\NormalizesPhone;
use App\Models\Concerns\VisibleByScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, NormalizesPhone, SoftDeletes, VisibleByScope;

    protected $fillable = [
        'name', 'legal_name', 'type', 'industry', 'email', 'phone', 'website', 'country', 'city',
        'address', 'tax_number', 'currency', 'source', 'owner_id', 'notes', 'created_by', 'task_prefix',
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'task_seq' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest('id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->latest('id');
    }

    public function taskLists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('sort')->orderBy('id');
    }
}
