<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessageLog;
use App\Models\SenderDevice;
use App\Models\SenderDevicePairingCode;
use App\Services\BulkGateClient;
use App\Services\CampaignSender;
use App\Services\HaltService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SenderDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private function pairedDevice(): array
    {
        $device = new SenderDevice(['name' => 'Test phone', 'is_active' => true]);
        $token = $device->issueToken();
        $device->save();

        return [$device, $token];
    }

    private function deviceCampaign(SenderDevice $device, string $channel = Campaign::CHANNEL_DEVICE_SMS, int $recipients = 2): Campaign
    {
        $campaign = Campaign::create([
            'type' => 'specific_students',
            'status' => 'queued',
            'channel' => $channel,
            'sender_device_id' => $device->id,
            'body_template' => 'Hello',
            'tag' => 'T',
            'total_recipients' => $recipients,
        ]);
        for ($i = 1; $i <= $recipients; $i++) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'phone_e164' => '+3161234567' . $i,
                'body_personalized' => "Hello {$i}",
                'status' => 'pending',
                'segments' => 1,
                'idempotency_key' => "k-{$campaign->id}-{$i}",
            ]);
        }

        return $campaign;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_pairing_with_valid_code_issues_a_token_once(): void
    {
        $code = SenderDevicePairingCode::generate();

        $res = $this->postJson('/api/device/pair', ['code' => $code->code, 'device_name' => 'Office phone', 'model' => 'S25'])
            ->assertCreated()
            ->assertJsonStructure(['device_id', 'token', 'config' => ['min_delay_sec', 'whatsapp_mode']]);

        $token = $res->json('token');
        $this->assertNotNull(SenderDevice::findByToken($token));
        $this->assertNotNull($code->fresh()->used_at);

        // نفس الرمز مرة ثانية → مرفوض
        $this->postJson('/api/device/pair', ['code' => $code->code, 'device_name' => 'Again'])
            ->assertStatus(422);
    }

    public function test_expired_or_unknown_code_is_rejected(): void
    {
        $code = SenderDevicePairingCode::generate();
        $code->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/device/pair', ['code' => $code->code, 'device_name' => 'X'])->assertStatus(422);
        $this->postJson('/api/device/pair', ['code' => 'NOPE1234', 'device_name' => 'X'])->assertStatus(422);
    }

    public function test_requests_without_valid_token_are_rejected(): void
    {
        $this->postJson('/api/device/jobs/claim', [])->assertStatus(401);
        $this->postJson('/api/device/jobs/claim', [], $this->auth('bogus'))->assertStatus(401);
    }

    public function test_claim_returns_only_this_devices_pending_recipients_and_marks_them_sending(): void
    {
        [$device, $token] = $this->pairedDevice();
        [$other, $otherToken] = $this->pairedDevice();
        $mine = $this->deviceCampaign($device);
        $theirs = $this->deviceCampaign($other, Campaign::CHANNEL_DEVICE_WHATSAPP, 1);

        $res = $this->postJson('/api/device/jobs/claim', ['limit' => 10], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('halt', false)
            ->assertJsonCount(2, 'jobs');

        $job = $res->json('jobs.0');
        $this->assertSame('sms', $job['channel']);
        $this->assertSame('+31612345671', $job['to']);
        $this->assertSame('Hello 1', $job['body']);
        $this->assertSame($mine->id, $job['campaign_id']);

        $this->assertSame('running', $mine->fresh()->status);
        $this->assertSame(2, CampaignRecipient::where('campaign_id', $mine->id)->where('status', 'sending')->where('sender_device_id', $device->id)->count());
        $this->assertSame('queued', $theirs->fresh()->status);

        // حجز ثانٍ لا يعيد نفس الرسائل
        $this->postJson('/api/device/jobs/claim', ['limit' => 10], $this->auth($token))
            ->assertOk()->assertJsonCount(0, 'jobs');

        // الهاتف الآخر يرى حملته فقط، بقناة واتساب
        $this->postJson('/api/device/jobs/claim', [], $this->auth($otherToken))
            ->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.channel', 'whatsapp');
    }

    public function test_report_finalizes_recipients_logs_messages_and_completes_campaign(): void
    {
        [$device, $token] = $this->pairedDevice();
        $campaign = $this->deviceCampaign($device);
        $ids = $this->postJson('/api/device/jobs/claim', [], $this->auth($token))->json('jobs.*.id');

        $this->postJson('/api/device/jobs/report', ['results' => [
            ['id' => $ids[0], 'status' => 'sent', 'sent_at' => now()->toIso8601String(), 'attempts' => 1],
            ['id' => $ids[1], 'status' => 'failed', 'error' => 'NO_SERVICE', 'attempts' => 3],
        ]], $this->auth($token))
            ->assertOk()->assertJsonPath('accepted', 2);

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->failed_count);
        $this->assertNotNull($campaign->finished_at);

        $this->assertSame(2, MessageLog::where('campaign_id', $campaign->id)->where('provider', SenderDevice::PROVIDER_SMS)->count());
        $this->assertSame('SENT', MessageLog::where('campaign_id', $campaign->id)->where('phone', '+31612345671')->value('status'));

        // تكرار التقرير لا يضاعف العدّادات
        $this->postJson('/api/device/jobs/report', ['results' => [
            ['id' => $ids[0], 'status' => 'sent'],
        ]], $this->auth($token))->assertOk()->assertJsonPath('duplicate', 1);
        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    public function test_manual_retry_can_upgrade_a_failed_recipient_to_sent(): void
    {
        [$device, $token] = $this->pairedDevice();
        $campaign = $this->deviceCampaign($device, Campaign::CHANNEL_DEVICE_WHATSAPP, 1);
        $id = $this->postJson('/api/device/jobs/claim', [], $this->auth($token))->json('jobs.0.id');

        $this->postJson('/api/device/jobs/report', ['results' => [['id' => $id, 'status' => 'failed', 'error' => 'WA_TIMEOUT']]], $this->auth($token))->assertOk();
        $this->assertSame('failed', $campaign->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->failed_count);

        // المستخدم ضغط "إعادة المحاولة" على الهاتف ونجحت
        $this->postJson('/api/device/jobs/report', ['results' => [['id' => $id, 'status' => 'sent']]], $this->auth($token))
            ->assertOk()->assertJsonPath('upgraded', 1)->assertJsonPath('duplicate', 0);

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertSame('sent', CampaignRecipient::find($id)->status);

        // لكن sent → failed لا يُقبل أبداً
        $this->postJson('/api/device/jobs/report', ['results' => [['id' => $id, 'status' => 'failed']]], $this->auth($token))
            ->assertOk()->assertJsonPath('duplicate', 1);
        $this->assertSame('sent', CampaignRecipient::find($id)->status);
    }

    public function test_report_ignores_recipients_owned_by_another_device(): void
    {
        [$device, $token] = $this->pairedDevice();
        [$other, $otherToken] = $this->pairedDevice();
        $this->deviceCampaign($other, Campaign::CHANNEL_DEVICE_SMS, 1);
        $id = $this->postJson('/api/device/jobs/claim', [], $this->auth($otherToken))->json('jobs.0.id');

        $this->postJson('/api/device/jobs/report', ['results' => [['id' => $id, 'status' => 'sent']]], $this->auth($token))
            ->assertOk()->assertJsonPath('unknown', 1)->assertJsonPath('accepted', 0);
        $this->assertSame('sending', CampaignRecipient::find($id)->status);
    }

    public function test_halt_stops_claiming(): void
    {
        [$device, $token] = $this->pairedDevice();
        $this->deviceCampaign($device);
        HaltService::halt();

        $this->postJson('/api/device/jobs/claim', [], $this->auth($token))
            ->assertOk()->assertJsonPath('halt', true)->assertJsonCount(0, 'jobs');
    }

    public function test_stale_claims_are_requeued_on_next_claim(): void
    {
        [$device, $token] = $this->pairedDevice();
        $campaign = $this->deviceCampaign($device, Campaign::CHANNEL_DEVICE_SMS, 1);
        $this->postJson('/api/device/jobs/claim', [], $this->auth($token))->assertJsonCount(1, 'jobs');

        CampaignRecipient::where('campaign_id', $campaign->id)->update(['claimed_at' => now()->subHours(2)]);

        // الرسالة تعود للطابور وتُحجز من جديد (المحاولة الثانية)
        $this->postJson('/api/device/jobs/claim', [], $this->auth($token))
            ->assertOk()->assertJsonCount(1, 'jobs');
        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->value('attempts'));
    }

    public function test_campaign_sender_hands_device_campaigns_off_without_calling_bulkgate(): void
    {
        [$device] = $this->pairedDevice();
        $campaign = $this->deviceCampaign($device);

        $client = $this->createMock(BulkGateClient::class);
        $client->expects($this->never())->method('send');

        $result = (new CampaignSender($client))->sendCampaign($campaign);

        $this->assertSame('handed_to_device', $result['status']);
        $this->assertSame('running', $campaign->fresh()->status);
        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count());
    }

    public function test_heartbeat_schedule_and_unpair(): void
    {
        [$device, $token] = $this->pairedDevice();
        $this->deviceCampaign($device);

        $this->postJson('/api/device/heartbeat', ['whatsapp_package' => 'com.whatsapp.w4b', 'accessibility_enabled' => true], $this->auth($token))
            ->assertOk()->assertJsonPath('pending_remote', 2);
        $this->assertSame('com.whatsapp.w4b', $device->fresh()->capabilities['whatsapp_package']);

        $this->getJson('/api/device/schedule', $this->auth($token))
            ->assertOk()->assertJsonCount(1, 'campaigns')->assertJsonPath('campaigns.0.channel', 'sms');

        $this->postJson('/api/device/unpair', [], $this->auth($token))->assertOk();
        $this->assertFalse($device->fresh()->is_active);
        $this->postJson('/api/device/heartbeat', [], $this->auth($token))->assertStatus(401);
    }
}
