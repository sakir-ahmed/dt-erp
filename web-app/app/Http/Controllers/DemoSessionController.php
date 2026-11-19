<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoSessionController extends Controller
{
    public function __invoke(Request $request, int $user): RedirectResponse
    {
        abort_unless(User::demoLoginEnabled(), 404);

        Auth::login(User::query()->where('is_demo', true)->findOrFail($user));
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
