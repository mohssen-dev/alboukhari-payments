<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScheduledCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function makeScheduled(array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'type' => 'send_all',
            'status' => 'queued',
            'scheduled_at' => now()->subMinute(),
            'period_year' => (int) date('Y'),
            'period_month' => (int) date('n'),
            'body_template' => 'Beste {{Naam}}, test.',
            'group_by_family' => false,
            'tag' => 'test-scheduled',
        ], $attrs));
    }

    public function test_due_scheduled_campaign_fires_and_completes(): void
    {
        // No students in DB → recipient list is empty → campaign completes
        // without any BulkGate call (safe for tests).
        $campaign = $this->makeScheduled();

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_future_scheduled_campaign_stays_queued(): void
    {
        $campaign = $this->makeScheduled(['scheduled_at' => now()->addHour()]);

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('queued', $campaign->fresh()->status);
    }

    public function test_halt_defers_due_campaigns(): void
    {
        \App\Models\Setting::put('halt_sending', '1');
        $campaign = $this->makeScheduled();

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('queued', $campaign->fresh()->status,
            'Halted system must leave scheduled campaigns queued for after resume.');
        \App\Models\Setting::put('halt_sending', '0');
    }

    public function test_staff_can_cancel_scheduled_campaign(): void
    {
        $staff = User::create([
            'name' => 'S', 'email' => 's@test.local',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_STAFF, 'is_active' => true,
        ]);
        $campaign = $this->makeScheduled(['scheduled_at' => now()->addHour()]);

        $this->actingAs($staff)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->post(route('campaigns.cancel', $campaign))
            ->assertRedirect();

        $this->assertSame('canceled', $campaign->fresh()->status);
    }

    public function test_completed_campaign_cannot_be_canceled(): void
    {
        $staff = User::create([
            'name' => 'S2', 'email' => 's2@test.local',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_STAFF, 'is_active' => true,
        ]);
        $campaign = $this->makeScheduled(['status' => 'completed', 'scheduled_at' => null]);

        $this->actingAs($staff)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->post(route('campaigns.cancel', $campaign));

        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_viewer_cannot_cancel(): void
    {
        $viewer = User::create([
            'name' => 'V', 'email' => 'v2@test.local',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_VIEWER, 'is_active' => true,
        ]);
        $campaign = $this->makeScheduled(['scheduled_at' => now()->addHour()]);

        $this->actingAs($viewer)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->post(route('campaigns.cancel', $campaign))
            ->assertForbidden();

        $this->assertSame('queued', $campaign->fresh()->status);
    }
}
