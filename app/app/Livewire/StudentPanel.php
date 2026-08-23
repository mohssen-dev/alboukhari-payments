<?php

namespace App\Livewire;

use App\Models\Student;
use App\Models\StudentMonthlyFeeOverride;
use App\Models\StudentSurcharge;
use App\Models\StudentSuspension;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use App\Support\AuthorizesLivewireWrite;
use Livewire\Component;

class StudentPanel extends Component
{
    use AuthorizesLivewireWrite;
    public ?int $studentId = null;
    public string $tab = 'payments'; // payments | settings | notes | siblings

    // For edit
    public string $name = '';
    public string $phone_primary_raw = '';
    public string $phone_secondary_raw = '';
    public ?float $default_fee_amount = null;
    public string $notes = '';
    public ?string $enrolled_at = null;
    public ?string $withdrawn_at = null;

    // Suspension
    public string $suspend_starts_at = '';
    public string $suspend_ends_at = '';
    public string $suspend_reason = '';

    // Fee Override
    public int $override_month = 1;
    public ?float $override_amount = null;
    public string $override_reason = '';

    // Surcharge
    public int $surcharge_month = 1;
    public ?float $surcharge_amount = null;
    public string $surcharge_reason = '';

    protected $listeners = [
        'open-student-panel' => 'switchStudent',
        'close-student-panel' => 'closeSelf',
        'payment-saved' => '$refresh',
    ];

    public function mount(?int $studentId = null)
    {
        if ($studentId) {
            $this->loadStudent($studentId);
        }
    }

    public function switchStudent(int $studentId)
    {
        $this->loadStudent($studentId);
        $this->tab = 'payments';
    }

    /**
     * Clear panel state → next render outputs empty view → panel disappears.
     * Used by the close-student-panel event so the panel component doesn't rely on a parent.
     */
    public function closeSelf(): void
    {
        $this->reset([
            'studentId', 'tab', 'name', 'phone_primary_raw', 'phone_secondary_raw',
            'default_fee_amount', 'notes', 'enrolled_at', 'withdrawn_at',
            'suspend_starts_at', 'suspend_ends_at', 'suspend_reason',
            'override_month', 'override_amount', 'override_reason',
            'surcharge_month', 'surcharge_amount', 'surcharge_reason',
        ]);
    }

    private function loadStudent(int $id)
    {
        $student = Student::with(['family.students', 'payments', 'markers', 'suspensions', 'surcharges', 'feeOverrides'])->findOrFail($id);
        $this->studentId = $student->id;
        $this->name = $student->name;
        $this->phone_primary_raw = $student->phone_primary_raw ?? '';
        $this->phone_secondary_raw = $student->phone_secondary_raw ?? '';
        $this->default_fee_amount = $student->default_fee_amount ? (float) $student->default_fee_amount : null;
        $this->notes = $student->notes ?? '';
        $this->enrolled_at = $student->enrolled_at?->format('Y-m-d');
        $this->withdrawn_at = $student->withdrawn_at?->format('Y-m-d');
    }

    /**
     * Kept for backward compatibility with anything still calling close().
     *
     * It used to dispatch 'close-student', an event NOTHING listens for (the
     * registered listener is 'close-student-panel'), so calling it did nothing
     * at all. It now performs the close directly — same behaviour as the
     * blade's own close button.
     */
    public function close()
    {
        $this->closeSelf();
    }

    public function saveBasic()
    {
        $this->assertCanWrite();

        // Normalize empty-string date inputs to null BEFORE validating —
        // Laravel treats "" as an invalid date and breaks after_or_equal comparisons.
        $this->enrolled_at = $this->enrolled_at ?: null;
        $this->withdrawn_at = $this->withdrawn_at ?: null;

        $this->validate([
            'enrolled_at' => 'nullable|date',
            'withdrawn_at' => 'nullable|date|after_or_equal:enrolled_at',
        ]);

        $student = Student::findOrFail($this->studentId);
        $student->name = trim($this->name);
        $student->phone_primary_raw = $this->phone_primary_raw ?: null;
        $student->phone_primary_e164 = \App\Support\PhoneNormalizer::normalize($this->phone_primary_raw);
        $student->phone_secondary_raw = $this->phone_secondary_raw ?: null;
        $student->phone_secondary_e164 = \App\Support\PhoneNormalizer::normalize($this->phone_secondary_raw);
        $student->default_fee_amount = $this->default_fee_amount;
        $student->notes = $this->notes ?: null;
        $student->enrolled_at = $this->enrolled_at ?: null;
        $student->withdrawn_at = $this->withdrawn_at ?: null;
        $student->save();

        $this->dispatch('flash', message: __('flash.saved'));
        $this->dispatch('student-updated');
    }

    public function toggleFlag(string $flag)
    {
        $this->assertCanWrite();

        $allowed = ['is_hidden', 'is_blocked_messages', 'is_in_person', 'excluded_from_send_all', 'included_in_send_all', 'allow_sms', 'allow_whatsapp'];
        if (!in_array($flag, $allowed, true)) return;
        $student = Student::findOrFail($this->studentId);
        $student->{$flag} = !$student->{$flag};
        $student->save();
        $this->dispatch('flash', message: __('flash.updated'));
        $this->dispatch('student-updated');
    }

    public function addSuspension()
    {
        $this->assertCanWrite();

        $this->validate([
            'suspend_starts_at' => 'required|date',
            'suspend_ends_at' => 'nullable|date|after_or_equal:suspend_starts_at',
            'suspend_reason' => 'nullable|string|max:255',
        ]);

        StudentSuspension::create([
            'student_id' => $this->studentId,
            'starts_at' => $this->suspend_starts_at,
            'ends_at' => $this->suspend_ends_at ?: null,
            'reason' => $this->suspend_reason ?: null,
        ]);

        $this->suspend_starts_at = '';
        $this->suspend_ends_at = '';
        $this->suspend_reason = '';
        $this->dispatch('flash', message: __('flash.suspension_created'));
        $this->dispatch('student-updated');
    }

    public function removeSuspension(int $id)
    {
        $this->assertCanWrite();

        StudentSuspension::where('id', $id)->where('student_id', $this->studentId)->delete();
        $this->dispatch('flash', message: __('flash.deleted'));
        $this->dispatch('student-updated');
    }

    public function addOverride()
    {
        $this->assertCanWrite();

        $this->validate([
            'override_month' => 'required|integer|min:1|max:12',
            'override_amount' => 'required|numeric|min:0',
            'override_reason' => 'nullable|string|max:255',
        ]);

        $year = (int) date('Y');
        StudentMonthlyFeeOverride::updateOrCreate(
            [
                'student_id' => $this->studentId,
                'period_year' => $year,
                'period_month' => $this->override_month,
            ],
            [
                'amount' => $this->override_amount,
                'reason' => $this->override_reason ?: null,
            ]
        );

        $this->override_amount = null;
        $this->override_reason = '';
        $this->dispatch('flash', message: __('flash.override_added'));
        $this->dispatch('student-updated');
    }

    public function removeOverride(int $id)
    {
        $this->assertCanWrite();

        StudentMonthlyFeeOverride::where('id', $id)->where('student_id', $this->studentId)->delete();
        $this->dispatch('flash', message: __('flash.deleted'));
        $this->dispatch('student-updated');
    }

    public function addSurcharge()
    {
        $this->assertCanWrite();

        $this->validate([
            'surcharge_month' => 'required|integer|min:1|max:12',
            'surcharge_amount' => 'required|numeric|min:0',
            'surcharge_reason' => 'required|string|max:255',
        ]);

        $year = (int) date('Y');
        StudentSurcharge::create([
            'student_id' => $this->studentId,
            'period_year' => $year,
            'period_month' => $this->surcharge_month,
            'amount' => $this->surcharge_amount,
            'reason' => $this->surcharge_reason,
        ]);

        $this->surcharge_amount = null;
        $this->surcharge_reason = '';
        $this->dispatch('flash', message: __('flash.surcharge_added'));
        $this->dispatch('student-updated');
    }

    public function removeSurcharge(int $id)
    {
        $this->assertCanWrite();

        StudentSurcharge::where('id', $id)->where('student_id', $this->studentId)->delete();
        $this->dispatch('flash', message: __('flash.deleted'));
        $this->dispatch('student-updated');
    }

    public function openPayment(int $month)
    {
        $this->dispatch('open-payment-modal', studentId: $this->studentId, year: (int) date('Y'), month: $month);
    }

    public function openFamily()
    {
        $this->dispatch('open-family-modal', studentId: $this->studentId);
    }

    public function openSendMessage()
    {
        $this->dispatch('open-send-message', studentId: $this->studentId);
    }

    public function render()
    {
        // حماية دفاعية: إذا فُقد studentId لأي سبب، اعرض div فارغ بدل الانهيار
        if (!$this->studentId) {
            return view('livewire.student-panel-empty');
        }

        $student = Student::with(['family.students', 'payments', 'markers', 'suspensions', 'surcharges', 'feeOverrides'])->find($this->studentId);
        if (!$student) {
            return view('livewire.student-panel-empty');
        }

        $year = (int) date('Y');
        $months = MonthNames::full();

        // Batch resolvers: resolve() recomputes all 12 months internally, so
        // the old per-month loop did 12x the work (and 3 lazy paths besides).
        $statuses = MonthStatusResolver::resolveAll($student, $year);
        $dueAll   = FeeResolver::dueAllMonths($student, $year);
        $paidAll  = FeeResolver::paidAllMonths($student, $year);
        $nowMonth = (int) date('n');

        $monthsData = [];
        $totalBalance = 0;
        foreach (range(1, 12) as $m) {
            $status = $statuses[$m];
            $due = $dueAll[$m];
            $paid = $paidAll[$m];
            $payments = $student->payments->where('period_year', $year)->where('period_month', $m);
            // Only months due so far count toward the displayed debt —
            // partial advance payments must not inflate it.
            if ($m <= $nowMonth && in_array($status, ['unpaid', 'late', 'partial'])) {
                $totalBalance += max(0, $due - $paid);
            }
            $monthsData[$m] = compact('status', 'due', 'paid', 'payments');
        }

        $siblings = $student->siblings();

        return view('livewire.student-panel', [
            'student' => $student,
            'siblings' => $siblings,
            'months' => $months,
            'monthsData' => $monthsData,
            'totalBalance' => $totalBalance,
            'year' => $year,
        ]);
    }
}
