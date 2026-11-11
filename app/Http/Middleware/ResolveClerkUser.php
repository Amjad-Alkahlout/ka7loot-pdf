<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidClerkSessionException;
use App\Models\User;
use App\Services\Auth\ClerkSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveClerkUser
{
    public function handle(Request $request, Closure $next, bool $required = false): Response
    {
        $token = app(ClerkSessionService::class)->token(
            $request->header('Authorization'),
            $request->cookie('__session')
        );

        if ($token === null) {
            if ($required && ! Auth::check()) {
                return $this->unauthenticated($request);
            }

            return $next($request);
        }

        try {
            $identity = app(ClerkSessionService::class)->resolve($token);
            $user = User::where('clerk_id', $identity['subject'])->first();

            if (! $user) {
                $emailMatch = User::whereRaw('LOWER(email) = ?', [$identity['email']])->first();
                if ($emailMatch) {
                    throw new InvalidClerkSessionException('A local account already uses this email address.');
                }

                $user = User::create([
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    'clerk_id' => $identity['subject'],
                    'email_verified_at' => now(),
                    'password' => null,
                ]);
            } else {
                $user->forceFill([
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }

            Auth::login($user);
        } catch (InvalidClerkSessionException $e) {
            Log::notice('Clerk session rejected.', ['reason' => $e->getMessage()]);

            if ($required) {
                return $this->unauthenticated($request);
            }
        }

        return $next($request);
    }

    protected function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() || $request->header('X-Livewire')) {
            return response()->json(['message' => __('ui.clerk_session_invalid')], 401);
        }

        return redirect()->route('home')->withErrors([
            'auth' => __('ui.clerk_session_invalid'),
        ]);
    }
}
