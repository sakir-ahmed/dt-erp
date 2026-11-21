<?php

namespace App\Http\Middleware;

use App\Actions\WorkspaceSettings;
use App\Enums\WorkspacePermission;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        try {
            $defaultLocale = app(WorkspaceSettings::class)->application()['default_locale'];
        } catch (\Throwable) {
            // Settings may be unavailable before the first migration; the
            // configured application locale still works.
            $defaultLocale = config('app.locale');
        }
        app()->setLocale($request->session()->get('locale', $defaultLocale));

        return [
            ...parent::share($request),
            'canManageConverseKnowledge' => fn () => $request->user()?->can(WorkspacePermission::ManageConverseKnowledge->value) ?? false,
            'canManageConverseChannels' => fn () => $request->user()?->can(WorkspacePermission::ManageConverseChannels->value) ?? false,
            'canViewConverseInbox' => fn () => $request->user()?->can(WorkspacePermission::ViewConverseInbox->value) ?? false,
            'canViewUsers' => fn () => $request->user()?->can(WorkspacePermission::ViewUsers->value) ?? false,
            'canManageSettings' => fn () => $request->user()?->can(WorkspacePermission::ManageSettings->value) ?? false,
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
