<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
use Livewire\Attributes\On;
use Livewire\Component;

class PaymentModal extends Component
{
    use AuthorizesLivewireWrite;
    use DispatchesGridRow;

    public bool $isOpen = false;
    public ?int $studentId = null;
    public ?int $year = null;
    public ?int $month = null;

    public ?float $amount = null;
    public string $method = 'cash';
    public string $note = '';
    public string $paid_at = '';

    public ?int $editingPaymentId = null;
    public array $existingPayments = [];

    public string $studentName = '';
    public float $dueAmount = 0;
    public float $paidSoFar = 0;

    /** The student's enrolment date (Y-m-d) — set from this modal with setEnrollmentMonth(). */
    public ?string $enrolledAt = null;
    /** Recorded payments for months before the month on screen (kept if enrolment moves past them). */
    public int $paymentsBefore = 0;

    public function mount(?int $initialStudentId = null, ?int $initialYear = null, ?int $initialMonth = null): void
    {
        if ($initialStudentId && $initialYear && $initialMonth) {
            $this->open($initialStudentId, $initialYear, $initialMonth);
        }
    }

    #[On('open-payment-modal')]
    public function open(int $studentId, int $year, int $month): void
    {
        $student = Student::findOrFail($studentId);
        $this->studentId = $studentId;
        $this->year = $year;
        $this->month = $month;
        $this->studentName = $student->name;
        $this->enrolledAt = $student->enrolled_at?->format('Y-m-d');
        $this->paymentsBefore = $student->payments()
            ->whereIn('method', ['cash', 'bank'])
            ->whereRaw('(period_year * 12 + period_month) < ?', [$year * 12 + $month])
            ->count();

        $this->dueAmount = FeeResolver::dueAmount($student, $year, $month);
        $this->paidSoFar = FeeResolver::paidAmount($student, $year, $month);

        $remaining = $this->dueAmount - $this->paidSoFar;
        $this->amount = $remaining > 0 ? $remaining : $this->dueAmount;
        $this->method = 'cash';
        $this->note = '';
        $this->paid_at = now()->format('Y-m-d');
        $this->editingPaymentId = null;

        $this->existingPayments = $student->payments()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->orderBy('paid_at')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'method_label' => $p->methodLabel(),
                'method_icon' => $p->methodIcon(),
                'paid_at' => $p->paid_at->format('Y-m-d'),
                'note' => $p->note,
            ])->toArray();

        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->resetFormState();
        $this->dispatch('close-modal');
    }

    private function resetFormState(): void
    {
        $this->reset(['isOpen', 'studentId', 'year', 'month', 'amount', 'method', 'note', 'paid_at', 'editingPaymentId', 'existingPayments', 'studentName', 'dueAmount', 'paidSoFar', 'enrolledAt', 'paymentsBefore']);
        $this->method = 'cash';
    }

    public function editExisting(int $paymentId): void
    {
        // The list shown may be stale (payment deleted from another tab/user);
        // findOrFail would 404-crash the whole Livewire request.
        $p = Payment::find($paymentId);
        if (!$p) {
            $this->dispatch('toast', message: __('flash.deleted'), type: 'error');
            $this->open($this->studentId, $this->year, $this->month); // refresh list
            return;
        }
        $this->editingPaymentId = $paymentId;
        $this->amount = (float) $p->amount;
        $this->method = $p->method === 'legacy_zero' ? 'bank' : $p->method;
        $this->note = $p->note ?: '';
        $this->paid_at = $p->paid_at->format('Y-m-d');
    }

    public function deletePayment(int $paymentId): void
    {
        $this->assertCanWrite();

        try {
            $p = Payment::findOrFail($paymentId);
            $p->delete();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('payment-saved', studentId: $this->studentId);
        $this->dispatchGridRow($this->studentId, $this->year, 'year');
        $this->dispatch('toast', message: __('flash.payment_deleted'), type: 'success');
        $this->open($this->studentId, $this->year, $this->month);
    }

    public function setMethod(string $method): void
    {
        if (in_array($method, ['cash', 'bank'], true)) {
            $this->method = $method;
        }
    }

    public function save(bool $next = false): void
    {
        $this->assertCanWrite();

        $this->validate([
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,bank',
            'paid_at' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        // Block payments for months outside the student's enrollment window.
        // Legacy imports bypass this check (they set method='legacy_zero' / 'bank' directly).
        $student = Student::find($this->studentId);
        if ($student && FeeResolver::isOutsideEnrollment($student, $this->year, $this->month)) {
            $this->dispatch('toast', message: __('status.not_enrolled') . ' — ' . $student->name, type: 'error');
            $this->close();
            return;
        }

        try {
            if ($this->editingPaymentId) {
                // Scoped to the open student: editingPaymentId is plain client
                // state, so it must not be able to point at another child's row.
                $p = Payment::where('student_id', $this->studentId)->findOrFail($this->editingPaymentId);
                // Editing a legacy_zero row with amount still 0 must keep its
                // method — converting it to a 0.00 'bank' payment would flip
                // the month from settled (legacy_zero) to unpaid/late.
                $method = ($p->method === 'legacy_zero' && (float) $this->amount == 0.0)
                    ? 'legacy_zero'
                    : $this->method;
                $p->update([
                    'amount' => $this->amount,
                    'method' => $method,
                    'note' => $this->note ?: null,
                    'paid_at' => $this->paid_at,
                ]);
            } else {
                Payment::create([
                    'student_id' => $this->studentId,
                    'period_year' => $this->year,
                    'period_month' => $this->month,
                    'amount' => $this->amount,
                    'method' => $this->method,
                    'note' => $this->note ?: null,
                    'paid_at' => $this->paid_at,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        $this->dispatch('payment-saved', studentId: $this->studentId);
        $this->dispatchGridRow($this->studentId, $this->year, 'year');
        $this->dispatch('toast', message: __('flash.payment_saved'), type: 'success');

        if ($next) {
            // The same student's next month (December → January of next
            // year), so several months are paid from one window. It used to
            // jump to the next student. The date and method carry over — a
            // parent paying two months pays them the same day, the same way.
            [$studentId, $method, $paidAt] = [$this->studentId, $this->method, $this->paid_at];
            [$year, $month] = self::nextMonth($this->year, $this->month);
            $this->resetFormState();
            $this->open($studentId, $year, $month);
            $this->method = $method;
            $this->paid_at = $paidAt;
        } else {
            $this->close();
        }
    }

    /**
     * Make the month on screen the student's first billed month: every month
     * before it stops being owed (FeeResolver / MonthStatusResolver treat it
     * as not enrolled). Recorded payments are never touched.
     */
    public function setEnrollmentMonth(): void
    {
        $this->assertCanWrite();

        $student = Student::find($this->studentId);
        if (!$student || !$this->year || !$this->month) {
            return;
        }

        $startYm = $this->year * 12 + $this->month;
        if ($student->withdrawn_at && ($student->withdrawn_at->year * 12 + $student->withdrawn_at->month) <= $startYm) {
            $this->dispatch('toast', message: __('enroll.after_withdrawal'), type: 'error');
            return;
        }

        $student->update(['enrolled_at' => sprintf('%04d-%02d-01', $this->year, $this->month)]);
        $this->afterEnrollmentChange($student, __('enroll.saved', ['month' => $this->monthLabel()]));
    }

    public function clearEnrollment(): void
    {
        $this->assertCanWrite();

        $student = Student::find($this->studentId);
        if (!$student) {
            return;
        }

        $student->update(['enrolled_at' => null]);
        $this->afterEnrollmentChange($student, __('enroll.cleared'));
    }

    private function afterEnrollmentChange(Student $student, string $message): void
    {
        // Enrolment shifts which months are owed in every year → 'student' scope.
        $this->dispatchGridRow($student->id, $this->year, 'student');
        $this->dispatch('student-updated', studentId: $student->id);
        $this->dispatch('toast', message: $message, type: 'success');
        $this->open($student->id, $this->year, $this->month);
    }

    /** @return array{0:int,1:int} [year, month] of the month after the given one */
    public static function nextMonth(int $year, int $month): array
    {
        return $month >= 12 ? [$year + 1, 1] : [$year, $month + 1];
    }

    private function monthLabel(): string
    {
        return (MonthNames::full()[$this->month] ?? '') . ' ' . $this->year;
    }

    public function render()
    {
        $monthName = $this->month ? (MonthNames::full()[$this->month] ?? '') : '';

        $enrollLabel = null;
        $enrollYm = null;
        if ($this->enrolledAt) {
            $d = \Carbon\Carbon::parse($this->enrolledAt);
            $enrollLabel = (MonthNames::full()[$d->month] ?? '') . ' ' . $d->year;
            $enrollYm = $d->year * 12 + $d->month;
        }
        $ym = ($this->year && $this->month) ? $this->year * 12 + $this->month : null;

        [$nextYear, $nextMonth] = $this->month ? self::nextMonth((int) $this->year, (int) $this->month) : [null, null];

        return view('livewire.payment-modal', [
            'monthName' => $monthName,
            'nextMonthLabel' => $nextMonth ? (MonthNames::full()[$nextMonth] ?? '') . ($nextYear !== (int) $this->year ? ' ' . $nextYear : '') : '',
            'enrollLabel' => $enrollLabel,
            'isEnrollMonth' => $ym !== null && $enrollYm === $ym,
            'beforeEnroll' => $ym !== null && $enrollYm !== null && $ym < $enrollYm,
            'canWrite' => (bool) auth()->user()?->canWrite(),
        ]);
    }
}
