<?php

namespace App\Console\Commands;

use App\Models\SenderDevice;
use App\Services\SenderDeviceQueue;
use Illuminate\Console\Command;

class SenderDevicesList extends Command
{
    protected $signature = 'sender:devices';
    protected $description = 'يعرض الهواتف المقترنة وحالتها وعدد الرسائل بانتظار كل منها.';

    public function handle(SenderDeviceQueue $queue): int
    {
        $devices = SenderDevice::orderBy('id')->get();
        if ($devices->isEmpty()) {
            $this->line('No paired devices. Run: php artisan sender:pair-code');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Model', 'Android', 'App', 'Active', 'Online', 'Last seen', 'WhatsApp pkg', 'Pending'],
            $devices->map(fn (SenderDevice $d) => [
                $d->id,
                $d->name,
                trim(($d->manufacturer ?? '') . ' ' . ($d->model ?? '')),
                $d->android_version ?? '-',
                $d->app_version ?? '-',
                $d->is_active ? 'yes' : 'no',
                $d->isOnline() ? 'yes' : 'no',
                $d->last_seen_at?->diffForHumans() ?? '-',
                $d->capabilities['whatsapp_package'] ?? '-',
                $queue->pendingCount($d),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
