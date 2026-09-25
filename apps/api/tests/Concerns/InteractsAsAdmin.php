<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Session-cookie auth (ADR-0001) is not Sanctum-token based, so tests log in
 * via actingAs() and supply a matching CSRF token/header pair rather than a
 * bearer token — see App\Http\Controllers\Auth\AuthController for context.
 */
trait InteractsAsAdmin
{
    /**
     * Laravel skips CSRF verification while running unit tests
     * (ValidateCsrfToken::runningUnitTests()), which would leave both the 419
     * contract and the token pairs below unchecked — keep it enforced.
     */
    protected function setUpInteractsAsAdmin(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    protected function loginAsAdmin(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Admin',
            'email' => 'admin+'.uniqid('', true).'@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ], $attributes));

        $this->actingAs($user, 'web');
        $this->withSession(['_token' => $this->csrfToken()]);

        return $user;
    }

    protected function csrfToken(): string
    {
        return 'test-csrf-token';
    }

    /**
     * @return array<string, string>
     */
    protected function csrfHeaders(): array
    {
        return ['X-CSRF-TOKEN' => $this->csrfToken()];
    }
}
