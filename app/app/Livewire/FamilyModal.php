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
use Livewire\Attributes\On;
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
     */
    public int $year = 0;
    public int $month = 1;
    public string $method = 'cash';
    public string $paid_at = '';
    /** @var array<int, string> student id => the month's total as typed */
    public array $amounts = [];

    public function mount(?int $initialStudentId = null): void
    {
        if ($initialStudentId) {
            $this->open($initialStudentId);
        }
    }

    #[On('open-family-modal')]
    public function open(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->year = (int) date('Y');
        $this->month = (int) date('n');
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
        $this->loadMembers();
    }

    public function updatedMonth(): void
    {
        $this->month = max(1, min(12, (int) $this->month));
        $this->loadMembers();
    }

    /**
     * Bring each child's recorded total for the chosen month to the amount
     * typed: more → the difference is a new payment (method and date from the
     * form); less → recorded payments are reduced, newest first, and removed
     * when they reach zero.
     */
    public function saveAll(): void
    {
        $this->assertCanWrite();

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
        $this->dispatch('toast', type: 'success', message: __('family.saved_changes', [
            'added' => number_format($added, 2),
            'removed' => number_format($removed, 2),
        ]));

        // Stay open: the table now shows what was recorded.
        $this->loadMembers();
    }

    /** The chosen month becomes this child's first billed month (earlier months stop being owed). */
    public function setEnrollmentMonth(int $studentId): void
    {
        $this->assertCanWrite();

        $student = $this->payableStudents()->get($studentId);
        if (!$student) {
            return;
        }

        $startYm = $this->year * 12 + $this->month;
        if ($student->withdrawn_at && ($student->withdrawn_at->year * 12 + $student->withdrawn_at->month) <= $startYm) {
            $this->dispatch('toast', message: __('enroll.after_withdrawal'), type: 'error');
            return;
        }

        $student->update(['enrolled_at' => sprintf('%04d-%02d-01', $this->year, $this->month)]);
        $this->afterEnrollmentChange($student->id, __('enroll.saved', [
            'month' => (MonthNames::full()[$this->month] ?? '') . ' ' . $this->year,
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
        $this->loadMembers();
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'studentId', 'familyId', 'familyTitle', 'guardianPhone', 'members', 'amounts', 'year', 'month', 'method', 'paid_at']);
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
        $amounts = [];

        $this->members = $members->map(function (Student $s) use ($nowYm, &$amounts) {
            $statuses = MonthStatusResolver::resolveAll($s, $this->year);
            $dueAll   = FeeResolver::dueAllMonths($s, $this->year);
            $paidAll  = FeeResolver::paidAllMonths($s, $this->year);

            $months = [];
            $balance = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $st = $statuses[$m];
                $months[$m] = [
                    'status' => $st,
                    'paid' => round($paidAll[$m], 2),
                    'due' => round($dueAll[$m], 2),
                    'class' => GridRow::cellClass($st),
                    'display' => GridRow::cellDisplay($st, $paidAll[$m]),
                    'label' => MonthStatusResolver::label($st),
                ];
                $isDueByNow = (($this->year * 12) + $m) <= $nowYm;
                if ($isDueByNow && in_array($st, ['unpaid', 'late', 'partial'], true)) {
                    $balance += max(0, $dueAll[$m] - $paidAll[$m]);
                }
            }

            // The month being paid for: show what is recorded, or suggest
            // what is owed when nothing is recorded yet.
            $m = $this->month;
            $outside = FeeResolver::isOutsideEnrollment($s, $this->year, $m);
            $paid = round($paidAll[$m], 2);
            $remaining = round(max(0, $dueAll[$m] - $paidAll[$m]), 2);
            $amounts[$s->id] = match (true) {
                $outside => '',
                $paid > 0.005 => self::plainAmount($paid),
                $remaining > 0.005 => self::plainAmount($remaining),
                default => '',
            };

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
                'month_status' => $statuses[$m],
                'month_label' => MonthStatusResolver::label($statuses[$m]),
                'month_due' => round($dueAll[$m], 2),
                'month_paid' => $paid,
                'month_due_plain' => self::plainAmount($dueAll[$m]),
                'outside' => $outside,
                'enrolled_label' => $s->enrolled_at
                    ? (MonthNames::full()[$s->enrolled_at->month] ?? '') . ' ' . $s->enrolled_at->year
                    : null,
                'is_enroll_month' => $s->enrolled_at !== null
                    && ($s->enrolled_at->year * 12 + $s->enrolled_at->month) === ($this->year * 12 + $m),
                'payments_before' => $s->payments
                    ->filter(fn ($p) => in_array($p->method, ['cash', 'bank'], true)
                        && ($p->period_year * 12 + $p->period_month) < ($this->year * 12 + $m))
                    ->count(),
            ];
        })->values()->toArray();

        $this->amounts = $amounts;
    }

    /** 30.0 → "30", 12.5 → "12.50" — what a person would type. */
    private static function plainAmount(float $v): string
    {
        return abs($v - round($v)) < 0.005 ? (string) (int) round($v) : number_format($v, 2, '.', '');
    }
}
