<?php

namespace App\Http\Controllers;

use App\Actions\LocalQuickSignIn;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Login', [
            'localQuickSignInUsers' => fn () => User::localQuickSignInEnabled()
                ? LocalQuickSignIn::eligibleAccounts()->map->only(['id', 'name'])->values()
                : [],
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt([...$request->validated(), 'is_demo' => false])) {
            throw ValidationException::withMessages([
                'email' => __('workspace.credentials_invalid'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $locale = $request->session()->get('locale');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($locale !== null) {
            $request->session()->put('locale', $locale);
        }

        return to_route('login');
    }
}
