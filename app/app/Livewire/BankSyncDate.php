<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use Carbon\Carbon;
use Livewire\Component;

/**
 * The date bank payments were last entered from the bank statement, shown in
 * the navbar for everyone and set by hand by staff — the next check of the
 * statement starts after it. Kept in settings with who set it and when.
 */
class BankSyncDate extends Component
{
    use AuthorizesLivewireWrite;

    public const DATE = 'bank_sync_date';
    public const BY = 'bank_sync_by';
    public const AT = 'bank_sync_at';

    public string $date = '';

    public function mount(): void
    {
        $this->date = (string) Setting::get(self::DATE, '');
    }

    public function save(): void
    {
        $this->assertCanWrite();

        $this->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:2020-01-01|before_or_equal:today',
        ], [], ['date' => __('banksync.date')]);

        Setting::put(self::DATE, $this->date);
        Setting::put(self::BY, (string) auth()->user()->name);
        Setting::put(self::AT, now()->format('Y-m-d H:i'));

        activity('bank-sync')
            ->causedBy(auth()->user())
            ->withProperties(['date' => $this->date])
            ->log('bank payments synced up to ' . $this->date);

        $this->dispatch('toast', type: 'success', message: __('banksync.saved', ['date' => self::label($this->date)]));
    }

    /** "12 September 2026" in the interface language. */
    public static function label(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        $d = Carbon::createFromFormat('Y-m-d', $date);

        return $d->day . ' ' . (MonthNames::full()[$d->month] ?? '') . ' ' . $d->year;
    }

    public function render()
    {
        $saved = (string) Setting::get(self::DATE, '');
        $days = $saved ? (int) Carbon::createFromFormat('Y-m-d', $saved)->startOfDay()->diffInDays(today()) : null;

        return view('livewire.bank-sync-date', [
            'saved' => $saved,
            'savedLabel' => self::label($saved),
            'short' => $saved ? Carbon::createFromFormat('Y-m-d', $saved)->format('d-m') : null,
            'days' => $days,
            'by' => Setting::get(self::BY),
            'at' => Setting::get(self::AT),
            'canWrite' => (bool) auth()->user()?->canWrite(),
        ]);
    }
}
