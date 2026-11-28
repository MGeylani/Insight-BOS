<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\User;
use App\Services\Tasks\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sharing a client, project or list with team members (beyond their scope) or with guests. JSON for the share dialog. */
class AccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$type, $id] = $this->place($request);

        return response()->json(Access::payload($type, $id));
    }

    public function store(Request $request): JsonResponse
    {
        [$type, $id] = $this->place($request);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'level' => ['required', Rule::in(array_keys(AccessGrant::LEVELS))]]);
        $to = User::findOrFail($data['user_id']);
        abort_if($to->is_super_admin, 422, 'مدير النظام يرى كل شيء.');
        Access::grant($request->user(), $to, $type, $id, $data['level']);

        return response()->json(Access::payload($type, $id));
    }

    /** Invite a guest by email: returns a link to set their password, to send them. */
    public function invite(Request $request): JsonResponse
    {
        [$type, $id] = $this->place($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190'],
            'level' => ['required', Rule::in(array_keys(AccessGrant::LEVELS))],
        ]);
        $r = Access::invite($request->user(), $data['name'], $data['email'], $type, $id, $data['level']);

        return response()->json([...Access::payload($type, $id), 'link' => $r['link'], 'invited' => $r['user']->only('id', 'name', 'email')]);
    }

    /** A fresh set-password link for a guest (when the first one expired or was lost). */
    public function link(Request $request, User $user): JsonResponse
    {
        abort_unless($user->is_guest && AccessGrant::where('user_id', $user->id)->get()->contains(fn ($g) => Access::canShare($request->user(), $g->scope_type, $g->scope_id)), 403);

        return response()->json(['link' => Access::passwordLink($user)]);
    }

    public function update(Request $request, AccessGrant $grant): JsonResponse
    {
        abort_unless(Access::canShare($request->user(), $grant->scope_type, $grant->scope_id), 403);
        $grant->update($request->validate(['level' => ['required', Rule::in(array_keys(AccessGrant::LEVELS))]]));

        return response()->json(Access::payload($grant->scope_type, $grant->scope_id));
    }

    public function destroy(Request $request, AccessGrant $grant): JsonResponse
    {
        abort_unless(Access::canShare($request->user(), $grant->scope_type, $grant->scope_id), 403);
        Access::revoke($grant);

        return response()->json(Access::payload($grant->scope_type, $grant->scope_id));
    }

    /** @return array{0: string, 1: int} */
    private function place(Request $request): array
    {
        $data = $request->validate(['type' => ['required', Rule::in(['client', 'project', 'list'])], 'id' => ['required', 'integer']]);
        Access::target($data['type'], (int) $data['id']);
        abort_unless(Access::canShare($request->user(), $data['type'], (int) $data['id']), 403);

        return [$data['type'], (int) $data['id']];
    }
}
