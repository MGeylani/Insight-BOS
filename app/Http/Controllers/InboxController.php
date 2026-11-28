<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Inbox: every in-app notification, newest first, with read / unread handling. */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $unread = $request->boolean('unread');
        $items = $request->user()->notifications()
            ->when($unread, fn ($q) => $q->whereNull('read_at'))
            ->when($request->input('kind'), fn ($q, $k) => $q->where('data', 'like', '%"kind":"'.str_replace(['%', '_', '"'], '', $k).'%'))
            ->paginate(30)->withQueryString();

        return Inertia::render('tasks/Inbox', [
            'items' => $items->through(fn ($n) => [
                'id' => $n->id, 'data' => $n->data, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
            ]),
            'filters' => ['unread' => $unread, 'kind' => $request->input('kind')],
            'counts' => ['unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function unread(Request $request): RedirectResponse
    {
        $ids = (array) $request->input('ids', []);
        $request->user()->notifications()->whereIn('id', $ids)->update(['read_at' => null]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $q = $request->user()->notifications();
        $request->boolean('read_only') ? $q->whereNotNull('read_at')->delete() : $q->whereIn('id', (array) $request->input('ids', []))->delete();

        return back();
    }
}
