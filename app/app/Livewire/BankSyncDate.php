<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use Carbon\Carbon;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * The date bank payments were last entered from the bank statement, shown in
 * the navbar for everyone and set by hand by staff — the next check of the
 * statement starts after it. Kept in settings with who set it and when.
 *
 * Every save is also written to the activity log ('bank-sync'), which the
 * history window reads back: each round with its date, who set it, an
 * optional note, and the bank payments entered since the round before it,
 * so a round can be reviewed later.
 */
class BankSyncDate extends Component
{
    use AuthorizesLivewireWrite;

    public const DATE = 'bank_sync_date';
    public const BY = 'bank_sync_by';
    public const AT = 'bank_sync_at';
    public const LOG = 'bank-sync';
    /** Rounds shown in the history window. */
    public const HISTORY = 20;

    public string $date = '';
    public string $note = '';

    public bool $showHistory = false;
    /** The history round whose payments are listed (activity id). */
    public ?int $expanded = null;

    public function mount(): void
    {
        $this->date = (string) Setting::get(self::DATE, '');
    }

    public function save(): void
    {
        $this->assertCanWrite();

        $this->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:2020-01-01|before_or_equal:today',
            'note' => 'nullable|string|max:200',
        ], [], ['date' => __('banksync.date'), 'note' => __('banksync.note')]);

        Setting::put(self::DATE, $this->date);
        Setting::put(self::BY, (string) auth()->user()->name);
        Setting::put(self::AT, now()->format('Y-m-d H:i'));

        activity(self::LOG)
            ->causedBy(auth()->user())
            ->withProperties(array_filter([
                'date' => $this->date,
                'note' => trim($this->note) ?: null,
            ]))
            ->log('bank payments synced up to ' . $this->date);

        $this->note = '';
        $this->dispatch('toast', type: 'success', message: __('banksync.saved', ['date' => self::label($this->date)]));
    }

    public function openHistory(): void
    {
        $this->showHistory = true;
        $this->expanded = null;
    }

    public function closeHistory(): void
    {
        $this->showHistory = false;
        $this->expanded = null;
    }

    public function toggleRound(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
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

    /**
     * The latest rounds, newest first. A round's payments are the bank
     * payments ENTERED (created) after the round before it was saved and up
     * to this one — the work done in that round, whatever their paid date.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(int $limit = self::HISTORY): array
    {
        // One extra, older round: the start of the oldest round shown.
        $logs = Activity::with('causer')
            ->where('log_name', self::LOG)
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->values();

        $rounds = [];
        foreach ($logs->take($limit) as $i => $log) {
            $previous = $logs->get($i + 1);
            $payments = Payment::where('method', 'bank')
                ->where('created_at', '<=', $log->created_at)
                ->when($previous, fn ($q) => $q->where('created_at', '>', $previous->created_at));
            $date = (string) $log->properties?->get('date');
            $previousDate = $previous ? (string) $previous->properties?->get('date') : null;

            $rounds[] = [
                'id' => $log->id,
                'date' => $date,
                'date_label' => $date ? self::label($date) : '—',
                'from_label' => $previousDate ? self::label($previousDate) : null,
                'note' => $log->properties?->get('note'),
                'by' => $log->causer?->name ?? '—',
                'at' => $log->created_at?->format('Y-m-d H:i'),
                'count' => (clone $payments)->count(),
                'total' => (float) (clone $payments)->sum('amount'),
                'has_start' => (bool) $previous,
            ];
        }

        return $rounds;
    }

    /** The bank payments entered in one round (see history()). */
    public static function roundPayments(int $activityId): array
    {
        $log = Activity::where('log_name', self::LOG)->find($activityId);
        if (!$log) {
            return [];
        }
        $previous = Activity::where('log_name', self::LOG)->where('id', '<', $log->id)->orderByDesc('id')->first();
        $months = MonthNames::full();

        return Payment::with(['student' => fn ($q) => $q->withTrashed()])
            ->where('method', 'bank')
            ->where('created_at', '<=', $log->created_at)
            ->when($previous, fn ($q) => $q->where('created_at', '>', $previous->created_at))
            ->orderBy('paid_at')
            ->orderBy('id')
            ->limit(300)
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'student_id' => $p->student_id,
                'student' => $p->student?->name ?? '#' . $p->student_id,
                'period' => ($months[$p->period_month] ?? $p->period_month) . ' ' . $p->period_year,
                'amount' => (float) $p->amount,
                'paid_at' => $p->paid_at?->format('Y-m-d'),
                'note' => $p->note,
            ])
            ->all();
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
            'rounds' => $this->showHistory ? self::history() : [],
            'roundPayments' => $this->showHistory && $this->expanded ? self::roundPayments($this->expanded) : [],
        ]);
    }
}
