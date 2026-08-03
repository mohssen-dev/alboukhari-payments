<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Skip CSRF check for POST-based auth tests — CSRF is exercised in
        // browser integration, not here where we test controller behaviour.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in', false);
    }

    public function test_valid_credentials_log_user_in(): void
    {
        $user = User::create([
            'name'      => 'Test Admin',
            'email'     => 'admin@test.local',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->post(route('login.attempt'), [
            'email'    => 'admin@test.local',
            'password' => 'secret123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::create([
            'name'      => 'Foo',
            'email'     => 'foo@test.local',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $this->from(route('login'))
            ->post(route('login.attempt'), [
                'email'    => 'foo@test.local',
                'password' => 'WRONG',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        User::create([
            'name'      => 'Disabled',
            'email'     => 'off@test.local',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_STAFF,
            'is_active' => false,
        ]);

        $this->post(route('login.attempt'), [
            'email'    => 'off@test.local',
            'password' => 'secret123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'name'      => 'Test',
            'email'     => 'a@b.test',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_protected_routes_redirect_guests(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('reports'))->assertRedirect(route('login'));
        $this->get(route('campaigns.index'))->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_access_admin_only_settings(): void
    {
        $viewer = User::create([
            'name'      => 'V',
            'email'     => 'v@test.local',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $this->actingAs($viewer)->get(route('settings'))->assertForbidden();
        $this->actingAs($viewer)->get(route('users.index'))->assertForbidden();
    }

    public function test_admin_can_access_users_page(): void
    {
        $admin = User::create([
            'name'      => 'A',
            'email'     => 'a@test.local',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
    }

    public function test_user_helpers(): void
    {
        $admin  = new User(['role' => 'admin', 'is_active' => true]);
        $staff  = new User(['role' => 'staff', 'is_active' => true]);
        $viewer = new User(['role' => 'viewer', 'is_active' => true]);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($staff->isAdmin());
        $this->assertTrue($admin->isStaff());
        $this->assertTrue($staff->isStaff());
        $this->assertFalse($viewer->isStaff());
        $this->assertTrue($admin->canWrite());
        $this->assertTrue($staff->canWrite());
        $this->assertFalse($viewer->canWrite());
    }
}
