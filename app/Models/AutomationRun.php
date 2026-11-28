<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['automation_id', 'task_id', 'status', 'message', 'dedupe_key'];
}
