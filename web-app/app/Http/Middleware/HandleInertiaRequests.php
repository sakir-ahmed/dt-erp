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
            'canViewProducts' => fn () => $request->user()?->can(WorkspacePermission::ViewProducts->value) ?? false,
            'canViewUsers' => fn () => $request->user()?->can(WorkspacePermission::ViewUsers->value) ?? false,
            'signedIn' => fn () => $request->user() !== null,
            'locale' => app()->getLocale(),
            'labels' => __('workspace'),
        ];
    }
}
