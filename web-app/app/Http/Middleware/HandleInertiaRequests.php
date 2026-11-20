<?php

namespace App\Http\Middleware;

use App\Enums\WorkspacePermission;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        app()->setLocale($request->session()->get('locale', config('app.locale')));

        return [
            ...parent::share($request),
            'canManageConverseKnowledge' => fn () => $request->user()?->can(WorkspacePermission::ManageConverseKnowledge->value) ?? false,
            'canManageConverseChannels' => fn () => $request->user()?->can(WorkspacePermission::ManageConverseChannels->value) ?? false,
            'canViewConverseInbox' => fn () => $request->user()?->can(WorkspacePermission::ViewConverseInbox->value) ?? false,
            'canViewUsers' => fn () => $request->user()?->can(WorkspacePermission::ViewUsers->value) ?? false,
            'account' => function () use ($request): ?array {
                $user = $request->user();

                return $user ? [
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                ] : null;
            },
            'signedIn' => fn () => $request->user() !== null,
            'locale' => app()->getLocale(),
            'labels' => __('workspace'),
        ];
    }
}
