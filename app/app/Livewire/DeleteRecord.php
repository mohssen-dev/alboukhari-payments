<?php

namespace App\Livewire;

use App\Models\Family;
use App\Models\Student;
use App\Services\MonthNames;
use App\Support\AuthorizesLivewireWrite;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Delete a student, or a whole family — admin only, mounted once in the
 * layout and opened with 'open-delete-student' {studentId} or
 * 'open-delete-family' {familyId}.
 *
 * Deleting takes the student off the grid, out of every message and out of
 * the arrears, but the payment record stays for the reports. The school's
 * rule: a deleted student's last month owed is the last month it paid for —
 * nothing is owed after it, and nobody picks that month by hand. It is
 * stored as the withdrawal date so every resolver, statement and export
 * agrees. Deleted students are listed (read-only) under the grid's
 * "Deleted" filter and can be restored there; restoring puts the dates back
 * the way they were.
 */
class DeleteRecord extends Component
{
    use AuthorizesLivewireWrite;

    public const LOG = 'deletion';

    public bool $isOpen = false;
    #[Locked] public string $kind = 'student';
    #[Locked] public ?int $studentId = null;
    #[Locked] public ?int $familyId = null;

    #[On('open-delete-student')]
    public function openStudent(int $studentId): void
    {
        $this->assertAdmin();
        $this->resetState();

        if (!Student::whereKey($studentId)->exists()) {
            $this->dispatch('toast', message: __('delete.not_found'), type: 'error');
            return;
        }

        $this->kind = 'student';
        $this->studentId = $studentId;
        $this->isOpen = true;
    }

    #[On('open-delete-family')]
    public function openFamily(int $familyId): void
    {
        $this->assertAdmin();
        $this->resetState();

        if (!Student::where('family_id', $familyId)->exists()) {
            $this->dispatch('toast', message: __('delete.not_found'), type: 'error');
            return;
        }

        $this->kind = 'family';
        $this->familyId = $familyId;
        $this->isOpen = true;
    }

    public function delete(): void
    {
        $this->assertAdmin();

        $students = $this->students();
        if ($students->isEmpty()) {
            $this->close();
            $this->dispatch('toast', message: __('delete.not_found'), type: 'error');
            return;
        }

        DB::transaction(fn () => $students->each(fn (Student $s) => self::deleteKeepingPayments($s)));

        $ids = $students->pluck('id')->all();
        $label = $this->kind === 'family' ? (Family::find($this->familyId)?->displayName() ?? '') : $students->first()->name;

        $this->dispatch('students-deleted', studentIds: $ids);
        $this->dispatch('toast', type: 'success', message: $this->kind === 'family'
            ? __('delete.family_done', ['name' => $label, 'count' => count($ids)])
            : __('delete.student_done', ['name' => $label]));

        $this->close();
    }

    /**
     * Soft-delete one student: owed up to its last paid month, payments kept.
     * The dates it had before are logged so a restore can put them back.
     */
    public static function deleteKeepingPayments(Student $student): void
    {
        $before = [
            'enrolled_at' => $student->enrolled_at?->format('Y-m-d'),
            'withdrawn_at' => $student->withdrawn_at?->format('Y-m-d'),
        ];

        $last = self::lastPaidMonth($student);
        if ($last) {
            // FeeResolver owes nothing from the withdrawal month on.
            $student->withdrawn_at = $last->copy()->addMonth();
        } else {
            // Never paid: owes nothing at all (enrolment = withdrawal month).
            $start = $student->enrolled_at?->copy()->startOfMonth() ?? now()->startOfMonth();
            $student->enrolled_at = $start;
            $student->withdrawn_at = $start;
        }
        if ($student->enrolled_at && $student->withdrawn_at->lt($student->enrolled_at->copy()->startOfMonth())) {
            $student->withdrawn_at = $student->enrolled_at->copy()->startOfMonth();
        }

        $student->save();
        $student->delete();

        activity(self::LOG)
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->withProperties(['before' => $before, 'last_paid_month' => $last?->format('Y-m')])
            ->log('student deleted, payments kept');
    }

    /** Back into the lists, with the enrolment / withdrawal dates it had before the delete. */
    public static function restore(Student $student): void
    {
        $log = Activity::inLog(self::LOG)->forSubject($student)->latest('id')->first();
        $before = $log?->properties->get('before');

        if (is_array($before)) {
            $student->enrolled_at = $before['enrolled_at'];
            $student->withdrawn_at = $before['withdrawn_at'];
        }
        $student->restore();
        $student->save();
    }

    /** The first day of the latest month this student paid for, or null if it never paid. */
    public static function lastPaidMonth(Student $student): ?Carbon
    {
        $last = DB::table('payments')->where('student_id', $student->id)
            ->where(fn ($q) => $q->where('amount', '>', 0)->orWhere('method', 'legacy_zero'))
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->first(['period_year', 'period_month']);

        return $last ? Carbon::create((int) $last->period_year, (int) $last->period_month, 1)->startOfDay() : null;
    }

    public function close(): void
    {
        $this->resetState();
    }

    public function render()
    {
        return view('livewire.delete-record', $this->isOpen ? $this->summary() : []);
    }

    /** Who goes, the payment record each keeps, and its last month owed. */
    private function summary(): array
    {
        $students = $this->students();
        $ids = $students->pluck('id')->all();
        $names = MonthNames::full();
        $label = fn (?Carbon $d) => $d ? $names[$d->month] . ' ' . $d->year : null;

        $payments = DB::table('payments')->whereIn('student_id', $ids)
            ->selectRaw('student_id, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total, MIN(period_year * 12 + period_month) AS first_ym')
            ->groupBy('student_id')->get()->keyBy('student_id');

        return [
            'people' => $students->map(function (Student $s) use ($payments, $label) {
                $p = $payments[$s->id] ?? null;
                $firstYm = $p ? (int) $p->first_ym - 1 : null;

                return [
                    'id' => $s->id,
                    'number' => $s->external_id,
                    'name' => $s->name,
                    'payments' => (int) ($p->n ?? 0),
                    'paid' => (float) ($p->total ?? 0),
                    'from' => $firstYm !== null ? $label(Carbon::create(intdiv($firstYm, 12), $firstYm % 12 + 1, 1)) : null,
                    'lastPaid' => $label(self::lastPaidMonth($s)),
                ];
            })->all(),
            'paymentCount' => (int) $payments->sum('n'),
            'paymentTotal' => (float) $payments->sum('total'),
            'familyName' => $this->kind === 'family'
                ? Family::find($this->familyId)?->displayName()
                : $students->first()?->family?->displayName(),
        ];
    }

    /** The students this window deletes — never already-deleted ones. @return Collection<int, Student> */
    private function students(): Collection
    {
        return $this->kind === 'family'
            ? ($this->familyId ? Student::where('family_id', $this->familyId)->orderBy('external_id')->orderBy('id')->get() : collect())
            : Student::whereKey($this->studentId)->get();
    }

    private function resetState(): void
    {
        $this->reset(['isOpen', 'kind', 'studentId', 'familyId']);
        $this->resetValidation();
    }
}
