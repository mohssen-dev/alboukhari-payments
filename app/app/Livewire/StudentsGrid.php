<?php

namespace App\Livewire;

use App\Models\Student;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
use App\Support\GridRow;
use Livewire\Component;
use Livewire\WithPagination;

class StudentsGrid extends Component
{
    use AuthorizesLivewireWrite;
    use DispatchesGridRow;
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
     * Modals + student panel are mounted persistently in layouts/app.blade.php.
     * The grid opens them straight from the browser (abOpenPayment /
     * Livewire.dispatch) — no round-trip through this component; the open*()
     * methods below stay as server-side entry points.
     *
     * A change to one student comes back as that single re-rendered row
     * ('grid-row-updated', see App\Support\GridRow) — never as a full grid
     * re-render, which is what made every payment save lag.
     */
    public function mount(bool $focus = false)
    {
        $this->year = (int) date('Y');
        $this->focus = $focus;
    }

    /** A new student is a new row, not a patch — re-render once. */
    protected $listeners = ['student-created' => '$refresh'];

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

    public function toggleFlag(int $studentId, string $flag)
    {
        $this->assertCanWrite();

        $allowed = ['is_hidden', 'is_blocked_messages', 'is_in_person', 'excluded_from_send_all', 'included_in_send_all', 'allow_sms'];
        if (!in_array($flag, $allowed, true)) return;

        $student = Student::findOrFail($studentId);
        $student->{$flag} = !$student->{$flag};
        $student->save();

        $this->dispatch('toast', message: __('common.flash_saved'));

        // Under a status filter the row may have to leave the page, so let the
        // grid re-render. Otherwise patching the one row is enough.
        if ($this->filterStatus === 'all') {
            $this->dispatchGridRow($studentId, $this->year);
            $this->skipRender();
        }
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
        $query = Student::query()->with(GridRow::relations($this->year));

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

        $built = [];
        foreach ($students as $student) {
            $built[$student->id] = GridRow::build($student, $this->year);
        }

        return view('livewire.students-grid', [
            'students' => $students,
            'months' => MonthNames::full(),
            'built' => $built,
            'totalStudents' => Student::count(),
        ])->layout('layouts.app');
    }
}
