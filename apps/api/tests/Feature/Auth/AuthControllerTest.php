<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_returns_no_content(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
    }

    public function test_login_succeeds_with_valid_admin_credentials(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);

        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'secret-password',
            ], $this->csrfHeaders());

        $response->assertOk();
        $response->assertJson([
            'id' => $user->id,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
        ]);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);

        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ], $this->csrfHeaders());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertGuest('web');
    }

    public function test_login_rejects_non_admin_role(): void
    {
        User::create([
            'name' => 'Moderator',
            'email' => 'mod@example.test',
            'password' => 'secret-password',
            'role' => 'moderator',
        ]);

        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', [
                'email' => 'mod@example.test',
                'password' => 'secret-password',
            ], $this->csrfHeaders());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertGuest('web');
    }

    public function test_login_without_csrf_token_returns_419(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);

        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'secret-password',
            ]);

        $response->assertStatus(419);
        $response->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/login', [], $this->csrfHeaders());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_current_user(): void
    {
        $user = $this->loginAsAdmin();

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('email', $user->email);
    }

    public function test_logout_is_idempotent_without_a_session(): void
    {
        $response = $this->withSession(['_token' => $this->csrfToken()])
            ->postJson('/api/auth/logout', [], $this->csrfHeaders());

        $response->assertNoContent();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->loginAsAdmin();

        $response = $this->postJson('/api/auth/logout', [], $this->csrfHeaders());

        $response->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_every_response_carries_a_request_id_header(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertHeader('X-Request-ID');
    }
}
