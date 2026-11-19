<?php

namespace App\Http\Controllers;

use App\Actions\Users\SaveStaffUser;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Http\Requests\SaveStaffUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserDirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $rawSearch = $request->query('search');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 100) : '';
        $viewer = $request->user();
        $canManageUsers = ! $viewer?->is_demo && $viewer?->can(WorkspacePermission::ManageUsers->value);
        $users = User::query()
            ->with('roles')
            ->where('is_demo', (bool) $viewer?->is_demo)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%");
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->appends(['search' => $search])
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->all(),
                'editable' => $canManageUsers && $user->hasExactRoles([WorkspaceRole::Staff->value]),
            ]);

        return Inertia::render('Users/Index', [
            'search' => $search,
            'canManageUsers' => $canManageUsers,
            'users' => [
                'data' => $users->items(),
                'first' => $users->firstItem(),
                'last' => $users->lastItem(),
                'total' => $users->total(),
                'previous' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
        ]);
    }

    public function store(SaveStaffUserRequest $request, SaveStaffUser $save): RedirectResponse
    {
        $save($request->validated());

        Inertia::flash('success', __('workspace.user_created'));

        return to_route('users.index');
    }

    public function update(SaveStaffUserRequest $request, User $user, SaveStaffUser $save): RedirectResponse
    {
        $save($request->validated(), $user);

        Inertia::flash('success', __('workspace.user_updated'));

        return to_route('users.index');
    }
}
