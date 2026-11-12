<?php

namespace App\Services\Auth;

use App\Exceptions\InvalidClerkSessionException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use stdClass;

class ClerkSessionService
{
    public function token(?string $authorization, ?string $cookie): ?string
    {
        if ($authorization && Str::startsWith($authorization, 'Bearer ')) {
            return trim(Str::after($authorization, 'Bearer '));
        }

        return filled($cookie) ? $cookie : null;
    }

    /**
     * @return array{subject: string, claims: stdClass, email: string, name: string}
     */
    public function resolve(string $token): array
    {
        $key = config('clerk.jwt_key');
        $issuer = config('clerk.issuer');
        if (blank($key) || blank($issuer)) {
            throw new InvalidClerkSessionException('Clerk session verification is not configured.');
        }

        try {
            $claims = JWT::decode($token, new Key($key, 'RS256'));
        } catch (\Throwable $e) {
            throw new InvalidClerkSessionException('The Clerk session token is invalid: '.$e->getMessage(), 0, $e);
        }

        if (($claims->iss ?? null) !== $issuer) {
            throw new InvalidClerkSessionException('The Clerk session issuer is invalid.');
        }

        $now = time();
        if (($claims->exp ?? 0) < $now || isset($claims->nbf) && $claims->nbf > $now) {
            throw new InvalidClerkSessionException('The Clerk session token is expired or not yet valid.');
        }

        $authorizedParties = config('clerk.authorized_parties', []);
        if ($authorizedParties !== [] && (! isset($claims->azp) || ! in_array($claims->azp, $authorizedParties, true))) {
            throw new InvalidClerkSessionException('The Clerk session authorized party is invalid.');
        }

        $subject = (string) ($claims->sub ?? '');
        if ($subject === '') {
            throw new InvalidClerkSessionException('The Clerk session has no user subject.');
        }

        $profile = $this->profile($subject);
        $email = $this->verifiedPrimaryEmail($profile);
        if ($email === null) {
            throw new InvalidClerkSessionException('The Clerk account has no verified email address.');
        }

        return [
            'subject' => $subject,
            'claims' => $claims,
            'email' => $email,
            'name' => $this->profileName($profile, $email),
        ];
    }

    public function deleteUser(string $clerkId): void
    {
        if (blank(config('clerk.secret_key'))) {
            throw new \RuntimeException('Clerk server access is not configured.');
        }

        try {
            $response = Http::withToken(config('clerk.secret_key'))
                ->acceptJson()
                ->timeout((int) config('clerk.api_timeout', 10))
                ->delete(rtrim(config('clerk.api_url'), '/').'/users/'.rawurlencode($clerkId));

            if ($response->status() === 404 || $response->successful()) {
                return;
            }

            throw new \RuntimeException('Clerk account deletion was rejected with status '.$response->status().'.');
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Clerk account deletion was rejected')) {
                throw $e;
            }

            throw new \RuntimeException('The Clerk account could not be deleted.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(string $clerkId): array
    {
        $secret = config('clerk.secret_key');
        if (blank($secret)) {
            throw new InvalidClerkSessionException('Clerk server access is not configured.');
        }

        try {
            return Http::withToken($secret)
                ->acceptJson()
                ->get(rtrim(config('clerk.api_url'), '/').'/users/'.rawurlencode($clerkId))
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw new InvalidClerkSessionException('The Clerk account could not be verified.', 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function verifiedPrimaryEmail(array $profile): ?string
    {
        $primaryId = $profile['primary_email_address_id'] ?? null;
        foreach ($profile['email_addresses'] ?? [] as $address) {
            if (($address['id'] ?? null) !== $primaryId) {
                continue;
            }

            if (($address['verification']['status'] ?? null) !== 'verified') {
                return null;
            }

            return strtolower((string) ($address['email_address'] ?? '')) ?: null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function profileName(array $profile, string $email): string
    {
        $name = trim(implode(' ', array_filter([
            $profile['first_name'] ?? null,
            $profile['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : strstr($email, '@', true);
    }
}
