<?php

namespace App\Console\Commands;

use App\Models\SenderDevicePairingCode;
use Illuminate\Console\Command;

class SenderPairCode extends Command
{
    protected $signature = 'sender:pair-code {--minutes=10 : صلاحية الرمز بالدقائق}';
    protected $description = 'يولّد رمز اقتران لتطبيق الهاتف المرسِل (بديل مؤقت لتبويب الويب).';

    public function handle(): int
    {
        $code = SenderDevicePairingCode::generate(null, (int) $this->option('minutes'));

        $this->info('Pairing code: ' . $code->code);
        $this->line('Expires at:   ' . $code->expires_at->format('Y-m-d H:i:s'));
        $this->line('QR payload:   ' . $code->qrPayload());

        return self::SUCCESS;
    }
}
