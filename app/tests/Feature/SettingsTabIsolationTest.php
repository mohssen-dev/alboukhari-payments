<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The settings page is five separate <form>s (one per tab) that all POST to
 * the same endpoint. The update loop used to walk EVERY known key and write
 * '0' to any boolean checkbox missing from the request — but a checkbox on
 * another tab is always missing. So saving the General tab silently:
 *   - turned off force_ascii, dropping every SMS to 70-char Unicode segments
 *     and roughly doubling the school's message bill, and
 *   - disabled both reminder triggers, stopping the automated dunning.
 */
class SettingsTabIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'email' => 'a@tabs.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function saveSettings(array $payload)
    {
        return $this->actingAs($this->admin())
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('settings.update'), $payload);
    }

    public function test_saving_general_tab_does_not_disable_other_tabs_switches(): void
    {
        Setting::put('force_ascii', '1');
        Setting::put('trigger_first_friday_enabled', '1');
        Setting::put('trigger_mid_month_enabled', '1');
        Setting::put('whatsapp_fallback_to_sms', '1');

        // Exactly what the General form submits — no checkboxes at all.
        $this->saveSettings([
            'tab' => 'general',
            'default_monthly_fee' => '35.00',
            'currency' => 'EUR',
            'school_year_start_month' => '9',
        ])->assertRedirect();

        $this->assertSame('35.00', Setting::get('default_monthly_fee'), 'the edited value must save');
        $this->assertSame('1', Setting::get('force_ascii'),
            'saving General must not turn off force_ascii (SMS cost would double)');
        $this->assertSame('1', Setting::get('trigger_first_friday_enabled'),
            'saving General must not disable the first-Friday reminder');
        $this->assertSame('1', Setting::get('trigger_mid_month_enabled'),
            'saving General must not disable the mid-month reminder');
        $this->assertSame('1', Setting::get('whatsapp_fallback_to_sms'));
    }

    public function test_unchecking_a_box_on_its_own_tab_still_turns_it_off(): void
    {
        Setting::put('force_ascii', '1');

        // The blade ships a paired hidden 0, so an unchecked box arrives as '0'.
        $this->saveSettings([
            'tab' => 'bulkgate',
            'bulkgate_app_id' => '35679',
            'force_ascii' => '0',
        ])->assertRedirect();

        $this->assertSame('0', Setting::get('force_ascii'));
    }

    public function test_checking_a_box_on_its_own_tab_turns_it_on(): void
    {
        Setting::put('force_ascii', '0');

        // A checked box wins over the hidden field (it is submitted last).
        $this->saveSettings([
            'tab' => 'bulkgate',
            'force_ascii' => '1',
        ])->assertRedirect();

        $this->assertSame('1', Setting::get('force_ascii'));
    }

    public function test_saving_reminders_tab_does_not_touch_bulkgate_switches(): void
    {
        Setting::put('force_ascii', '1');
        Setting::put('whatsapp_enabled', '1');

        $this->saveSettings([
            'tab' => 'reminders',
            'trigger_hour' => '9',
            'trigger_minute' => '5',
            'trigger_first_friday_enabled' => '1',
            'trigger_mid_month_enabled' => '0',
        ])->assertRedirect();

        $this->assertSame('1', Setting::get('force_ascii'));
        $this->assertSame('1', Setting::get('whatsapp_enabled'));
        $this->assertSame('1', Setting::get('trigger_first_friday_enabled'));
        $this->assertSame('0', Setting::get('trigger_mid_month_enabled'));
    }

    public function test_masked_secret_is_not_written_over_the_real_token(): void
    {
        Setting::put('bulkgate_app_token', 'the-real-token', true);

        $this->saveSettings([
            'tab' => 'bulkgate',
            'bulkgate_app_token' => '••••••••••',
        ])->assertRedirect();

        $this->assertSame('the-real-token', Setting::get('bulkgate_app_token'),
            'Re-submitting the mask must never overwrite the stored secret.');
    }
}
