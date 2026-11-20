<?php

namespace App\Http\Controllers;

use App\Actions\LocalQuickSignIn;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalQuickSignInController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(User::localQuickSignInEnabled($request), 404);

        $userId = filter_var($request->input('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($userId === false, 404);

        $account = LocalQuickSignIn::eligibleAccounts($userId)->first();
        abort_unless($account, 404);

        Auth::login($account);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
