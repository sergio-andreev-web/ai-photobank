<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('PHOTOBANK_ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_creates_admin_with_hashed_environment_password(): void
    {
        putenv('PHOTOBANK_ADMIN_PASSWORD=strong-password');

        $this->artisan('photobank:create-admin', [
            'email' => 'new@example.com', '--name' => 'New Admin',
        ])->assertExitCode(0);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('New Admin', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertNotSame('strong-password', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('strong-password', $user->password));
    }

    public function test_prompts_for_password_when_environment_variable_is_absent(): void
    {
        putenv('PHOTOBANK_ADMIN_PASSWORD');

        $this->artisan('photobank:create-admin', ['email' => 'prompt@example.com'])
            ->expectsQuestion('Admin password', 'prompt-password')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'prompt@example.com')->firstOrFail();
        $this->assertSame('prompt@example.com', $user->name);
        $this->assertTrue(Hash::check('prompt-password', $user->password));
    }

    public function test_rejects_invalid_email_and_short_password(): void
    {
        putenv('PHOTOBANK_ADMIN_PASSWORD=strong-password');
        $this->artisan('photobank:create-admin', ['email' => 'invalid-email'])->assertExitCode(1);

        putenv('PHOTOBANK_ADMIN_PASSWORD=short');
        $this->artisan('photobank:create-admin', ['email' => 'valid@example.com'])->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_existing_email_requires_update_flag_and_only_updates_password(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com', 'name' => 'Original', 'role' => 'admin',
        ]);
        $oldPassword = $user->password;
        putenv('PHOTOBANK_ADMIN_PASSWORD=new-password');

        $this->artisan('photobank:create-admin', ['email' => $user->email])->assertExitCode(1);
        $this->assertSame($oldPassword, $user->fresh()->password);

        $this->artisan('photobank:create-admin', [
            'email' => $user->email, '--name' => 'Ignored', '--update-password' => true,
        ])->assertExitCode(0);

        $user->refresh();
        $this->assertSame('Original', $user->name);
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_update_flag_does_not_create_missing_user(): void
    {
        putenv('PHOTOBANK_ADMIN_PASSWORD=strong-password');

        $this->artisan('photobank:create-admin', [
            'email' => 'missing@example.com', '--update-password' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_seeder_creates_known_admin_only_in_local_and_testing(): void
    {
        app()->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
        $this->assertSame(0, User::query()->count());

        app()->instance('env', 'testing');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);

        app()->instance('env', 'local');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, User::query()->count());
        $user = User::query()->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('password123', $user->password));

        app()->instance('env', 'testing');
    }
}
