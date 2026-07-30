<?php

namespace App\Livewire;

use App\Models\Student;
use App\Services\FeeResolver;
use Livewire\Attributes\On;
use Livewire\Component;

class FamilyModal extends Component
{
    public bool $isOpen = false;
    public ?int $studentId = null;
    public ?int $familyId = null;
    public string $familyTitle = '';
    public string $guardianPhone = '';
    public array $members = [];

    public function mount(?int $initialStudentId = null): void
    {
        if ($initialStudentId) {
            $this->open($initialStudentId);
        }
    }

    #[On('open-family-modal')]
    public function open(int $studentId): void
    {
        try {
            $student = Student::with([
                'family.students.payments',
                'family.students.feeOverrides',
                'family.students.surcharges',
                'family.students.markers',
                'family.students.suspensions',
            ])->findOrFail($studentId);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('flash.send_error') . ' ' . $e->getMessage(), type: 'error');
            return;
        }

        $this->studentId = $studentId;
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

        $year = (int) date('Y');
        $currentMonth = (int) date('n');

        $this->members = $members->map(function (Student $s) use ($year, $currentMonth) {
            $balance = 0.0;
            $monthsPaid = 0;
            foreach (range(1, $currentMonth) as $m) {
                $due = FeeResolver::dueAmount($s, $year, $m);
                $paid = FeeResolver::paidAmount($s, $year, $m);
                if ($paid >= $due && $due > 0) {
                    $monthsPaid++;
                }
                if ($due > $paid) {
                    $balance += ($due - $paid);
                }
            }
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
            ];
        })->values()->toArray();

        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'studentId', 'familyId', 'familyTitle', 'guardianPhone', 'members']);
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
        return view('livewire.family-modal');
    }
}
