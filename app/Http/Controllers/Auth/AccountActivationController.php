<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountActivationController extends Controller
{
    /**
     * Activate the user account using the unique activation token.
     */
    public function activate(Request $request, string $token): RedirectResponse
    {
        $user = User::findByActivationToken($token);

        if (! $user) {
            if (Auth::check()) {
                return redirect()->route('dashboard')->with('error', __('This activation link is invalid or has already been used.'));
            }

            return redirect()->route('login')->with('error', __('This activation link is invalid or has already been used. Please log in.'));
        }

        // Activate the user and fire the Verified event
        if ($user->activateAccount()) {
            event(new Verified($user));
        }

        // Log the user in on this first-registration activation click
        Auth::login($user);
        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', __('Your account has been activated successfully! Welcome to DuoAsset.'));
    }
}
