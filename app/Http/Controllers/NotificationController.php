<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->latest()->limit(20)->get()
            ->map(fn ($n) => ['id' => $n->id, 'data' => $n->data, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String()]));
    }

    public function read(Request $request): RedirectResponse
    {
        $ids = (array) $request->input('ids', []);
        $request->user()->unreadNotifications()->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return back();
    }
}
