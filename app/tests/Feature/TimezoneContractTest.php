<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app must think in the school's local time, not UTC.
 *
 * config/app.php used to hard-code 'UTC' and ignore APP_TIMEZONE — production
 * therefore ran 2 hours behind Dutch time in summer, which silently shifted:
 *   - the daily reminder hour (09:05 became 11:05 local),
 *   - the first-Friday / day-15 checks near midnight,
 *   - "today" on the dashboard and reports,
 *   - the default paid_at on a new payment,
 *   - and the moment a scheduled campaign fires, even though the admin picks
 *     that moment in a datetime-local input that submits BROWSER-local time.
 *
 * These tests pin the contract so it cannot regress.
 */
class TimezoneContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_timezone_is_not_utc(): void
    {
        $this->assertNotSame('UTC', config('app.timezone'),
            'config/app.php must resolve APP_TIMEZONE, not hard-code UTC.');
    }

    public function test_app_timezone_is_read_from_env(): void
    {
        $raw = file_get_contents(config_path('app.php'));
        $this->assertMatchesRegularExpression(
            "/'timezone'\s*=>\s*env\(\s*'APP_TIMEZONE'/",
            $raw,
            "config/app.php must read the timezone from APP_TIMEZONE so the server can set it."
        );
    }

    public function test_now_and_carbon_agree_with_configured_zone(): void
    {
        $this->assertSame(config('app.timezone'), now()->timezoneName);
        $this->assertSame(config('app.timezone'), \Carbon\Carbon::today()->timezoneName);
    }

    public function test_scheduled_at_is_interpreted_in_app_timezone(): void
    {
        // A datetime-local input submits a naive wall-clock string in the
        // admin's own timezone. It must be stored as that wall-clock moment in
        // the app timezone, otherwise a campaign fires hours early or late.
        $campaign = Campaign::create([
            'type' => 'send_all',
            'status' => 'queued',
            'scheduled_at' => '2026-12-24T18:30',
            'period_year' => 2026,
            'period_month' => 12,
            'body_template' => 'test',
            'group_by_family' => false,
        ]);

        $stored = $campaign->fresh()->scheduled_at;
        $this->assertSame('18:30', $stored->format('H:i'),
            'A campaign scheduled for 18:30 must stay 18:30, not shift by the UTC offset.');
        $this->assertSame(config('app.timezone'), $stored->timezoneName);
    }
}
