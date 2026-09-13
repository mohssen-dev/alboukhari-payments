<?php

namespace App\Support;

use App\Livewire\StudentsGrid;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;

/**
 * One row of the students grid — the single source of truth for BOTH the full
 * grid render and the in-place row patch sent after a change.
 *
 * Saving a payment used to make the whole grid re-render: 100 rows came back
 * as ~1.6 MB of HTML (4.8 MB at 500 rows) and the browser re-morphed all of
 * it — the lag users felt after every save. Now only the touched row is
 * rebuilt from the same partial and swapped in place (~3 KB), so a full
 * render and a patched row can never disagree.
 */
final class GridRow
{
    /** Eager loads a row needs, scoped to the year on screen. */
    public static function relations(int $year): array
    {
        // Year-scoped: the grid only renders one year, so loading other years'
        // rows just inflates hydration cost. (suspensions stay unscoped —
        // activeSuspension() needs current state.)
        return [
            'family:id,guardian_name,is_blocked_messages',
            'family.students:id,family_id,name',
            'payments' => fn ($q) => $q->where('period_year', $year),
            'markers' => fn ($q) => $q->where('period_year', $year),
            'surcharges' => fn ($q) => $q->where('period_year', $year),
            'feeOverrides' => fn ($q) => $q->where('period_year', $year),
            'suspensions',
        ];
    }

    /**
     * Compute everything the row shows. $student must carry relations($year).
     *
     * @return array{cells: array<int, array>, row: array}
     */
    public static function build(Student $student, int $year): array
    {
        // Balance means "owed to date": a partial ADVANCE payment for a future
        // month must not increase the debt figure.
        $nowYm = ((int) date('Y') * 12) + (int) date('n');

        // Batch-compute all 12 months in one pass.
        $statuses = MonthStatusResolver::resolveAll($student, $year);
        $paidAll  = FeeResolver::paidAllMonths($student, $year);
        $dueAll   = FeeResolver::dueAllMonths($student, $year);

        // Latest cash/bank payment per month, for the method icon.
        $lastMethodByMonth = [];
        foreach ($student->payments as $p) {
            if ($p->period_year !== $year) continue;
            if ($p->method !== 'cash' && $p->method !== 'bank') continue;
            $existing = $lastMethodByMonth[$p->period_month] ?? null;
            if (!$existing || $p->paid_at > $existing->paid_at) {
                $lastMethodByMonth[$p->period_month] = $p;
            }
        }

        $cells = [];
        $balance = 0.0;
        foreach (range(1, 12) as $m) {
            $status = $statuses[$m];
            $paid = $paidAll[$m];
            $due = $dueAll[$m];

            $methodIcon = '';
            if ($paid > 0 && isset($lastMethodByMonth[$m])) {
                $methodIcon = $lastMethodByMonth[$m]->methodIcon();
            } elseif ($status === 'legacy_zero') {
                $methodIcon = '🏦';
            }

            $cells[$m] = [
                'status' => $status,
                'paid' => $paid,
                'due' => $due,
                'methodIcon' => $methodIcon,
                'class' => self::cellClass($status),
                'display' => self::cellDisplay($status, $paid),
                'label' => MonthStatusResolver::label($status),
            ];

            $isFutureMonth = (($year * 12) + $m) > $nowYm;
            if (!$isFutureMonth && ($status === 'unpaid' || $status === 'late' || $status === 'partial')) {
                $balance += max(0, $due - $paid);
            }
        }

        $siblings = $student->family ? max(0, $student->family->students->count() - 1) : 0;

        // A deleted student is shown read-only, for its payment record.
        $isDeleted = $student->trashed();
        $deletedNote = null;
        if ($isDeleted) {
            $w = $student->withdrawn_at;
            $neverOwed = !$w || ($student->enrolled_at && $w->lte($student->enrolled_at->copy()->startOfMonth()));
            $lastOwed = $w?->copy()->startOfMonth()->subMonth();
            $deletedNote = $neverOwed
                ? __('delete.deleted_badge_none')
                : __('delete.deleted_badge', ['month' => MonthNames::full()[$lastOwed->month] . ' ' . $lastOwed->year]);
        }

        return [
            'cells' => $cells,
            // Client-side data for search / filters / sort / CSV.
            'row' => [
                'id' => $student->id,
                'extId' => $student->external_id,
                'name' => $student->name,
                'phone' => $student->phone_primary_e164 ?: '',
                'siblings' => $siblings,
                'balance' => round($balance, 2),
                'isHidden' => (bool) $student->is_hidden,
                'isBlocked' => (bool) $student->is_blocked_messages,
                'isInPerson' => (bool) $student->is_in_person,
                'excludedSendAll' => (bool) $student->excluded_from_send_all,
                'isDeleted' => $isDeleted,
                'badge' => $isDeleted ? '🗑️' : $student->statusBadge(),
                'skipReason' => $isDeleted ? $deletedNote : $student->skipReason(),
                'haystack' => mb_strtolower(implode(' ', array_filter([
                    $student->name,
                    $student->phone_primary_raw,
                    $student->phone_primary_e164,
                    $student->external_id,
                    (string) $student->id,
                ]))),
            ],
        ];
    }

    /**
     * Render one <tr> from the shared partial.
     *
     * Rendered as if inside the grid component, because Livewire only emits
     * its morph markers (<!--[if BLOCK]-->) while a component is rendering.
     * A patched row must match a full render byte for byte, or the next
     * morph of the grid compares against a differently-shaped DOM.
     */
    public static function html(Student $student, array $built, ?array $months = null): string
    {
        $blade = app(ExtendBlade::class);
        $blade->startLivewireRendering(new StudentsGrid());

        try {
            return view('livewire.partials.grid-row', [
                'student' => $student,
                'built' => $built,
                'months' => $months ?? MonthNames::full(),
            ])->render();
        } finally {
            $blade->endLivewireRendering();
        }
    }

    /**
     * Browser payload for the 'grid-row-updated' event, or null when the
     * student no longer exists.
     *
     * @return array{id: int, year: int, row: array, html: string}|null
     */
    public static function payload(int $studentId, int $year): ?array
    {
        $student = Student::with(self::relations($year))->find($studentId);
        if (!$student) {
            return null;
        }

        $built = self::build($student, $year);

        return [
            'id' => $student->id,
            'year' => $year,
            'row' => $built['row'],
            'html' => self::html($student, $built),
        ];
    }

    /** Status → the colour class shared by the grid and the family window. */
    public static function cellClass(string $status): string
    {
        return match ($status) {
            'paid' => 'cell-paid',
            'paid_advance' => 'cell-paid-advance',
            'partial' => 'cell-partial',
            'unpaid' => 'cell-unpaid',
            'late' => 'cell-late',
            'legacy_zero' => 'cell-legacy-zero',
            'not_enrolled' => 'cell-not-enrolled',
            default => 'cell-notdue',
        };
    }

    /** Status → the short text a month cell shows (amount, X, ·, −). */
    public static function cellDisplay(string $status, float $paid): string
    {
        return match ($status) {
            'paid', 'paid_advance', 'partial' => number_format($paid, 0),
            'legacy_zero' => '0',
            'late' => 'X',
            'unpaid' => '·',
            'not_enrolled' => '−',
            default => '',
        };
    }
}
