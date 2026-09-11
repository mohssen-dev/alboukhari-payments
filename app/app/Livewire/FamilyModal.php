<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
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
     * Pay-the-family form: one amount per child for one month, ready the
     * moment the window opens. It used to take a click on 💶 per child, each
     * closing this window and opening the single-payment modal.
     */
    #[Locked] public int $year = 0;
    public int $month = 1;
    public string $method = 'cash';
    public string $paid_at = '';
    /** @var array<int, string> student id => amount as typed */
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

    /** Another month picked: recompute what each child still owes and prefill it. */
    public function updatedMonth(): void
    {
        $this->month = max(1, min(12, (int) $this->month));
        $this->loadMembers();
    }

    /** Record every filled-in amount as a payment for the chosen month, in one go. */
    public function saveAll(): void
    {
        $this->assertCanWrite();

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

        $toSave = [];
        foreach ($this->amounts as $id => $raw) {
            $student = $payable->get((int) $id);
            if (!$student || $raw === null || $raw === '') continue;

            $amount = round((float) $raw, 2);
            if ($amount <= 0) continue;
            if (FeeResolver::isOutsideEnrollment($student, $this->year, $this->month)) continue;

            $toSave[$student->id] = $amount;
        }

        if (!$toSave) {
            $this->dispatch('toast', message: __('family.nothing_to_save'), type: 'error');
            return;
        }

        try {
            DB::transaction(function () use ($toSave) {
                foreach ($toSave as $id => $amount) {
                    Payment::create([
                        'student_id' => $id,
                        'period_year' => $this->year,
                        'period_month' => $this->month,
                        'amount' => $amount,
                        'method' => $this->method,
                        'paid_at' => $this->paid_at,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        foreach (array_keys($toSave) as $id) {
            $this->dispatch('payment-saved', studentId: $id);
            $this->dispatchGridRow($id, $this->year);
        }
        $this->dispatch('toast', type: 'success', message: __('family.saved', [
            'count' => count($toSave),
            'total' => number_format(array_sum($toSave), 2),
        ]));

        $this->close();
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
            'canWrite' => (bool) auth()->user()?->canWrite(),
        ]);
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

        $currentMonth = (int) date('n');
        $amounts = [];

        $this->members = $members->map(function (Student $s) use ($currentMonth, &$amounts) {
            // Status-driven so settled-but-unpaid months (exempt override 0,
            // not-enrolled) don't inflate the family balance.
            $statuses = MonthStatusResolver::resolveAll($s, $this->year);
            $dueAll   = FeeResolver::dueAllMonths($s, $this->year);
            $paidAll  = FeeResolver::paidAllMonths($s, $this->year);

            $balance = 0.0;
            $monthsPaid = 0;
            foreach (range(1, $currentMonth) as $m) {
                $st = $statuses[$m];
                if ($st === 'paid' || $st === 'paid_advance' || $st === 'legacy_zero') {
                    $monthsPaid++;
                } elseif (in_array($st, ['unpaid', 'late', 'partial'], true)) {
                    $balance += max(0, $dueAll[$m] - $paidAll[$m]);
                }
            }

            // The month being paid for.
            $m = $this->month;
            $outside = FeeResolver::isOutsideEnrollment($s, $this->year, $m);
            $remaining = round(max(0, $dueAll[$m] - $paidAll[$m]), 2);
            $amounts[$s->id] = (!$outside && $remaining > 0.005) ? self::plainAmount($remaining) : '';

            return [
                'id' => $s->id,
                'external_id' => $s->external_id,
                'name' => $s->name,
                'phone' => $s->phone_primary_e164 ?: '',
                'balance' => round($balance, 2),
                'months_paid' => $monthsPaid,
                'months_total' => $currentMonth,
                'is_self' => $s->id === $this->studentId,
                'badge' => $s->statusBadge(),
                'skip_reason' => $s->skipReason(),
                'is_hidden' => (bool) $s->is_hidden,
                'is_blocked' => (bool) $s->is_blocked_messages,
                'is_in_person' => (bool) $s->is_in_person,
                'month_status' => $statuses[$m],
                'month_label' => MonthStatusResolver::label($statuses[$m]),
                'month_due' => round($dueAll[$m], 2),
                'month_paid' => round($paidAll[$m], 2),
                'month_remaining' => $remaining,
                'outside' => $outside,
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
