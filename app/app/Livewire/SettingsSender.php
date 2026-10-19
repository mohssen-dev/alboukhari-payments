<?php

namespace App\Livewire;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Models\SenderDevice;
use App\Models\SenderDevicePairingCode;
use App\Services\SenderDeviceConfig;
use App\Services\SenderDeviceQueue;
use App\Support\AuthorizesLivewireWrite;
use Livewire\Component;

/**
 * Settings → Sender phone: pair a phone running the Al Boukhari Sender app,
 * see each paired phone's state as its last heartbeat reported it, disable or
 * remove it, and set the sending pace the phone follows.
 *
 * Admin only, like the rest of the settings: a paired phone sends messages in
 * the school's name.
 */
class SettingsSender extends Component
{
    use AuthorizesLivewireWrite;

    /** The pairing code on screen (its id), while it can still be used. */
    public ?int $codeId = null;

    public ?int $renamingId = null;
    public string $renameTo = '';

    /** sender_* settings, edited in the pace form. */
    public array $config = [];

    public function mount(): void
    {
        $this->config = SenderDeviceConfig::all();
        $this->codeId = SenderDevicePairingCode::where('created_by', auth()->id())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->value('id');
    }

    public function generateCode(): void
    {
        $this->assertAdmin();

        $this->codeId = SenderDevicePairingCode::generate(auth()->id())->id;
    }

    public function closeCode(): void
    {
        $this->codeId = null;
    }

    public function toggle(int $id): void
    {
        $this->assertAdmin();

        $device = SenderDevice::find($id);
        if (!$device) {
            return;
        }

        $device->update(['is_active' => !$device->is_active]);
        if (!$device->is_active) {
            // A disabled phone is refused by the API; what it claimed goes back to the queue.
            app(SenderDeviceQueue::class)->releaseClaims($device);
        }
        activity('sender_device')->causedBy(auth()->user())->performedOn($device)
            ->log(($device->is_active ? 'enabled: ' : 'disabled: ') . $device->name);

        $this->dispatch('toast', type: 'success', message: __($device->is_active ? 'sender.enabled_ok' : 'sender.disabled_ok', ['name' => $device->name]));
    }

    public function startRename(int $id): void
    {
        $this->assertAdmin();

        $device = SenderDevice::find($id);
        if ($device) {
            $this->renamingId = $device->id;
            $this->renameTo = $device->name;
        }
    }

    public function saveName(): void
    {
        $this->assertAdmin();

        $this->validate(['renameTo' => 'required|string|max:60'], [], ['renameTo' => __('sender.name')]);
        SenderDevice::whereKey($this->renamingId)->update(['name' => trim($this->renameTo)]);
        $this->reset(['renamingId', 'renameTo']);
    }

    public function delete(int $id): void
    {
        $this->assertAdmin();

        $device = SenderDevice::find($id);
        if (!$device) {
            return;
        }

        // A campaign still going out through this phone must be finished, canceled or moved first.
        $busy = Campaign::where('sender_device_id', $device->id)->whereIn('status', ['queued', 'running', 'paused'])->exists();
        if ($busy) {
            $this->dispatch('toast', type: 'error', message: __('sender.delete_busy'));
            return;
        }

        app(SenderDeviceQueue::class)->releaseClaims($device);
        activity('sender_device')->causedBy(auth()->user())->log('deleted: ' . $device->name);
        $device->delete();

        $this->dispatch('toast', type: 'success', message: __('sender.deleted_ok'));
    }

    public function saveConfig(): void
    {
        $this->assertAdmin();

        $this->validate([
            'config.sender_min_delay_sec' => 'required|integer|min:0|max:600',
            'config.sender_max_delay_sec' => 'required|integer|max:1800|gte:config.sender_min_delay_sec',
            'config.sender_batch_size' => 'required|integer|min:0|max:500',
            'config.sender_batch_pause_sec' => 'required|integer|min:0|max:3600',
            'config.sender_daily_cap_whatsapp' => 'required|integer|min:0|max:5000',
            'config.sender_daily_cap_sms' => 'required|integer|min:0|max:5000',
            'config.sender_window_start' => 'required|date_format:H:i',
            'config.sender_window_end' => 'required|date_format:H:i',
            'config.sender_whatsapp_mode' => 'required|in:auto,assisted',
            'config.sender_poll_interval_sec' => 'required|integer|min:5|max:300',
            'config.sender_max_attempts' => 'required|integer|min:1|max:10',
        ], [], collect(SenderDeviceConfig::KEYS)->mapWithKeys(fn ($k) => ["config.$k" => __("sender.cfg.$k")])->all());

        foreach (SenderDeviceConfig::KEYS as $key) {
            Setting::put($key, (string) $this->config[$key]);
        }

        $this->dispatch('toast', type: 'success', message: __('sender.config_saved'));
    }

    public function resetConfig(): void
    {
        $this->assertAdmin();

        $this->config = SenderDeviceConfig::DEFAULTS;
        $this->resetValidation();
    }

    public function render()
    {
        $code = $this->codeId ? SenderDevicePairingCode::with('device')->find($this->codeId) : null;
        $queue = app(SenderDeviceQueue::class);

        $devices = SenderDevice::orderByDesc('is_active')->orderByDesc('last_seen_at')->get()
            ->map(fn (SenderDevice $d) => [
                'device' => $d,
                'pending' => $queue->pendingCount($d),
                'holding' => CampaignRecipient::where('sender_device_id', $d->id)->where('status', 'sending')->count(),
                'hb' => $d->last_heartbeat ?? [],
            ]);

        return view('livewire.settings-sender', [
            'code' => $code,
            'devices' => $devices,
            'defaults' => SenderDeviceConfig::DEFAULTS,
        ]);
    }
}
