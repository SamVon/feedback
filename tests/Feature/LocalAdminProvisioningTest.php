<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LocalAdminSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalAdminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_login_without_resetting_an_existing_account(): void
    {
        $password = str_repeat('x', 8);
        config(['feedback.staff_auth' => 'local', 'feedback.local_admin.email' => 'admin@example.test', 'feedback.local_admin.password' => $password]);
        $migration = require database_path('migrations/2026_11_16_084933_seed_local_feedback_admin.php');
        $migration->up();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => $password]));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame(config('feedback.permissions'), $user->permissions);

        $user->update(['active' => false, 'permissions' => []]);
        config(['feedback.local_admin.password' => 'different-password']);
        $this->seed(LocalAdminSeeder::class);
        $migration->down();
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($user->fresh()->active);
        $this->assertSame([], $user->fresh()->permissions);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_no_implicit_account_or_production_bootstrap(): void
    {
        config(['feedback.staff_auth' => 'local', 'feedback.local_admin.email' => null, 'feedback.local_admin.password' => null]);
        $this->seed(LocalAdminSeeder::class);
        $this->assertDatabaseCount('users', 0);
        config(['feedback.local_admin.email' => 'admin@example.test', 'feedback.local_admin.password' => 'example-password']);
        app()->instance('env', 'production');
        app(LocalAdminSeeder::class)->run();
        $this->assertDatabaseCount('users', 0);
    }
}
