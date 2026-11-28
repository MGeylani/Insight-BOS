<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests (clients and outside collaborators) use only the task pages: the tasks shared with them, My Work,
 * the inbox, files and proofing on those tasks, and their profile. Everything else is closed to them,
 * whatever permissions a guest account might be given by mistake.
 */
class RestrictGuests
{
    private const ALLOWED = [
        'logout', 'profile.edit', 'profile.update', 'profile.password',
        'attachments.store', 'attachments.open', 'attachments.inline', 'attachments.retry', 'attachments.destroy',
        'notifications.index', 'notifications.read', 'notifications.unread', 'notifications.destroy', 'inbox',
        'my-work', 'my-work.calendar', 'reminders.store', 'reminders.update', 'reminders.destroy',
        'tasks.index', 'tasks.show', 'tasks.lookup', 'tasks.watch', 'tasks.store', 'tasks.update', 'tasks.fields.value',
        'tasks.comments.store', 'tasks.comments.update', 'tasks.comments.destroy', 'tasks.comments.resolve',
        'tasks.checklists.items.update', 'tasks.views.store', 'tasks.views.update', 'tasks.views.destroy',
        'tasks.proof', 'tasks.proof.comment', 'tasks.proof.update', 'tasks.proof.destroy', 'tasks.proof.version',
        'forms.show', 'forms.submit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->is_guest) {
            return $next($request);
        }
        $name = $request->route()?->getName();
        if ($name === null || in_array($name, self::ALLOWED, true) || str_starts_with($name, 'password.') || $name === 'login') {
            return $next($request);
        }
        if ($name === 'dashboard') {
            return redirect('/my-work');
        }
        abort(403, 'هذه الصفحة غير متاحة لحسابات الضيوف.');
    }
}
