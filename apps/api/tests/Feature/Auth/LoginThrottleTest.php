<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * Behind nginx every request comes from the proxy's private address; the login
 * throttle (throttle:5,1) must still count attempts per real client IP, and a
 * client connecting directly must not be able to pick its IP via the header.
 */
class LoginThrottleTest extends TestCase
{
    use InteractsAsAdmin;
    use RefreshDatabase;

    private const NGINX = '172.18.0.10';

    public function test_login_throttle_is_tracked_per_forwarded_client_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin(self::NGINX, '203.0.113.10')->assertStatus(422);
        }

        $this->attemptLogin(self::NGINX, '203.0.113.10')->assertStatus(429);
        $this->attemptLogin(self::NGINX, '198.51.100.20')->assertStatus(422);
    }

    public function test_forged_leftmost_forwarded_entry_is_ignored(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptLogin(self::NGINX, "198.51.100.{$i}, 203.0.113.10")->assertStatus(422);
        }

        $this->attemptLogin(self::NGINX, '198.51.100.99, 203.0.113.10')->assertStatus(429);
    }

    public function test_direct_client_cannot_spoof_forwarded_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptLogin('203.0.113.50', "198.51.100.{$i}")->assertStatus(422);
        }

        $this->attemptLogin('203.0.113.50', '198.51.100.99')->assertStatus(429);
    }

    private function attemptLogin(string $remoteAddr, string $forwardedFor): TestResponse
    {
        return $this->withSession(['_token' => $this->csrfToken()])
            ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson('/api/auth/login', [
                'email' => 'nobody@example.test',
                'password' => 'wrong-password',
            ], $this->csrfHeaders());
    }
}
