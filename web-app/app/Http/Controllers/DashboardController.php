<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canViewUsers = $user?->can(WorkspacePermission::ViewUsers->value) ?? false;

        return Inertia::render('Dashboard', [
            'name' => $user?->name,
            'accountCount' => $canViewUsers
                ? User::query()->where('is_demo', $user->is_demo)->count()
                : null,
        ]);
    }
}
