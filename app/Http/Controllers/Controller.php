<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /** Abort unless the current user's grant for $permission covers this record (own / assigned / all). */
    protected function authorizeRecord(Model $record, string $permission): void
    {
        abort_unless(method_exists($record, 'isVisibleTo') && $record->isVisibleTo(request()->user(), $permission), 403);
    }
}
