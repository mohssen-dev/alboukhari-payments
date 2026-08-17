<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkGateCreditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'email' => 'a@test.local',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function configureBulkGate(): void
    {
        Setting::put('bulkgate_app_id', '35679');
        Setting::put('bulkgate_app_token', 'test-token', true);
    }

    public function test_settings_page_shows_credit_balance(): void
    {
        $this->configureBulkGate();
        Http::fake([
            'portal.bulkgate.com/api/2.0/advanced/info' => Http::response([
                'data' => ['credit' => 766.4, 'currency' => 'credits'],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->get('/settings?tab=bulkgate')
            ->assertOk()
            ->assertSee('766.4')
            ->assertSee(__('settings.credit_balance'));
    }

    public function test_api_failure_degrades_gracefully_and_is_not_cached(): void
    {
        $this->configureBulkGate();
        Http::fake([
            'portal.bulkgate.com/*' => Http::response(['error' => 'invalid token'], 401),
        ]);

        $this->actingAs($this->admin())
            ->get('/settings?tab=bulkgate')
            ->assertOk()
            ->assertSee(__('settings.credit_unavailable'));

        $this->assertNull(Cache::get('bulkgate:credit'),
            'Failures must not be cached — the next visit should retry.');
    }

    public function test_credit_is_cached_between_visits(): void
    {
        $this->configureBulkGate();
        Http::fake([
            'portal.bulkgate.com/api/2.0/advanced/info' => Http::response([
                'data' => ['credit' => 500.0, 'currency' => 'credits'],
            ]),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings?tab=bulkgate')->assertOk();
        $this->actingAs($admin)->get('/settings?tab=bulkgate')->assertOk();

        Http::assertSentCount(1); // second visit served from cache
    }

    public function test_refresh_button_clears_cache(): void
    {
        Cache::put('bulkgate:credit', ['credit' => 1.0, 'currency' => 'credits', 'checked_at' => 'x'], 300);

        $this->actingAs($this->admin())
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->post(route('settings.refresh_credit'))
            ->assertRedirect(route('settings', ['tab' => 'bulkgate']));

        $this->assertNull(Cache::get('bulkgate:credit'));
    }

    public function test_unconfigured_bulkgate_shows_unavailable_without_api_call(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->get('/settings?tab=bulkgate')
            ->assertOk()
            ->assertSee(__('settings.credit_unavailable'));

        Http::assertNothingSent();
    }
}
