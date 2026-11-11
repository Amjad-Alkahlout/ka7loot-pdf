<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\File\DailyFileQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function callback(DailyFileQuotaService $quota): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
            $googleId = (string) $googleUser->getId();
            $email = strtolower((string) $googleUser->getEmail());

            if ($googleId === '' || $email === '') {
                throw new \RuntimeException('Google did not provide the required account information.');
            }

            $user = User::where('google_id', $googleId)->first();
            if (! $user) {
                $emailMatch = User::whereRaw('LOWER(email) = ?', [$email])->first();
                if ($emailMatch) {
                    return redirect()->route('home')->withErrors([
                        'auth' => __('ui.google_account_link_required'),
                    ]);
                }

                $user = User::create([
                    'name' => $googleUser->getName() ?: strstr($email, '@', true),
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar' => $googleUser->getAvatar(),
                    'email_verified_at' => now(),
                    'password' => Str::random(64),
                ]);
            }

            $guestKey = session('file_quota_guest_key');
            Auth::login($user, remember: true);
            $quota->mergeGuestIntoUser($user->id, $guestKey);
            session()->regenerate();
            session()->forget('file_quota_guest_key');

            return redirect()->intended(route('home'));
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed.', ['message' => $e->getMessage()]);

            return redirect()->route('home')->withErrors([
                'auth' => __('ui.google_sign_in_failed'),
            ]);
        }
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('home');
    }
}
