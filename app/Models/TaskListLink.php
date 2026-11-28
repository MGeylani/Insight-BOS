<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskListLink extends Model
{
    protected $fillable = ['task_id', 'task_list_id', 'position'];
}
