<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Services\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Campaigns must not be able to strand parents half-messaged.
 *
 * Three defects made that possible:
 *  - a recipient flipped to 'sending' right before the API call stayed there
 *    forever if the process died, never retried and never counted as remaining;
 *  - the completion check looked only at 'pending', so such a campaign was
 *    stamped 'completed' — and a campaign where every message failed was too;
 *  - nothing anywhere resumed a campaign paused by the hourly quota, so the
 *    remaining parents were never contacted at all.
 */
class CampaignRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('halt_sending', '0');
        Setting::put('bulkgate_app_id', '1');
        Setting::put('bulkgate_app_token', 'tok', true);
    }

    private function campaign(string $status = 'queued'): Campaign
    {
        return Campaign::create([
            'type' => 'send_all',
            'status' => $status,
            'period_year' => (int) date('Y'),
            'period_month' => (int) date('n'),
            'body_template' => 'hi',
            'group_by_family' => false,
            'tag' => 'recovery-test',
        ]);
    }

    private function recipient(Campaign $c, string $status, int $attempts = 0): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $c->id,
            'phone_e164' => '+31612345678',
            'body_personalized' => 'hi',
            'status' => $status,
            'segments' => 1,
            'attempts' => $attempts,
            'idempotency_key' => 'k' . uniqid('', true),
        ]);
    }

    private function fakeProviderAccepts(): void
    {
        Http::fake(['portal.bulkgate.com/*' => Http::response(['data' => ['response' => [['status' => 'accepted']]]])]);
    }

    public function test_recipient_stranded_at_sending_is_requeued_and_delivered(): void
    {
        $this->fakeProviderAccepts();
        $c = $this->campaign();
        $stranded = $this->recipient($c, 'sending', 1);

        app(CampaignSender::class)->sendCampaign($c);

        $this->assertSame('sent', $stranded->fresh()->status,
            'A recipient stranded mid-flight must be picked back up.');
    }

    public function test_recipient_stranded_too_many_times_is_abandoned_not_looped(): void
    {
        $this->fakeProviderAccepts();
        $c = $this->campaign();
        $stuck = $this->recipient($c, 'sending', CampaignSender::MAX_ATTEMPTS);

        app(CampaignSender::class)->sendCampaign($c);

        $this->assertSame('failed', $stuck->fresh()->status,
            'A recipient that keeps killing the worker must be abandoned, not retried forever.');
    }

    public function test_campaign_is_not_completed_while_a_recipient_is_still_sending(): void
    {
        // Halted so the loop does no work: the stranded row must still block completion.
        Setting::put('halt_sending', '1');
        $c = $this->campaign('running');
        $this->recipient($c, 'sending', 1);

        app(CampaignSender::class)->sendCampaign($c);

        $this->assertNotSame('completed', $c->fresh()->status);
    }

    public function test_campaign_where_every_message_failed_is_marked_failed(): void
    {
        Http::fake(['portal.bulkgate.com/*' => Http::response(['data' => ['response' => [['status' => 'error']]]])]);
        $c = $this->campaign();
        $this->recipient($c, 'pending');

        app(CampaignSender::class)->sendCampaign($c);

        $this->assertSame('failed', $c->fresh()->status,
            'A campaign where nothing was delivered is not "completed".');
    }

    public function test_provider_rejection_inside_a_2xx_is_treated_as_a_failure(): void
    {
        // BulkGate answers 200 but rejects the number. This used to be recorded
        // as 'sent' and billed, with no retry — a message no parent received.
        Http::fake(['portal.bulkgate.com/*' => Http::response(['data' => ['response' => [['status' => 'invalid_number']]]])]);
        $c = $this->campaign();
        $r = $this->recipient($c, 'pending');

        app(CampaignSender::class)->sendCampaign($c);

        $this->assertSame('failed', $r->fresh()->status);
        $this->assertNull($r->fresh()->sent_at);
    }

    public function test_paused_campaign_is_resumed_by_the_scheduler(): void
    {
        $this->fakeProviderAccepts();
        $c = $this->campaign('paused');
        $left = $this->recipient($c, 'pending');

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('sent', $left->fresh()->status,
            'A quota-paused campaign must be picked back up automatically.');
        $this->assertSame('completed', $c->fresh()->status);
    }

    public function test_stale_running_campaign_is_resumed(): void
    {
        $this->fakeProviderAccepts();
        $c = $this->campaign('running');
        $left = $this->recipient($c, 'pending');
        // Pretend the batch died 30 minutes ago.
        Campaign::where('id', $c->id)->update(['updated_at' => now()->subMinutes(30)]);

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('sent', $left->fresh()->status);
    }

    public function test_a_freshly_running_campaign_is_left_alone(): void
    {
        // Guard against the reaper stealing a batch another worker is running.
        $this->fakeProviderAccepts();
        $c = $this->campaign('running');
        $left = $this->recipient($c, 'pending');

        Artisan::call('campaigns:dispatch-scheduled');

        $this->assertSame('pending', $left->fresh()->status,
            'A campaign that is actively running must not be resumed underneath itself.');
    }
}
