<?php

namespace Tests\Feature;

use App\Livewire\SendCampaign;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\CampaignLauncher;
use App\Services\CampaignSender;
use App\Services\MonthStatusResolver;
use App\Services\RecipientListBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A campaign made while sending was halted (⛔) stayed "waiting" forever:
 * the send page saved it with a bare "Status: halted", the scheduler only
 * fires campaigns that have a time, and nothing on screen could start it.
 * Now the send page refuses while halted, and a waiting campaign has
 * "Send now" / "Cancel" on the campaigns pages.
 */
class CampaignSendNowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-13 10:00:00');
        $this->resetResolverToday();
        Setting::put('halt_sending', '0');
        Setting::put('bulkgate_app_id', '1');
        Setting::put('bulkgate_app_token', 'tok', true);
        Http::fake(['portal.bulkgate.com/*' => Http::response(['data' => ['response' => [['status' => 'accepted']]]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetResolverToday();
        parent::tearDown();
    }

    private function resetResolverToday(): void
    {
        $r = new \ReflectionClass(MonthStatusResolver::class);
        foreach (['todayYm', 'todayDay'] as $prop) {
            $r->getProperty($prop)->setValue(null, null);
        }
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => "$role@sendnow.test", 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    private function student(string $name): Student
    {
        static $n = 0;

        return Student::create([
            'name' => $name,
            'phone_primary_e164' => sprintf('+316123457%02d', ++$n),
            'default_fee_amount' => 30,
            'enrolled_at' => '2026-01-01',
            'allow_sms' => true,
        ]);
    }

    /** A "didn't pay May" campaign whose list was built when it was made — as the send page does. */
    private function waitingCampaign(array $attrs = []): Campaign
    {
        $campaign = Campaign::create($attrs + [
            'type' => 'unpaid_by_month',
            'status' => 'queued',
            'period_year' => 2026,
            'period_month' => 5,
            'body_template' => 'Herinnering voor {{student_name}}',
            'group_by_family' => false,
            'tag' => 'send-now-test',
        ]);
        foreach ((new RecipientListBuilder())->build($campaign)['recipients'] as $r) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'student_id' => $r['student_id'],
                'phone_e164' => $r['phone'],
                'body_personalized' => $r['body'],
                'status' => 'pending',
                'segments' => $r['segments'],
                'idempotency_key' => $r['idempotency_key'],
            ]);
        }
        $campaign->update(['total_recipients' => $campaign->recipients()->count()]);

        return $campaign->fresh();
    }

    private function postAs(string $route, Campaign $campaign, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user(User::ROLE_STAFF))
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post(route($route, $campaign));
    }

    // ---- the send page ----

    public function test_launching_while_halted_is_refused_and_creates_nothing(): void
    {
        $this->student('Owes May');
        Setting::put('halt_sending', '1');
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(SendCampaign::class)
            ->set('type', 'send_all')
            ->set('body', 'Test')
            ->call('launch')
            ->assertDispatched('flash', type: 'error');

        $this->assertSame(0, Campaign::count(), 'no campaign is left waiting');
    }

    public function test_a_manual_campaign_goes_out_while_the_automatic_reminders_are_off(): void
    {
        // Reminders off (Settings → Reminders) — NOT the ⛔ switch.
        Setting::put('trigger_first_friday_enabled', '0');
        Setting::put('trigger_mid_month_enabled', '0');
        $this->student('Owes May');
        $this->student('Also owes May');

        // The 15th at reminder time: nothing is sent automatically.
        Carbon::setTestNow('2026-09-15 09:05:00');
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->assertSame(0, Campaign::count());

        // A campaign launched by hand still goes out at once.
        $this->actingAs($this->user(User::ROLE_STAFF));
        Livewire::test(SendCampaign::class)
            ->set('type', 'unpaid_by_month')
            ->set('year', 2026)
            ->set('month', 5)
            ->set('body', 'Herinnering')
            ->call('launch')
            ->assertDispatched('flash', type: 'success');

        $campaign = Campaign::sole();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(2, $campaign->sent_count);
        // Only message sends — the send page also reads prices and the balance.
        $this->assertCount(2, Http::recorded(fn ($request) => str_contains($request->url(), '/advanced/transactional')));
    }

    // ---- "send now" ----

    public function test_a_waiting_campaign_is_sent_now_and_leaves_out_who_paid_meanwhile(): void
    {
        $owes = $this->student('Still owes');
        $paid = $this->student('Paid since');
        $campaign = $this->waitingCampaign();
        $this->assertSame(2, $campaign->recipients()->count());

        Payment::create(['student_id' => $paid->id, 'period_year' => 2026, 'period_month' => 5, 'amount' => 30, 'paid_at' => '2026-09-13', 'method' => 'cash']);

        $this->postAs('campaigns.send', $campaign)
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('flash_type', 'success');

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame('sent', $campaign->recipients()->where('student_id', $owes->id)->value('status'));
        $this->assertSame('skipped', $campaign->recipients()->where('student_id', $paid->id)->value('status'));
        Http::assertSentCount(1);
    }

    public function test_send_now_is_refused_while_halted(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign();
        Setting::put('halt_sending', '1');

        $this->postAs('campaigns.send', $campaign)->assertSessionHas('flash_type', 'error');

        $this->assertSame('queued', $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_viewers_cannot_send_now(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign();

        $this->postAs('campaigns.send', $campaign, $this->user(User::ROLE_VIEWER))->assertForbidden();
        $this->assertSame('queued', $campaign->fresh()->status);
    }

    public function test_a_finished_campaign_cannot_be_sent_again(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign(['status' => 'completed']);

        $this->postAs('campaigns.send', $campaign)->assertSessionHas('flash_type', 'error');
        Http::assertNothingSent();
    }

    public function test_a_hand_picked_campaign_is_never_widened_to_everyone(): void
    {
        $picked = $this->student('Picked');
        $this->student('Not picked');
        $campaign = Campaign::create([
            'type' => 'specific_students', 'status' => 'queued', 'period_year' => 2026, 'period_month' => 9,
            'body_template' => 'hi', 'group_by_family' => false, 'tag' => 'picked',
        ]);
        CampaignRecipient::create([
            'campaign_id' => $campaign->id, 'student_id' => $picked->id, 'phone_e164' => $picked->phone_primary_e164,
            'body_personalized' => 'hi', 'status' => 'pending', 'segments' => 1, 'idempotency_key' => 'picked-1',
        ]);

        $this->postAs('campaigns.send', $campaign);

        $this->assertSame(1, $campaign->recipients()->count(), 'no one was added');
        $this->assertSame('sent', $campaign->recipients()->value('status'));
        Http::assertSentCount(1);
    }

    public function test_cancel_stops_a_waiting_campaign(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign();

        $this->postAs('campaigns.cancel', $campaign)->assertSessionHas('flash_type', 'success');

        $this->assertSame('canceled', $campaign->fresh()->status);
        $this->assertSame(0, $campaign->recipients()->where('status', 'pending')->count());
        $this->assertSame(1, $campaign->fresh()->skipped_count);
    }

    // ---- never twice ----

    public function test_a_campaign_already_being_sent_is_not_sent_again_in_parallel(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign(['status' => 'paused']);

        $lock = Cache::lock('campaign-send:' . $campaign->id, 60);
        $this->assertTrue($lock->get());
        try {
            $result = app(CampaignSender::class)->sendCampaign($campaign);
        } finally {
            $lock->release();
        }

        $this->assertSame('busy', $result['status']);
        $this->assertSame('pending', $campaign->recipients()->value('status'));
        Http::assertNothingSent();
    }

    public function test_a_recipient_skipped_meanwhile_is_not_sent(): void
    {
        $this->student('Owes May');
        $this->student('Also owes');
        $campaign = $this->waitingCampaign(['status' => 'paused']);
        $campaign->recipients()->first()->update(['status' => 'skipped']);

        app(CampaignSender::class)->sendCampaign($campaign);

        Http::assertSentCount(1);
    }

    // ---- the pages ----

    public function test_the_campaigns_pages_offer_send_now_and_say_why_it_waits(): void
    {
        $this->student('Owes May');
        $campaign = $this->waitingCampaign();
        Setting::put('halt_sending', '1');
        $this->actingAs($this->user(User::ROLE_STAFF));

        $this->get(route('campaigns.index'))->assertOk()
            ->assertSee(__('campaign.send_now'))
            ->assertSee(__('halt.bar_title'));
        $this->get(route('campaigns.show', $campaign))->assertOk()
            ->assertSee(__('campaign.why_waiting_halted'))
            ->assertSee(__('campaigns.status.queued'));
    }

    public function test_send_now_is_offered_only_for_waiting_campaigns(): void
    {
        $this->assertTrue(CampaignLauncher::canSendNow(new Campaign(['status' => 'queued'])));
        $this->assertTrue(CampaignLauncher::canSendNow(new Campaign(['status' => 'paused'])));
        $this->assertFalse(CampaignLauncher::canSendNow(new Campaign(['status' => 'completed'])));
        $this->assertFalse(CampaignLauncher::canSendNow(new Campaign(['status' => 'canceled'])));
    }
}
