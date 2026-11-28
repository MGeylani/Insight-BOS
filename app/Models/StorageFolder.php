<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageFolder extends Model
{
    protected $fillable = ['storage_connection_id', 'key', 'external_id'];
}
