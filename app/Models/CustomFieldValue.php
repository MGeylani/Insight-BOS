<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomFieldValue extends Model
{
    protected $fillable = ['task_id', 'custom_field_id', 'value', 'sort_value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id')->withTrashed();
    }
}
