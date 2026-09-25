<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * Behind nginx every request comes from the proxy's address; the login throttle
 * (throttle:5,1) must still count attempts per real client IP.
 */
class LoginThrottleTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    public function test_login_throttle_is_tracked_per_forwarded_client_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLoginFrom('203.0.113.10')->assertStatus(422);
        }

        $this->attemptLoginFrom('203.0.113.10')->assertStatus(429);
        $this->attemptLoginFrom('198.51.100.20')->assertStatus(422);
    }

    private function attemptLoginFrom(string $clientIp): TestResponse
    {
        return $this->withSession(['_token' => $this->csrfToken()])
            ->withServerVariables(['REMOTE_ADDR' => '172.18.0.10'])
            ->withHeader('X-Forwarded-For', $clientIp)
            ->postJson('/api/auth/login', [
                'email' => 'nobody@example.test',
                'password' => 'wrong-password',
            ], $this->csrfHeaders());
    }
}
