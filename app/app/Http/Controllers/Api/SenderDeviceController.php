<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\SenderDevice;
use App\Models\SenderDevicePairingCode;
use App\Services\HaltService;
use App\Services\SenderDeviceConfig;
use App\Services\SenderDeviceQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الـ API الذي يستهلكه تطبيق "مرسل البخاري" (mobile/). العقد موثّق في SENDER_PHONE_PLAN.md.
 */
class SenderDeviceController extends Controller
{
    public function __construct(private SenderDeviceQueue $queue) {}

    /** POST /api/device/pair — بدون توكن؛ يستهلك رمز اقتران ويصدر توكناً. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:16',
            'device_name' => 'required|string|max:100',
            'model' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:100',
            'android_version' => 'nullable|string|max:20',
            'sdk_int' => 'nullable|integer',
            'app_version' => 'nullable|string|max:40',
        ]);

        $code = strtoupper(trim($data['code']));

        [$device, $plainToken] = DB::transaction(function () use ($code, $data) {
            $pairing = SenderDevicePairingCode::where('code', $code)->lockForUpdate()->first();
            if (! $pairing || ! $pairing->isUsable()) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired pairing code.']);
            }

            $device = new SenderDevice([
                'name' => $data['device_name'],
                'model' => $data['model'] ?? null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'android_version' => $data['android_version'] ?? null,
                'sdk_int' => $data['sdk_int'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
                'is_active' => true,
                'paired_by' => $pairing->created_by,
            ]);
            $plain = $device->issueToken();
            $device->save();

            $pairing->update(['used_at' => now(), 'sender_device_id' => $device->id]);

            return [$device, $plain];
        });

        activity('sender_device')->performedOn($device)->log("paired: {$device->name}");

        return response()->json([
            'device_id' => $device->id,
            'token' => $plainToken,
            'server_time' => now()->toIso8601String(),
            'config' => SenderDeviceConfig::forDevice(),
        ], 201);
    }

    /** POST /api/device/jobs/claim */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'channels' => 'nullable|array',
            'channels.*' => 'in:sms,whatsapp',
        ]);
        $device = $this->device($request);

        $jobs = $this->queue->claim($device, (int) ($data['limit'] ?? 50), $data['channels'] ?? null);

        return response()->json([
            'halt' => HaltService::isHalted(),
            'jobs' => $jobs,
            'pending_remote' => $this->queue->pendingCount($device),
            'config' => SenderDeviceConfig::forDevice(),
        ]);
    }

    /** POST /api/device/jobs/report */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'results' => 'required|array|max:200',
            'results.*.id' => 'required|integer',
            'results.*.status' => 'required|in:sent,failed,skipped',
            'results.*.error' => 'nullable|string',
            'results.*.attempts' => 'nullable|integer',
            'results.*.sent_at' => 'nullable|string',
        ]);

        $stats = $this->queue->report($this->device($request), $data['results']);

        return response()->json(['ok' => true] + $stats);
    }

    /** POST /api/device/heartbeat */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $payload = $request->all();

        $device->forceFill([
            'last_seen_at' => now(),
            'last_heartbeat' => $payload,
            'capabilities' => array_filter([
                'whatsapp_package' => $payload['whatsapp_package'] ?? null,
                'accessibility_enabled' => $payload['accessibility_enabled'] ?? null,
                'sms_subscription_id' => $payload['sms_subscription_id'] ?? null,
            ], fn ($v) => $v !== null),
            'app_version' => $payload['app_version'] ?? $device->app_version,
        ])->save();

        return response()->json([
            'halt' => HaltService::isHalted(),
            'pending_remote' => $this->queue->pendingCount($device),
            'config' => SenderDeviceConfig::forDevice(),
        ]);
    }

    /** GET /api/device/schedule — الحملات المسندة لهذا الهاتف التي لم تنتهِ بعد. */
    public function schedule(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $campaigns = Campaign::query()
            ->where('sender_device_id', $device->id)
            ->whereIn('channel', Campaign::DEVICE_CHANNELS)
            ->whereIn('status', ['queued', 'running', 'paused'])
            ->orderByRaw('scheduled_at IS NULL, scheduled_at ASC')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'channel' => $c->deviceChannelName(),
                'status' => $c->status,
                'total_recipients' => (int) $c->total_recipients,
                'sent_count' => (int) $c->sent_count,
                'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            ]);

        return response()->json(['campaigns' => $campaigns]);
    }

    /** POST /api/device/unpair — يبطل التوكن؛ الحملات المسندة تبقى للمدير ليعيد إسنادها. */
    public function unpair(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $device->forceFill(['is_active' => false, 'token_hash' => SenderDevice::hashToken('revoked:' . $device->id . ':' . bin2hex(random_bytes(8)))])->save();
        activity('sender_device')->performedOn($device)->log("unpaired: {$device->name}");

        return response()->json(['ok' => true]);
    }

    private function device(Request $request): SenderDevice
    {
        return $request->attributes->get('senderDevice');
    }
}
