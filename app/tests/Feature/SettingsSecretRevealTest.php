<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsSecretRevealTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => 'U', 'email' => $role . '@test.local',
            'password' => Hash::make('secret123'),
            'role' => $role, 'is_active' => true,
        ]);
    }

    public function test_admin_can_reveal_encrypted_token(): void
    {
        Setting::put('bulkgate_app_token', 'my-real-token-123', true);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->withoutMiddleware(PreventRequestForgery::class)
            ->postJson(route('settings.reveal_secret'), ['key' => 'bulkgate_app_token'])
            ->assertOk()
            ->assertJson(['value' => 'my-real-token-123']);
    }

    public function test_reveal_is_audit_logged(): void
    {
        Setting::put('bulkgate_app_token', 'tok', true);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->withoutMiddleware(PreventRequestForgery::class)
            ->postJson(route('settings.reveal_secret'), ['key' => 'bulkgate_app_token'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settings',
            'description' => 'revealed secret: bulkgate_app_token',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_non_whitelisted_key_is_rejected(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->withoutMiddleware(PreventRequestForgery::class)
            ->postJson(route('settings.reveal_secret'), ['key' => 'default_monthly_fee'])
            ->assertStatus(422);
    }

    public function test_staff_and_viewer_cannot_reveal(): void
    {
        Setting::put('bulkgate_app_token', 'tok', true);

        foreach ([User::ROLE_STAFF, User::ROLE_VIEWER] as $role) {
            $this->actingAs($this->user($role))
                ->withoutMiddleware(PreventRequestForgery::class)
                ->postJson(route('settings.reveal_secret'), ['key' => 'bulkgate_app_token'])
                ->assertForbidden();
        }
    }
}
