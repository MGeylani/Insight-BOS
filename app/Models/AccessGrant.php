<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessGrant extends Model
{
    public const LEVELS = ['view' => 'عرض وتعليق', 'edit' => 'تعديل وإنشاء مهام'];

    protected $fillable = ['user_id', 'scope_type', 'scope_id', 'level', 'granted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
