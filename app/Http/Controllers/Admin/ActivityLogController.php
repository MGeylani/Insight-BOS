<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $action = (string) $request->query('action', '');

        $logs = ActivityLog::with('user:id,name')
            ->when($action !== '', fn ($q) => $q->where('action', 'like', "$action%"))
            ->latest('created_at')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn (ActivityLog $l) => [
                'id' => $l->id,
                'user' => $l->user?->name,
                'action' => $l->action,
                'description' => $l->description,
                'changes' => $l->changes,
                'ip' => $l->ip_address,
                'at' => $l->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/ActivityLog', ['logs' => $logs, 'filters' => ['action' => $action]]);
    }
}
