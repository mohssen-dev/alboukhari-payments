<?php

namespace App\Livewire;

use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use App\Support\AuthorizesLivewireWrite;
use Livewire\Component;
use Livewire\WithPagination;

class StudentsGrid extends Component
{
    use AuthorizesLivewireWrite;
    use WithPagination;

    public function paginationView(): string { return 'pagination::custom'; }
    public function paginationSimpleView(): string { return 'pagination::custom'; }

    public string $filterStatus = 'all';
    public int $year;
    public int $perPage = 100;

    /** When true, hides the KPI/actions chrome — pure grid + filters only. */
    public bool $focus = false;

    protected $queryString = ['filterStatus', 'year'];

    /**
     * Modals + student panel are mounted persistently in layouts/app.blade.php
     * and communicate via broadcast events. Actions here just fire events —
     * the grid itself does NOT re-render, keeping interactions snappy.
     */
    public function mount(bool $focus = false)
    {
        $this->year = (int) date('Y');
        $this->focus = $focus;
    }

    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingYear() { $this->resetPage(); }
    public function updatingPerPage() { $this->resetPage(); }

    public function openStudent(int $studentId)
    {
        $this->dispatch('open-student-panel', studentId: $studentId);
        $this->skipRender(); // No grid state changed — save the expensive re-render.
    }

    public function openPayment(int $studentId, int $month): void
    {
        $this->dispatch('open-payment-modal', studentId: $studentId, year: $this->year, month: $month);
        $this->skipRender();
    }

    public function openFamily(int $studentId): void
    {
        $this->dispatch('open-family-modal', studentId: $studentId);
        $this->skipRender();
    }

    public function openSendMessage(int $studentId): void
    {
        $this->dispatch('open-send-message', studentId: $studentId);
        $this->skipRender();
    }

    protected $listeners = [
        'payment-saved' => '$refresh',
        'student-updated' => '$refresh',
    ];

    public function toggleFlag(int $studentId, string $flag)
    {
        $this->assertCanWrite();

        $allowed = ['is_hidden', 'is_blocked_messages', 'is_in_person', 'excluded_from_send_all', 'included_in_send_all', 'allow_sms'];
        if (!in_array($flag, $allowed, true)) return;

        $student = Student::findOrFail($studentId);
        $student->{$flag} = !$student->{$flag};
        $student->save();

        $this->dispatch('toast', message: __('common.flash_saved'));
    }

    public function bulkAction(array $ids, string $flag, bool $value)
    {
        $this->assertCanWrite();

        $allowed = ['is_hidden', 'is_blocked_messages', 'is_in_person', 'excluded_from_send_all'];
        if (!in_array($flag, $allowed, true)) return;
        Student::whereIn('id', $ids)->update([$flag => $value]);
        $this->dispatch('toast', message: count($ids) . ' ✓');
    }

    public function render()
    {
        // Year-scoped relations: the grid only renders $this->year, so loading
        // other years' rows just inflates hydration cost every render.
        // (suspensions stay unscoped — activeSuspension() needs current state.)
        $yr = $this->year;
        $query = Student::query()
            ->with([
                'family:id,guardian_name,is_blocked_messages',
                'family.students:id,family_id,name',
                'payments' => fn ($q) => $q->where('period_year', $yr),
                'markers' => fn ($q) => $q->where('period_year', $yr),
                'surcharges' => fn ($q) => $q->where('period_year', $yr),
                'feeOverrides' => fn ($q) => $q->where('period_year', $yr),
                'suspensions',
            ]);

        match ($this->filterStatus) {
            'hidden' => $query->where('is_hidden', true),
            'blocked' => $query->where('is_blocked_messages', true),
            'in_person' => $query->where('is_in_person', true),
            'suspended' => $query->whereHas('suspensions', function ($q) {
                $q->where('starts_at', '<=', now())
                  ->where(function ($qq) {
                      $qq->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                  });
            }),
            'visible' => $query->where('is_hidden', false),
            default => null,
        };

        $students = $query->orderBy('id')->paginate($this->perPage);

        $months = MonthNames::full();

        // Balance should mean "owed to date": a partial ADVANCE payment for a
        // future month must not increase the debt figure.
        $nowYm = ((int) date('Y') * 12) + (int) date('n');

        $monthData = [];
        $rowsJson = [];
        foreach ($students as $student) {
            $monthData[$student->id] = [];
            $siblingsCount = $student->family_id ? max(0, $student->family->students->count() - 1) : 0;
            $totalBalance = 0;

            // Batch-compute all 12 months in one pass (avoids 12 * 3 = 36 redundant filter loops per student).
            $statuses = MonthStatusResolver::resolveAll($student, $this->year);
            $paidAll  = FeeResolver::paidAllMonths($student, $this->year);
            $dueAll   = FeeResolver::dueAllMonths($student, $this->year);

            // Pre-bucket the year's cash/bank payments by month for the method icon lookup.
            $lastMethodByMonth = [];
            foreach ($student->payments as $p) {
                if ($p->period_year !== $this->year) continue;
                if ($p->method !== 'cash' && $p->method !== 'bank') continue;
                $m = $p->period_month;
                $existing = $lastMethodByMonth[$m] ?? null;
                if (!$existing || $p->paid_at > $existing->paid_at) {
                    $lastMethodByMonth[$m] = $p;
                }
            }

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
                $monthData[$student->id][$m] = compact('status', 'paid', 'due', 'methodIcon');
                $isFutureMonth = (($this->year * 12) + $m) > $nowYm;
                if (!$isFutureMonth && ($status === 'unpaid' || $status === 'late' || $status === 'partial')) {
                    $totalBalance += max(0, $due - $paid);
                }
            }

            // Build search-haystack for client-side JS
            $haystack = strtolower(implode(' ', array_filter([
                $student->name,
                $student->phone_primary_raw,
                $student->phone_primary_e164,
                $student->external_id,
                (string) $student->id,
            ])));

            $rowsJson[$student->id] = [
                'id' => $student->id,
                'extId' => $student->external_id,
                'name' => $student->name,
                'phone' => $student->phone_primary_e164 ?: '',
                'siblings' => $siblingsCount,
                'balance' => round($totalBalance, 2),
                'isHidden' => (bool) $student->is_hidden,
                'isBlocked' => (bool) $student->is_blocked_messages,
                'isInPerson' => (bool) $student->is_in_person,
                'excludedSendAll' => (bool) $student->excluded_from_send_all,
                'badge' => $student->statusBadge(),
                'skipReason' => $student->skipReason(),
                'haystack' => $haystack,
            ];
        }

        return view('livewire.students-grid', [
            'students' => $students,
            'months' => $months,
            'monthData' => $monthData,
            'totalStudents' => Student::count(),
            'rowsJson' => $rowsJson,
        ])->layout('layouts.app');
    }
}
