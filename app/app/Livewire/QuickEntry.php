<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use Livewire\Component;

class QuickEntry extends Component
{
    use AuthorizesLivewireWrite;

    public string $search = '';
    public ?int $selectedStudentId = null;
    public int $year;
    public int $month;

    public ?float $amount = null;
    public string $method = 'cash';
    public string $note = '';

    public int $sessionCount = 0;
    public float $sessionTotal = 0;
    public array $sessionLog = [];

    public function mount()
    {
        $this->year = (int) date('Y');
        $this->month = (int) date('n');
    }

    public function selectStudent(int $id)
    {
        $student = Student::findOrFail($id);
        $this->selectedStudentId = $id;
        $remaining = FeeResolver::balance($student, $this->year, $this->month);
        // Fully-paid month → suggest 0, not the full fee again (double-charge trap).
        $this->amount = $remaining > 0.005 ? round($remaining, 2) : 0.0;
        $this->method = 'cash';
        $this->note = '';
    }

    public function setMethod(string $m): void
    {
        if (in_array($m, ['cash', 'bank'], true)) {
            $this->method = $m;
        }
    }

    public function save(): void
    {
        $this->assertCanWrite();

        $this->validate([
            'selectedStudentId' => 'required|exists:students,id',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,bank',
        ]);

        // Same enrollment-window guard as PaymentModal::save.
        $student = Student::find($this->selectedStudentId);
        if ($student && FeeResolver::isOutsideEnrollment($student, $this->year, $this->month)) {
            $this->dispatch('flash', message: __('status.not_enrolled') . ' — ' . $student->name);
            return;
        }

        $payment = Payment::create([
            'student_id' => $this->selectedStudentId,
            'period_year' => $this->year,
            'period_month' => $this->month,
            'amount' => $this->amount,
            'paid_at' => now()->format('Y-m-d'),
            'method' => $this->method,
            'note' => $this->note ?: null,
        ]);

        $student = Student::find($this->selectedStudentId);
        $this->sessionCount++;
        $this->sessionTotal += (float) $this->amount;
        array_unshift($this->sessionLog, [
            'student' => $student->name,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'icon' => $payment->methodIcon(),
            'time' => now()->format('H:i:s'),
        ]);
        $this->sessionLog = array_slice($this->sessionLog, 0, 15);

        // Reset for next entry
        $this->reset(['search', 'selectedStudentId', 'amount', 'method', 'note']);
        $this->method = 'cash';

        $this->dispatch('flash', message: __('flash.quick_saved', ['amount' => (string) $payment->amount]));
        $this->dispatch('focus-search');
    }

    public function render()
    {
        $candidates = [];
        if (strlen(trim($this->search)) >= 2) {
            $s = trim($this->search);
            $candidates = Student::query()
                ->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%$s%")
                      ->orWhere('phone_primary_e164', 'like', "%$s%")
                      ->orWhere('external_id', $s);
                })
                ->where('is_hidden', false)
                ->limit(8)
                ->get();
        }

        $months = MonthNames::full();

        $selectedStudent = $this->selectedStudentId ? Student::find($this->selectedStudentId) : null;
        $dueAmount = $selectedStudent ? FeeResolver::dueAmount($selectedStudent, $this->year, $this->month) : 0;
        $paidSoFar = $selectedStudent ? FeeResolver::paidAmount($selectedStudent, $this->year, $this->month) : 0;

        return view('livewire.quick-entry', [
            'candidates' => $candidates,
            'months' => $months,
            'selectedStudent' => $selectedStudent,
            'dueAmount' => $dueAmount,
            'paidSoFar' => $paidSoFar,
        ])->layout('layouts.app');
    }
}
