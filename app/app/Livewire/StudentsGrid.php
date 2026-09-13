<?php

namespace App\Livewire;

use App\Models\Student;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
use App\Support\GridRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class StudentsGrid extends Component
{
    use AuthorizesLivewireWrite;
    use DispatchesGridRow;
    use WithPagination;

    public function paginationView(): string { return 'pagination::custom'; }
    public function paginationSimpleView(): string { return 'pagination::custom'; }

    /**
     * The filter bar's choices (year, rows per page, state, client filter)
     * outlive a reload: they are kept in this browser's cookie for a year —
     * not in the session, which ends after two idle hours, and not in the
     * URL, where mount() used to overwrite ?year= with the current year.
     * The main page and focus mode share it.
     */
    public const VIEW_COOKIE = 'grid_view';
    public const PER_PAGE = [50, 100, 200, 500];
    public const STATUSES = ['all', 'visible', 'hidden', 'blocked', 'in_person', 'suspended', 'deleted'];
    public const CLIENT_FILTERS = ['all', 'overdue', 'paid_full', 'with_siblings'];

    public string $filterStatus = 'all';
    public int $year;
    public int $perPage = 100;

    /** Filtered in the browser (Alpine); kept here only so a reload restores it. */
    public string $clientFilter = 'all';

    /** When true, hides the KPI/actions chrome — pure grid + filters only. */
    public bool $focus = false;

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
        $this->restoreView();
    }

    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingYear() { $this->resetPage(); }
    public function updatingPerPage() { $this->resetPage(); }

    public function updated(string $property): void
    {
        if (in_array($property, ['year', 'perPage', 'filterStatus'], true)) {
            $this->sanitizeView();
            $this->rememberView();
        }
    }

    /** The client filter changed in the browser — remember it, nothing to re-render. */
    public function rememberClientFilter(string $value): void
    {
        $this->clientFilter = in_array($value, self::CLIENT_FILTERS, true) ? $value : 'all';
        $this->rememberView();
        $this->skipRender();
    }

    /** Bring a deleted student back into the lists, with the dates it had before the delete. */
    public function restoreStudent(int $studentId): void
    {
        $this->assertAdmin();

        $student = Student::onlyTrashed()->find($studentId);
        if (!$student) {
            return;
        }
        DeleteRecord::restore($student);

        $this->dispatch('toast', type: 'success', message: __('delete.restored', ['name' => $student->name]));
        $this->onStudentsDeleted(); // the row left this list — keep the page in range
    }

    /** Rows left the list (DeleteRecord) — re-render; rare enough not to patch. */
    #[On('students-deleted')]
    public function onStudentsDeleted(): void
    {
        // Deleting the last row(s) of the last page would leave an empty page.
        $lastPage = max(1, (int) ceil($this->filteredQuery()->count() / $this->perPage));
        if ($this->getPage() > $lastPage) {
            $this->setPage($lastPage);
        }
    }

    /**
     * A new student is a new row, not a patch: re-render on the page that
     * holds it (rows are ordered by id, so usually the last one) and let the
     * browser scroll to it — on page 1 it was simply never seen.
     */
    #[On('student-created')]
    public function showCreatedStudent(?int $studentId = null): void
    {
        if (!$studentId || !$this->filteredQuery()->whereKey($studentId)->exists()) {
            return;
        }

        $before = $this->filteredQuery()->where('id', '<', $studentId)->count();
        $this->setPage(intdiv($before, $this->perPage) + 1);
        $this->dispatch('grid-show-row', id: $studentId);
    }

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
        $this->sanitizeView();

        $students = $this->filteredQuery()
            ->with(GridRow::relations($this->year))
            ->orderBy('id')
            ->paginate($this->perPage);

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

    private function filteredQuery(): Builder
    {
        $query = Student::query();

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
            // Deleted students keep their payment record — listed read-only here.
            'deleted' => $query->onlyTrashed(),
            default => null,
        };

        return $query;
    }

    /** The years the year picker offers. */
    public static function yearRange(): array
    {
        return range((int) date('Y') + 1, 2020);
    }

    private function restoreView(): void
    {
        $saved = json_decode((string) request()->cookie(self::VIEW_COOKIE), true);
        if (!is_array($saved)) {
            return;
        }

        if (is_int($saved['year'] ?? null)) $this->year = $saved['year'];
        if (is_int($saved['perPage'] ?? null)) $this->perPage = $saved['perPage'];
        if (is_string($saved['filterStatus'] ?? null)) $this->filterStatus = $saved['filterStatus'];
        if (is_string($saved['clientFilter'] ?? null)) $this->clientFilter = $saved['clientFilter'];

        $this->sanitizeView();
    }

    /** Anything outside the pickers' own options (old cookie, crafted request) falls back to the default. */
    private function sanitizeView(): void
    {
        if (!in_array($this->year, self::yearRange(), true)) $this->year = (int) date('Y');
        if (!in_array($this->perPage, self::PER_PAGE, true)) $this->perPage = 100;
        if (!in_array($this->filterStatus, self::STATUSES, true)) $this->filterStatus = 'all';
        if (!in_array($this->clientFilter, self::CLIENT_FILTERS, true)) $this->clientFilter = 'all';
    }

    private function rememberView(): void
    {
        Cookie::queue(self::VIEW_COOKIE, json_encode([
            'year' => $this->year,
            'perPage' => $this->perPage,
            'filterStatus' => $this->filterStatus,
            'clientFilter' => $this->clientFilter,
        ]), 60 * 24 * 365);
    }
}
