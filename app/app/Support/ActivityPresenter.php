<?php

namespace App\Support;

use App\Livewire\BankSyncDate;
use App\Models\Family;
use App\Models\Student;
use App\Services\MonthNames;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * One activity-log entry in words, for the logs page: what happened, to
 * which student, and what changed — instead of "Payment #3612" and a JSON
 * dump.
 *
 * @phpstan-type Row array{icon: string, tone: string, title: string, lines: list<string>, student_id: ?int}
 */
final class ActivityPresenter
{
    /** Student fields as they are named on screen. */
    private const STUDENT_FIELDS = [
        'name' => 'log.field.name',
        'family_id' => 'log.field.family',
        'phone_primary_e164' => 'log.field.phone',
        'phone_secondary_e164' => 'log.field.phone2',
        'allow_sms' => 'log.field.allow_sms',
        'allow_whatsapp' => 'log.field.allow_whatsapp',
        'is_hidden' => 'log.field.hidden',
        'is_blocked_messages' => 'log.field.blocked',
        'is_in_person' => 'log.field.in_person',
        'excluded_from_send_all' => 'log.field.excluded_send_all',
        'default_fee_amount' => 'log.field.fee',
        'enrolled_at' => 'log.field.enrolled_at',
        'withdrawn_at' => 'log.field.withdrawn_at',
    ];

    private const BOOLEAN_FIELDS = ['allow_sms', 'allow_whatsapp', 'is_hidden', 'is_blocked_messages', 'is_in_person', 'excluded_from_send_all'];

    private const PAYMENT_FIELDS = [
        'amount' => 'log.field.amount',
        'period_month' => 'log.field.month',
        'period_year' => 'log.field.year',
        'method' => 'log.field.method',
        'paid_at' => 'log.field.paid_at',
        'note' => 'log.field.note',
        'student_id' => 'log.field.student',
    ];

    /** @param Collection<int, Student> $students keyed by id (with trashed) */
    public function __construct(
        private Collection $students,
        private Collection $families,
    ) {}

    /** The student an entry is about, if any — subject, or the payment's student. */
    public static function studentIdOf(Activity $a): ?int
    {
        if ($a->subject_type === Student::class && $a->subject_id) {
            return (int) $a->subject_id;
        }
        $changes = self::changes($a);
        $id = $changes['attributes']['student_id'] ?? $changes['old']['student_id'] ?? null;

        return $id ? (int) $id : null;
    }

    /** @return array{attributes: array, old: array} */
    public static function changes(Activity $a): array
    {
        $raw = $a->attribute_changes;
        $raw = $raw instanceof Collection ? $raw->toArray() : (is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []));

        return ['attributes' => (array) ($raw['attributes'] ?? []), 'old' => (array) ($raw['old'] ?? [])];
    }

    /** @return Row */
    public function present(Activity $a): array
    {
        $studentId = self::studentIdOf($a);
        $row = match ($a->log_name) {
            'payment' => $this->payment($a),
            'student' => $this->student($a),
            'deletion' => $this->deletion($a),
            BankSyncDate::LOG => $this->bankSync($a),
            'settings' => $this->settings($a),
            'user' => $this->user($a),
            'campaign' => $this->campaign($a),
            default => ['icon' => '•', 'tone' => 'info', 'title' => (string) $a->description, 'lines' => []],
        };

        return $row + ['student_id' => $studentId];
    }

    // ---- kinds ----------------------------------------------------------

    private function payment(Activity $a): array
    {
        ['attributes' => $new, 'old' => $old] = self::changes($a);
        $event = $a->event ?: $a->description;

        if ($event === 'created') {
            return ['icon' => '💶', 'tone' => 'add', 'title' => __('log.payment_created'), 'lines' => [$this->paymentSummary($new)]];
        }
        if ($event === 'deleted') {
            return ['icon' => '🗑️', 'tone' => 'delete', 'title' => __('log.payment_deleted'), 'lines' => [$this->paymentSummary($old)]];
        }

        $lines = [];
        foreach ($new as $field => $value) {
            if (!isset(self::PAYMENT_FIELDS[$field]) || $field === 'student_id') continue;
            $from = $this->paymentValue($field, $old[$field] ?? null);
            $to = $this->paymentValue($field, $value);
            if ($from === $to) continue;
            $lines[] = __('log.changed', ['field' => __(self::PAYMENT_FIELDS[$field]), 'from' => $from, 'to' => $to]);
        }

        return ['icon' => '✏️', 'tone' => 'edit', 'title' => __('log.payment_updated'), 'lines' => $lines];
    }

    private function student(Activity $a): array
    {
        ['attributes' => $new, 'old' => $old] = self::changes($a);
        $event = $a->event ?: $a->description;

        if ($event === 'created') {
            $lines = array_values(array_filter([
                isset($new['phone_primary_e164']) && $new['phone_primary_e164'] ? __('log.field.phone') . ': ' . $new['phone_primary_e164'] : null,
                isset($new['default_fee_amount']) && $new['default_fee_amount'] !== null ? __('log.field.fee') . ': ' . $this->money($new['default_fee_amount']) : null,
            ]));

            return ['icon' => '➕', 'tone' => 'add', 'title' => __('log.student_created'), 'lines' => $lines];
        }
        if ($event === 'deleted') {
            return ['icon' => '🗑️', 'tone' => 'delete', 'title' => __('log.student_deleted'), 'lines' => []];
        }
        if ($event === 'restored') {
            return ['icon' => '♻️', 'tone' => 'add', 'title' => __('log.student_restored'), 'lines' => []];
        }

        $lines = [];
        foreach ($new as $field => $value) {
            if (!isset(self::STUDENT_FIELDS[$field])) continue;
            // A yes/no field "changing" from nothing is the database default
            // being filled in, not an edit — and an unchanged value is noise.
            if (in_array($field, self::BOOLEAN_FIELDS, true) && ($old[$field] ?? null) === null) continue;
            $from = $this->studentValue($field, $old[$field] ?? null);
            $to = $this->studentValue($field, $value);
            if ($from === $to) continue;
            $lines[] = __('log.changed', ['field' => __(self::STUDENT_FIELDS[$field]), 'from' => $from, 'to' => $to]);
        }

        return ['icon' => '✏️', 'tone' => 'edit', 'title' => __('log.student_updated'), 'lines' => $lines];
    }

    private function deletion(Activity $a): array
    {
        $last = $a->properties?->get('last_paid_month');

        return [
            'icon' => '🗑️', 'tone' => 'delete', 'title' => __('log.student_deleted_kept'),
            'lines' => $last ? [__('log.last_paid_month', ['month' => $this->ymLabel((string) $last)])] : [],
        ];
    }

    private function bankSync(Activity $a): array
    {
        $date = (string) $a->properties?->get('date');
        $note = $a->properties?->get('note');

        return [
            'icon' => '🏦', 'tone' => 'info',
            'title' => __('log.bank_sync', ['date' => $date ? (BankSyncDate::label($date) ?? $date) : '—']),
            'lines' => $note ? ['📝 ' . $note] : [],
        ];
    }

    private function settings(Activity $a): array
    {
        $d = (string) $a->description;
        if (str_starts_with($d, 'revealed secret: ')) {
            return ['icon' => '🔑', 'tone' => 'info', 'title' => __('log.secret_revealed'), 'lines' => [substr($d, strlen('revealed secret: '))]];
        }

        return ['icon' => '⚙️', 'tone' => 'info', 'title' => $d, 'lines' => []];
    }

    private function user(Activity $a): array
    {
        ['attributes' => $new] = self::changes($a);
        $event = $a->event ?: $a->description;
        $name = $new['name'] ?? ('#' . $a->subject_id);

        return [
            'icon' => '👤', 'tone' => $event === 'deleted' ? 'delete' : ($event === 'created' ? 'add' : 'edit'),
            'title' => __('log.user_' . (in_array($event, ['created', 'updated', 'deleted'], true) ? $event : 'updated'), ['name' => $name]),
            'lines' => isset($new['role']) ? [__('log.field.role') . ': ' . $new['role']] : [],
        ];
    }

    private function campaign(Activity $a): array
    {
        ['attributes' => $new] = self::changes($a);
        $event = $a->event ?: $a->description;
        $lines = isset($new['status']) ? [__('columns.status') . ': ' . __('campaigns.status.' . $new['status'])] : [];

        return ['icon' => '📨', 'tone' => 'info', 'title' => __('log.campaign_' . ($event === 'created' ? 'created' : 'updated'), ['id' => $a->subject_id]), 'lines' => $lines];
    }

    // ---- values ---------------------------------------------------------

    private function paymentSummary(array $p): string
    {
        $parts = [$this->money($p['amount'] ?? 0)];
        if (!empty($p['period_month'])) {
            $parts[] = (MonthNames::full()[(int) $p['period_month']] ?? $p['period_month']) . ' ' . ($p['period_year'] ?? '');
        }
        if (!empty($p['method'])) {
            $parts[] = $this->method((string) $p['method']);
        }
        if (!empty($p['paid_at'])) {
            $parts[] = __('log.paid_on', ['date' => $this->date($p['paid_at'])]);
        }
        $out = implode(' · ', $parts);

        return !empty($p['note']) ? $out . ' — 📝 ' . $p['note'] : $out;
    }

    private function paymentValue(string $field, mixed $v): string
    {
        if ($v === null || $v === '') return '—';

        return match ($field) {
            'amount' => $this->money($v),
            'period_month' => MonthNames::full()[(int) $v] ?? (string) $v,
            'method' => $this->method((string) $v),
            'paid_at' => $this->date($v),
            default => (string) $v,
        };
    }

    private function studentValue(string $field, mixed $v): string
    {
        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return $v ? __('log.yes') : __('log.no');
        }
        if ($v === null || $v === '') return '—';

        return match ($field) {
            'default_fee_amount' => $this->money($v),
            'enrolled_at', 'withdrawn_at' => $this->date($v),
            'family_id' => $this->families->get((int) $v)?->displayName() ?? ('#' . $v),
            default => (string) $v,
        };
    }

    private function method(string $m): string
    {
        return match ($m) {
            'cash' => '💵 ' . __('payment.method_cash'),
            'bank' => '🏦 ' . __('payment.method_bank'),
            default => $m,
        };
    }

    private function money(mixed $v): string
    {
        return number_format((float) $v, 2) . ' €';
    }

    /** "6 October 2026" in the interface language — "06-10-2026" reads backwards in Arabic. */
    private function date(mixed $v): string
    {
        try {
            $d = Carbon::parse((string) $v)->timezone(config('app.timezone'));

            return $d->day . ' ' . (MonthNames::full()[$d->month] ?? '') . ' ' . $d->year;
        } catch (\Throwable) {
            return (string) $v;
        }
    }

    private function ymLabel(string $ym): string
    {
        [$y, $m] = array_pad(explode('-', $ym), 2, null);

        return (MonthNames::full()[(int) $m] ?? $m) . ' ' . $y;
    }
}
