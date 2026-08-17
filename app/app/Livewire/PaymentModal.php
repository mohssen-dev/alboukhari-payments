<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use Livewire\Attributes\On;
use Livewire\Component;

class PaymentModal extends Component
{
    use AuthorizesLivewireWrite;
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
        $this->reset(['isOpen', 'studentId', 'year', 'month', 'amount', 'method', 'note', 'paid_at', 'editingPaymentId', 'existingPayments', 'studentName', 'dueAmount', 'paidSoFar']);
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
                $p = Payment::findOrFail($this->editingPaymentId);
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
        $this->dispatch('toast', message: __('flash.payment_saved'), type: 'success');

        if ($next) {
            $nextStudent = Student::where('id', '>', $this->studentId)
                ->where('is_hidden', false)
                ->orderBy('id')
                ->first();
            if ($nextStudent) {
                $year = $this->year;
                $month = $this->month;
                $this->resetFormState();
                $this->open($nextStudent->id, $year, $month);
            } else {
                $this->close();
            }
        } else {
            $this->close();
        }
    }

    public function render()
    {
        $monthName = $this->month ? (MonthNames::full()[$this->month] ?? '') : '';
        return view('livewire.payment-modal', compact('monthName'));
    }
}
