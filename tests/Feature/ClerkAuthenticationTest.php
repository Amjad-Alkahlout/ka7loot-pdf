<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\ClerkSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class ClerkAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('clerk.jwt_key', null);
        Config::set('clerk.issuer', 'https://test.clerk.accounts.dev');
        Config::set('clerk.authorized_parties', ['http://localhost:8000']);

        Route::middleware(['web', 'auth'])
            ->get('/testing/clerk-protected', fn () => response()->json(['user_id' => auth()->id()]));
    }

    public function test_valid_clerk_session_maps_to_local_user(): void
    {
        $service = Mockery::mock(ClerkSessionService::class);
        $service->shouldReceive('token')->once()->andReturn('valid-token');
        $service->shouldReceive('resolve')->once()->with('valid-token')->andReturn([
            'subject' => 'user_123',
            'claims' => new \stdClass,
            'email' => 'clerk@example.com',
            'name' => 'Clerk User',
        ]);
        $this->app->instance(ClerkSessionService::class, $service);

        $response = $this->withHeader('Authorization', 'Bearer valid-token')
            ->getJson('/testing/clerk-protected');

        $response->assertOk()->assertJson(['user_id' => User::first()->id]);
        $this->assertDatabaseHas('users', [
            'clerk_id' => 'user_123',
            'email' => 'clerk@example.com',
            'password' => null,
        ]);
    }

    public function test_missing_or_expired_clerk_sessions_are_denied(): void
    {
        $this->getJson('/testing/clerk-protected')->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer expired.invalid.token')
            ->getJson('/testing/clerk-protected')
            ->assertUnauthorized();
    }

    public function test_clerk_controls_are_rendered_and_session_sync_uses_local_authentication(): void
    {
        $page = $this->get('/');

        $page->assertOk()
            ->assertSee('data-clerk-sign-in', false)
            ->assertSee('data-clerk-sign-up', false)
            ->assertSee('syncUrl:', false);

        $service = Mockery::mock(ClerkSessionService::class);
        $service->shouldReceive('token')->once()->andReturn('valid-token');
        $service->shouldReceive('resolve')->once()->with('valid-token')->andReturn([
            'subject' => 'user_sync',
            'claims' => new \stdClass,
            'email' => 'sync@example.com',
            'name' => 'Sync User',
        ]);
        $this->app->instance(ClerkSessionService::class, $service);

        $this->withHeader('Authorization', 'Bearer valid-token')
            ->postJson(route('auth.sync'))
            ->assertOk()
            ->assertJson(['authenticated' => true]);
    }

    public function test_local_authentication_survives_a_follow_up_request_without_a_clerk_token(): void
    {
        $service = Mockery::mock(ClerkSessionService::class);
        $service->shouldReceive('token')->twice()->andReturn('valid-token', null);
        $service->shouldReceive('resolve')->once()->with('valid-token')->andReturn([
            'subject' => 'user_persistent_session',
            'claims' => new \stdClass,
            'email' => 'persistent@example.com',
            'name' => 'Persistent User',
        ]);
        $this->app->instance(ClerkSessionService::class, $service);

        $this->withHeader('Authorization', 'Bearer valid-token')
            ->postJson(route('auth.sync'))
            ->assertOk()
            ->assertJson(['authenticated' => true]);

        $this->withoutHeader('Authorization')
            ->getJson('/testing/clerk-protected')
            ->assertOk()
            ->assertJson(['user_id' => User::where('clerk_id', 'user_persistent_session')->value('id')]);
    }

    public function test_renewed_clerk_session_is_resolved_on_the_next_request(): void
    {
        $service = Mockery::mock(ClerkSessionService::class);
        $service->shouldReceive('token')->twice()->andReturn('token-one', 'token-two');
        $service->shouldReceive('resolve')->once()->with('token-one')->andReturn([
            'subject' => 'user_one',
            'claims' => new \stdClass,
            'email' => 'one@example.com',
            'name' => 'User One',
        ]);
        $service->shouldReceive('resolve')->once()->with('token-two')->andReturn([
            'subject' => 'user_two',
            'claims' => new \stdClass,
            'email' => 'two@example.com',
            'name' => 'User Two',
        ]);
        $this->app->instance(ClerkSessionService::class, $service);

        $this->withHeader('Authorization', 'Bearer token-one')
            ->getJson('/testing/clerk-protected')
            ->assertOk()
            ->assertJson(['user_id' => 1]);

        $this->withHeader('Authorization', 'Bearer token-two')
            ->getJson('/testing/clerk-protected')
            ->assertOk()
            ->assertJson(['user_id' => 2]);
    }
}
