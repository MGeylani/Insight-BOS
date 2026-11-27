<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageConnection extends Model
{
    protected $fillable = [
        'provider', 'status', 'account_label', 'root_folder_id', 'root_folder_name', 'credentials',
        'max_upload_mb', 'allowed_types', 'is_active', 'connected_by', 'connected_at', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'allowed_types' => 'array',
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
