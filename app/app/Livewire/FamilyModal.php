<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
use App\Support\GridRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FamilyModal extends Component
{
    use AuthorizesLivewireWrite;
    use DispatchesGridRow;

    public bool $isOpen = false;
    #[Locked] public ?int $studentId = null;
    #[Locked] public ?int $familyId = null;
    public string $familyTitle = '';
    public string $guardianPhone = '';
    public array $members = [];

    /*
     * The family window: every child's payments for the chosen year in a
     * table on top, and under it one amount per child for the chosen month.
     *
     * Each amount is that month's TOTAL for the child, not money added on
     * top. Raising it records the difference as a new payment; lowering it
     * reduces (or removes) what was recorded. It used to only ever add, so a
     * mistyped amount could not be corrected from here.
     *
     * Several months can be picked at once (a parent paying June–September
     * in one go). Then each amount is what the child owes PER MONTH, and a
     * save only tops up the picked months that hold less than it — it never
     * reduces anything; lowering stays a one-month operation.
     */
    public int $year = 0;
    public int $month = 1;
    /** @var list<int> the months being paid for, ascending; always holds $month */
    public array $selectedMonths = [];
    public string $method = 'cash';
    public string $paid_at = '';
    /** @var array<int, string> student id => the month's total (or, for several months, the amount per month) as typed */
    public array $amounts = [];

    public function mount(?int $initialStudentId = null): void
    {
        if ($initialStudentId) {
            $this->open($initialStudentId);
        }
    }

    /**
     * Opens on the family's oldest unpaid month (see defaultMonths()), not on
     * the current month — that is the month a parent at the desk pays for.
     * $month forces a month instead.
     *
     * The browser opens the window at once (event 'family-open', see
     * abOpenFamily in the layout) and calls this for the content.
     */
    public function open(int $studentId, ?int $year = null, ?int $month = null): void
    {
        $this->studentId = $studentId;
        $this->year = self::clampYear($year ?? (int) date('Y'));
        $this->method = 'cash';
        $this->paid_at = now()->format('Y-m-d');
        $this->resetValidation();

        try {
            $this->loadMembers();
        } catch (\Throwable $e) {
            report($e);
            $this->reset(['isOpen', 'studentId', 'familyId', 'members', 'amounts']);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        $this->selectMonths($month ? [$month] : $this->defaultMonths());
        $this->isOpen = true;
    }

    /** Last year, this year and next year — next year so fees can be paid ahead. */
    public static function yearOptions(): array
    {
        $y = (int) date('Y');

        return [$y - 1, $y, $y + 1];
    }

    public function updatedYear(): void
    {
        $this->year = self::clampYear((int) $this->year);
        $this->reload();
        $this->selectMonths($this->defaultMonths());
    }

    public function updatedMonth(): void
    {
        $this->selectMonths([(int) $this->month]);
    }

    /**
     * Pick the months to pay for: one month, or several to pay them together.
     * The browser picks months on its own (no round-trip); this is for the
     * server side and the tests.
     */
    public function pickMonths(array $months): void
    {
        $this->selectMonths($months);
    }

    /**
     * The month to open on: the oldest month up to now that some child still
     * owes; when nothing is owed, the next month still to be paid (paying
     * ahead); when the whole year is paid, the current month.
     *
     * @return list<int>
     */
    public function defaultMonths(): array
    {
        $nowYm = ((int) date('Y') * 12) + (int) date('n');
        $open = fn (int $m, bool $dueOnly) => collect($this->members)->contains(function ($x) use ($m, $dueOnly) {
            $c = $x['months'][$m] ?? null;
            if (!$c || $c['outside']) return false;

            return $dueOnly
                ? in_array($c['status'], ['unpaid', 'late', 'partial'], true)
                : $c['due'] - $c['paid'] > 0.005;
        });

        foreach (range(1, 12) as $m) {
            if (($this->year * 12 + $m) <= $nowYm && $open($m, true)) return [$m];
        }
        foreach (range(1, 12) as $m) {
            if (($this->year * 12 + $m) > $nowYm && $open($m, false)) return [$m];
        }

        return [$this->year === (int) date('Y') ? (int) date('n') : 1];
    }

    /** Set the picked months and refresh what depends on them — no database work. */
    private function selectMonths(array $months): void
    {
        $months = collect($months)
            ->map(fn ($m) => (int) $m)
            ->filter(fn ($m) => $m >= 1 && $m <= 12)
            ->unique()->sort()->values()->all();
        if (!$months) {
            return;
        }

        $this->selectedMonths = $months;
        $this->month = $months[0];
        $this->applySelection();
    }

    public function isMultiMonth(): bool
    {
        return count($this->selectedMonths) > 1;
    }

    /**
     * Bring each child's recorded total for the chosen month to the amount
     * typed: more → the difference is a new payment (method and date from the
     * form); less → recorded payments are reduced, newest first, and removed
     * when they reach zero.
     */
    public function saveAll(?array $months = null, ?array $amounts = null): void
    {
        $this->assertCanWrite();

        // The browser sends the months and amounts it shows; without them the
        // properties are used (server-side callers, tests).
        if ($months !== null) {
            $this->selectMonths($months);
        }
        if ($amounts !== null) {
            $this->amounts = $amounts;
        }

        $this->year = self::clampYear((int) $this->year);
        $this->validate([
            'month' => 'required|integer|min:1|max:12',
            'method' => 'required|in:cash,bank',
            'paid_at' => 'required|date',
            'amounts' => 'array',
            'amounts.*' => 'nullable|numeric|min:0|max:100000',
        ]);

        // Who may be paid for comes from the database — never from $members
        // or the keys of $amounts, which are both plain client state.
        $payable = $this->payableStudents();

        if ($this->isMultiMonth()) {
            $this->saveMultiMonth($payable);
            return;
        }

        try {
            [$added, $removed, $changed] = DB::transaction(function () use ($payable) {
                $added = 0.0;
                $removed = 0.0;
                $changed = [];

                foreach ($payable as $student) {
                    if (!array_key_exists($student->id, $this->amounts)) continue;
                    if (FeeResolver::isOutsideEnrollment($student, $this->year, $this->month)) continue;

                    $raw = $this->amounts[$student->id];
                    $target = ($raw === null || $raw === '') ? 0.0 : round((float) $raw, 2);

                    $rows = Payment::where('student_id', $student->id)
                        ->where('period_year', $this->year)
                        ->where('period_month', $this->month)
                        ->whereIn('method', ['cash', 'bank'])
                        ->orderByDesc('paid_at')
                        ->orderByDesc('id')
                        ->get();
                    $current = round((float) $rows->sum('amount'), 2);

                    if (abs($target - $current) < 0.005) continue;
                    $changed[] = $student->id;

                    if ($target > $current) {
                        Payment::create([
                            'student_id' => $student->id,
                            'period_year' => $this->year,
                            'period_month' => $this->month,
                            'amount' => round($target - $current, 2),
                            'method' => $this->method,
                            'paid_at' => $this->paid_at,
                        ]);
                        $added += $target - $current;
                        continue;
                    }

                    $excess = round($current - $target, 2);
                    $removed += $excess;
                    foreach ($rows as $payment) {
                        if ($excess <= 0.005) break;
                        $amount = (float) $payment->amount;
                        if ($amount <= $excess + 0.005) {
                            $payment->delete();
                            $excess = round($excess - $amount, 2);
                        } else {
                            $payment->update(['amount' => round($amount - $excess, 2)]);
                            $excess = 0.0;
                        }
                    }
                }

                return [$added, $removed, $changed];
            });
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        if (!$changed) {
            $this->dispatch('toast', message: __('family.no_changes'), type: 'error');
            return;
        }

        foreach ($changed as $id) {
            $this->dispatch('payment-saved', studentId: $id);
            $this->dispatchGridRow($id, $this->year, 'year');
        }
        // Say only what happened: "added 60 €", not "added 60 € · reduced 0 €".
        $key = match (true) {
            $removed < 0.005 => 'family.saved_added',
            $added < 0.005 => 'family.saved_removed',
            default => 'family.saved_changes',
        };
        $this->dispatch('toast', type: 'success', message: __($key, [
            'added' => number_format($added, 2),
            'removed' => number_format($removed, 2),
        ]));

        // Stay open: the table now shows what was recorded.
        $this->reload();
    }

    /**
     * Several months at once: each child's amount is per month. A picked
     * month holding less than it gets the difference as a new payment; a
     * month already holding as much or more is left alone — never reduced.
     *
     * @param Collection<int, Student> $payable
     */
    private function saveMultiMonth(Collection $payable): void
    {
        $months = collect($this->selectedMonths)
            ->map(fn ($m) => (int) $m)
            ->filter(fn ($m) => $m >= 1 && $m <= 12)
            ->unique()->sort()->values()->all();

        try {
            [$added, $count, $changed] = DB::transaction(function () use ($payable, $months) {
                $added = 0.0;
                $count = 0;
                $changed = [];

                foreach ($payable as $student) {
                    $raw = $this->amounts[$student->id] ?? null;
                    $target = ($raw === null || $raw === '') ? 0.0 : round((float) $raw, 2);
                    if ($target <= 0.005) continue;

                    foreach ($months as $m) {
                        if (FeeResolver::isOutsideEnrollment($student, $this->year, $m)) continue;

                        $current = round((float) Payment::where('student_id', $student->id)
                            ->where('period_year', $this->year)
                            ->where('period_month', $m)
                            ->whereIn('method', ['cash', 'bank'])
                            ->sum('amount'), 2);
                        $diff = round($target - $current, 2);
                        if ($diff <= 0.005) continue;

                        Payment::create([
                            'student_id' => $student->id,
                            'period_year' => $this->year,
                            'period_month' => $m,
                            'amount' => $diff,
                            'method' => $this->method,
                            'paid_at' => $this->paid_at,
                        ]);
                        $added += $diff;
                        $count++;
                        $changed[$student->id] = true;
                    }
                }

                return [$added, $count, array_keys($changed)];
            });
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        if (!$changed) {
            $this->dispatch('toast', message: __('family.no_changes'), type: 'error');
            return;
        }

        foreach ($changed as $id) {
            $this->dispatch('payment-saved', studentId: $id);
            $this->dispatchGridRow($id, $this->year, 'year');
        }
        $this->dispatch('toast', type: 'success', message: __('family.saved_multi', [
            'count' => $count,
            'added' => number_format($added, 2),
        ]));

        $this->reload();
    }

    /**
     * The given month (default: the first picked one) becomes this child's
     * first billed month — earlier months stop being owed.
     */
    public function setEnrollmentMonth(int $studentId, ?int $month = null): void
    {
        $this->assertCanWrite();

        $student = $this->payableStudents()->get($studentId);
        if (!$student) {
            return;
        }

        $month = max(1, min(12, $month ?? $this->month));
        $startYm = $this->year * 12 + $month;
        if ($student->withdrawn_at && ($student->withdrawn_at->year * 12 + $student->withdrawn_at->month) <= $startYm) {
            $this->dispatch('toast', message: __('enroll.after_withdrawal'), type: 'error');
            return;
        }

        $student->update(['enrolled_at' => sprintf('%04d-%02d-01', $this->year, $month)]);
        $this->afterEnrollmentChange($student->id, __('enroll.saved', [
            'month' => (MonthNames::full()[$month] ?? '') . ' ' . $this->year,
        ]));
    }

    public function clearEnrollment(int $studentId): void
    {
        $this->assertCanWrite();

        $student = $this->payableStudents()->get($studentId);
        if (!$student) {
            return;
        }

        $student->update(['enrolled_at' => null]);
        $this->afterEnrollmentChange($student->id, __('enroll.cleared'));
    }

    private function afterEnrollmentChange(int $studentId, string $message): void
    {
        $this->dispatchGridRow($studentId, $this->year, 'student');
        $this->dispatch('student-updated', studentId: $studentId);
        $this->dispatch('toast', message: $message, type: 'success');
        $this->reload();
    }

    /**
     * Rebuild the window after a change, keeping the picked months. If that
     * fails (say the student was deleted in another tab) the window closes
     * with a message — an uncaught error here used to replace the whole page
     * with an error screen.
     */
    private function reload(): void
    {
        try {
            $this->loadMembers();
            $this->applySelection();
        } catch (\Throwable $e) {
            report($e);
            $this->close();
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
        }
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'studentId', 'familyId', 'familyTitle', 'guardianPhone', 'members', 'amounts', 'year', 'month', 'selectedMonths', 'method', 'paid_at']);
        $this->resetValidation();
        $this->dispatch('close-modal');
    }

    public function viewStudent(int $id): void
    {
        $this->close();
        $this->dispatch('open-student-panel', studentId: $id);
    }

    public function payNow(int $id, int $month): void
    {
        $this->close();
        $this->dispatch('open-payment-modal', studentId: $id, year: (int) date('Y'), month: $month);
    }

    public function render()
    {
        return view('livewire.family-modal', [
            'nowYm' => ((int) date('Y') * 12) + (int) date('n'),
            'monthNames' => MonthNames::full(),
            'yearOptions' => self::yearOptions(),
            'canWrite' => (bool) auth()->user()?->canWrite(),
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
        ]);
    }

    private static function clampYear(int $year): int
    {
        $options = self::yearOptions();

        return max($options[0], min($options[count($options) - 1], $year));
    }

    /** @return Collection<int, Student> keyed by id */
    private function payableStudents(): Collection
    {
        $student = Student::find($this->studentId);
        if (!$student) {
            return collect();
        }

        return $student->family_id
            ? Student::where('family_id', $student->family_id)->get()->keyBy('id')
            : collect([$student->id => $student]);
    }

    /**
     * Every child's year: per month its status, fee, what is recorded and a
     * suggested amount. Everything the window does with the picked months is
     * worked out from this — in applySelection() here and in the browser.
     */
    private function loadMembers(): void
    {
        // The student's OWN relations must be loaded too, not just the
        // family's. A student without a family falls into the branch below
        // that builds the member list from $student alone — with the own
        // relations unloaded, dueAllMonths() sees no feeOverrides and no
        // surcharges (it only reads them behind relationLoaded guards) and
        // silently reports the plain default fee, inventing debt for an
        // exempted child and hiding a surcharge on another.
        $student = Student::with([
            'payments', 'feeOverrides', 'surcharges', 'markers', 'suspensions',
            'family.students.payments',
            'family.students.feeOverrides',
            'family.students.surcharges',
            'family.students.markers',
            'family.students.suspensions',
        ])->findOrFail($this->studentId);

        $this->familyId = $student->family_id;

        if ($student->family) {
            $members = $student->family->students->sortBy('id')->values();
            $this->familyTitle = $student->family->displayName();
            $this->guardianPhone = $student->family->phone_primary_e164 ?: '';
        } else {
            $members = collect([$student]);
            $this->familyTitle = $student->name;
            $this->guardianPhone = $student->phone_primary_e164 ?: '';
        }

        // "Owed" counts only months that are due by now, as the grid does.
        $nowYm = ((int) date('Y') * 12) + (int) date('n');

        $this->members = $members->map(function (Student $s) use ($nowYm) {
            $statuses = MonthStatusResolver::resolveAll($s, $this->year);
            $dueAll   = FeeResolver::dueAllMonths($s, $this->year);
            $paidAll  = FeeResolver::paidAllMonths($s, $this->year);

            $months = [];
            $balance = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $st = $statuses[$m];
                $outside = FeeResolver::isOutsideEnrollment($s, $this->year, $m);
                $paid = round($paidAll[$m], 2);
                $remaining = round(max(0, $dueAll[$m] - $paidAll[$m]), 2);
                $months[$m] = [
                    'status' => $st,
                    'paid' => $paid,
                    'due' => round($dueAll[$m], 2),
                    'class' => GridRow::cellClass($st),
                    'display' => GridRow::cellDisplay($st, $paidAll[$m]),
                    'label' => MonthStatusResolver::label($st),
                    'outside' => $outside,
                    // One month picked: what is recorded, or what is owed.
                    'suggest' => match (true) {
                        $outside => '',
                        $paid > 0.005 => self::plainAmount($paid),
                        $remaining > 0.005 => self::plainAmount($remaining),
                        default => '',
                    },
                ];
                $isDueByNow = (($this->year * 12) + $m) <= $nowYm;
                if ($isDueByNow && in_array($st, ['unpaid', 'late', 'partial'], true)) {
                    $balance += max(0, $dueAll[$m] - $paidAll[$m]);
                }
            }

            return [
                'id' => $s->id,
                'external_id' => $s->external_id,
                'name' => $s->name,
                'phone' => $s->phone_primary_e164 ?: '',
                'is_self' => $s->id === $this->studentId,
                'badge' => $s->statusBadge(),
                'skip_reason' => $s->skipReason(),
                'months' => $months,
                'year_paid' => round(array_sum($paidAll), 2),
                'balance' => round($balance, 2),
                'enrolled_label' => $s->enrolled_at
                    ? (MonthNames::full()[$s->enrolled_at->month] ?? '') . ' ' . $s->enrolled_at->year
                    : null,
                'enrolled_ym' => $s->enrolled_at ? $s->enrolled_at->year * 12 + $s->enrolled_at->month : null,
                // Year*12+month of every recorded cash/bank payment — the
                // enrolment confirm counts those before the picked month.
                'pay_yms' => $s->payments
                    ->filter(fn ($p) => in_array($p->method, ['cash', 'bank'], true))
                    ->map(fn ($p) => $p->period_year * 12 + $p->period_month)
                    ->values()->all(),
            ];
        })->values()->toArray();
    }

    /**
     * What depends on the picked months: each child's summary of the first
     * picked month, and the amounts suggested — one month: what is recorded
     * or owed; several: the fee of the first picked month still owing (per
     * month). The browser works out the same from $members on its own.
     */
    private function applySelection(): void
    {
        $multi = $this->isMultiMonth();
        $m = $this->month;
        $ym = $this->year * 12 + $m;
        $amounts = [];

        foreach ($this->members as $i => $x) {
            $c = $x['months'][$m];
            $selEnrolled = array_values(array_filter($this->selectedMonths, fn ($mm) => !$x['months'][$mm]['outside']));
            $selOwing = array_values(array_filter($selEnrolled, fn ($mm) => $x['months'][$mm]['due'] - $x['months'][$mm]['paid'] > 0.005));
            $selDue = $selOwing ? $x['months'][$selOwing[0]]['due'] : 0.0;

            $amounts[$x['id']] = $multi
                ? ($selDue > 0.005 ? self::plainAmount($selDue) : '')
                : $c['suggest'];

            $this->members[$i] = array_merge($x, [
                'month_status' => $c['status'],
                'month_paid' => $c['paid'],
                'outside' => $multi ? !$selEnrolled : $c['outside'],
                'is_enroll_month' => $x['enrolled_ym'] === $ym,
                'payments_before' => count(array_filter($x['pay_yms'], fn ($p) => $p < $ym)),
            ]);
        }

        $this->amounts = $amounts;
    }


    /** 30.0 → "30", 12.5 → "12.50" — what a person would type. */
    private static function plainAmount(float $v): string
    {
        return abs($v - round($v)) < 0.005 ? (string) (int) round($v) : number_format($v, 2, '.', '');
    }
}
