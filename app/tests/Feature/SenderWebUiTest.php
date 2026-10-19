<?php

namespace Tests\Feature;

use App\Livewire\SendCampaign;
use App\Livewire\SettingsSender;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SenderDevice;
use App\Models\SenderDevicePairingCode;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\SenderDeviceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The web side of the sender phone: Settings → Sender phone (pairing, the
 * phones, the pace) and the channel picker on the send page.
 */
class SenderWebUiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => "$role@senderui.test", 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    private function device(array $attrs = []): SenderDevice
    {
        $d = new SenderDevice($attrs + ['name' => 'Office phone', 'is_active' => true, 'last_seen_at' => now()]);
        $d->issueToken();
        $d->save();

        return $d;
    }

    private function student(string $name, array $attrs = []): Student
    {
        static $n = 0;

        return Student::create($attrs + [
            'name' => $name,
            'phone_primary_e164' => sprintf('+316123458%02d', ++$n),
            'default_fee_amount' => 30,
            'allow_sms' => true,
            'allow_whatsapp' => true,
        ]);
    }

    // ---- settings tab ----

    public function test_admin_generates_a_pairing_code_the_app_can_use(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $lw = Livewire::test(SettingsSender::class)->call('generateCode');
        $code = SenderDevicePairingCode::sole();
        $lw->assertSee(substr($code->code, 0, 4) . ' ' . substr($code->code, 4));

        // The app pairs with it (public endpoint) and the tab shows the paired phone.
        $this->postJson('/api/device/pair', ['code' => $code->code, 'device_name' => 'Samsung S25'])->assertCreated();
        // The tab polls while a code is on screen, so the same instance picks the pairing up.
        $lw->call('$refresh')->assertSee(__('sender.paired_ok', ['name' => 'Samsung S25']))->assertSee('Samsung S25');
    }

    public function test_the_settings_page_shows_the_sender_tab_with_the_phones_state(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->device(['last_heartbeat' => ['attention' => 'SCREEN_LOCKED', 'battery_level' => 42, 'whatsapp_package' => 'com.whatsapp']]);

        $this->get(route('settings', ['tab' => 'sender']))->assertOk()
            ->assertSee('Office phone')
            ->assertSee(__('sender.waiting_for', ['reason' => __('sender.att.SCREEN_LOCKED')]))
            ->assertSee('42%');
    }

    public function test_disabling_a_phone_releases_its_claims_and_the_api_refuses_it(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $d = new SenderDevice(['name' => 'P', 'is_active' => true]);
        $token = $d->issueToken();
        $d->save();
        $campaign = Campaign::create(['type' => 'send_all', 'status' => 'running', 'channel' => Campaign::CHANNEL_DEVICE_SMS, 'sender_device_id' => $d->id, 'body_template' => 'x', 'tag' => 't']);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'phone_e164' => '+31612345678', 'body_personalized' => 'x', 'status' => 'sending', 'sender_device_id' => $d->id, 'claimed_at' => now(), 'segments' => 1, 'idempotency_key' => 'k1']);

        Livewire::test(SettingsSender::class)->call('toggle', $d->id);

        $this->assertFalse($d->fresh()->is_active);
        $this->assertSame('pending', CampaignRecipient::sole()->status);
        $this->postJson('/api/device/heartbeat', [], ['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_a_phone_with_an_unfinished_campaign_cannot_be_deleted(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $d = $this->device();
        Campaign::create(['type' => 'send_all', 'status' => 'queued', 'channel' => Campaign::CHANNEL_DEVICE_SMS, 'sender_device_id' => $d->id, 'body_template' => 'x', 'tag' => 't']);

        Livewire::test(SettingsSender::class)->call('delete', $d->id)->assertDispatched('toast', type: 'error');
        $this->assertNotNull($d->fresh());
    }

    public function test_the_pace_is_validated_and_reaches_the_phone(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        Livewire::test(SettingsSender::class)
            ->set('config.sender_min_delay_sec', 30)
            ->set('config.sender_max_delay_sec', 20)
            ->call('saveConfig')
            ->assertHasErrors('config.sender_max_delay_sec');

        Livewire::test(SettingsSender::class)
            ->set('config.sender_min_delay_sec', 30)
            ->set('config.sender_max_delay_sec', 60)
            ->set('config.sender_daily_cap_whatsapp', 50)
            ->call('saveConfig')
            ->assertHasNoErrors();

        $this->assertSame(60, SenderDeviceConfig::forDevice()['max_delay_sec']);
        $this->assertSame(50, SenderDeviceConfig::forDevice()['daily_cap_whatsapp']);
    }

    public function test_staff_cannot_use_the_sender_tab(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(SettingsSender::class)->call('generateCode')->assertForbidden();
        $this->assertSame(0, SenderDevicePairingCode::count());
    }

    // ---- send page ----

    public function test_a_phone_channel_needs_a_phone_and_hands_the_campaign_to_it(): void
    {
        Setting::put('halt_sending', '0');
        Http::fake();
        $this->actingAs($this->user(User::ROLE_STAFF));
        $this->student('A');
        $d = $this->device();

        Livewire::test(SendCampaign::class)
            ->set('type', 'send_all')
            ->set('body', 'Hallo')
            ->set('channel', Campaign::CHANNEL_DEVICE_SMS)
            ->assertSet('senderDeviceId', $d->id, 'the only active phone is picked')
            ->set('senderDeviceId', null)
            ->call('launch')
            ->assertHasErrors('senderDeviceId');

        Livewire::test(SendCampaign::class)
            ->set('type', 'send_all')
            ->set('body', 'Hallo')
            ->set('channel', Campaign::CHANNEL_DEVICE_SMS)
            ->call('launch')
            ->assertHasNoErrors()
            ->assertDispatched('flash', type: 'success');

        $campaign = Campaign::sole();
        $this->assertSame(Campaign::CHANNEL_DEVICE_SMS, $campaign->channel);
        $this->assertSame($d->id, $campaign->sender_device_id);
        $this->assertSame('running', $campaign->status, 'handed to the phone, not sent by the server');
        $this->assertSame('pending', CampaignRecipient::sole()->status);
        $this->assertEquals(0, $campaign->estimated_cost);
        $this->assertCount(0, Http::recorded(fn ($r) => str_contains($r->url(), '/advanced/transactional')), 'nothing went through BulkGate');
    }

    public function test_whatsapp_campaigns_skip_students_who_declined_whatsapp(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $this->student('Yes to WhatsApp');
        $this->student('No to WhatsApp', ['allow_whatsapp' => false]);
        $this->student('No to SMS', ['allow_sms' => false]);
        $this->device();

        $lw = Livewire::test(SendCampaign::class)->set('type', 'send_all')->set('body', 'Hallo');

        $lw->set('channel', Campaign::CHANNEL_DEVICE_WHATSAPP);
        $this->assertSame(['No to SMS', 'Yes to WhatsApp'], collect($lw->get('previewRecipients'))->pluck('name')->sort()->values()->all());

        $lw->set('channel', Campaign::CHANNEL_BULKGATE_SMS);
        $this->assertSame(['No to WhatsApp', 'Yes to WhatsApp'], collect($lw->get('previewRecipients'))->pluck('name')->sort()->values()->all());
    }

    public function test_a_test_from_the_phone_is_a_one_message_campaign_for_it(): void
    {
        Setting::put('halt_sending', '0');
        $this->actingAs($this->user(User::ROLE_STAFF));
        $this->student('A');
        $d = $this->device();

        Livewire::test(SendCampaign::class)
            ->set('body', 'Test bericht')
            ->set('channel', Campaign::CHANNEL_DEVICE_WHATSAPP)
            ->set('testPhone', '06 12345678')
            ->call('sendTest')
            ->assertDispatched('flash');

        $campaign = Campaign::sole();
        $this->assertSame('TEST', $campaign->tag);
        $this->assertSame($d->id, $campaign->sender_device_id);
        $this->assertSame('+31612345678', CampaignRecipient::sole()->phone_e164);
    }

    public function test_campaign_pages_show_the_channel_and_phone(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $d = $this->device();
        $campaign = Campaign::create(['type' => 'send_all', 'status' => 'running', 'channel' => Campaign::CHANNEL_DEVICE_WHATSAPP, 'sender_device_id' => $d->id, 'body_template' => 'x', 'tag' => 't']);

        $this->get(route('campaigns.index'))->assertOk()->assertSee('WhatsApp · Office phone');
        $this->get(route('campaigns.show', $campaign))->assertOk()->assertSee('WhatsApp · Office phone');
    }
}
