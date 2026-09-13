<?php

namespace Tests\Feature;

use App\Livewire\DeleteRecord;
use App\Livewire\StudentPanel;
use App\Livewire\StudentsGrid;
use App\Models\Family;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a student (or a family) takes it off the lists but keeps its
 * payment record: a child who paid four months and left still shows those
 * four payments, and owes nothing after the chosen last month.
 */
class DeleteRecordTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = (int) date('Y');
        $this->actingAs($this->user(User::ROLE_ADMIN));
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $role . '@delete.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** Enrolled in January, paid January–April (30 € each). */
    private function fourMonthStudent(?Family $family = null, string $name = 'Left After April'): Student
    {
        $s = Student::create([
            'name' => $name,
            'family_id' => $family?->id,
            'default_fee_amount' => 30,
            'enrolled_at' => sprintf('%d-01-01', $this->year),
        ]);
        foreach ([1, 2, 3, 4] as $m) {
            Payment::create(['student_id' => $s->id, 'period_year' => $this->year, 'period_month' => $m, 'amount' => 30, 'paid_at' => now(), 'method' => 'cash']);
        }
        DB::table('student_surcharges')->insert(['student_id' => $s->id, 'period_year' => $this->year, 'period_month' => 2, 'amount' => 5, 'reason' => 'book']);

        return $s;
    }

    private function ym(int $month): string
    {
        return sprintf('%04d-%02d', $this->year, $month);
    }

    public function test_deleting_keeps_the_payments_and_owes_nothing_after_the_last_paid_month(): void
    {
        $s = $this->fourMonthStudent();

        Livewire::test(DeleteRecord::class)
            ->dispatch('open-delete-student', studentId: $s->id)
            ->assertSet('isOpen', true)
            ->assertSee('Left After April')
            ->assertSee('120.00')
            ->assertSee('April ' . $this->year)          // shown as the last month owed
            ->assertDontSeeHtml('<select')              // nothing to pick by hand
            ->call('delete')
            ->assertHasNoErrors()
            ->assertSet('isOpen', false)
            ->assertDispatched('students-deleted', studentIds: [$s->id]);

        // Off the lists…
        $this->assertNull(Student::find($s->id));
        $deleted = Student::onlyTrashed()->findOrFail($s->id);

        // …but the record stays: every payment and the surcharge.
        $this->assertSame(4, Payment::where('student_id', $s->id)->count());
        $this->assertEquals(120, (float) Payment::where('student_id', $s->id)->sum('amount'));
        $this->assertSame(1, DB::table('student_surcharges')->where('student_id', $s->id)->count());
        $this->assertSame('Left After April', Payment::where('student_id', $s->id)->first()->student->name);

        // Last paid month April → withdrawn from May: nothing owed after April.
        $this->assertSame(sprintf('%d-05-01', $this->year), $deleted->withdrawn_at->format('Y-m-d'));
        $due = FeeResolver::dueAllMonths($deleted->load(['payments', 'markers', 'feeOverrides', 'surcharges']), $this->year);
        $this->assertEquals(30, $due[4]);
        foreach (range(5, 12) as $m) {
            $this->assertEquals(0, $due[$m], "month {$m} must not be owed");
        }
    }

    public function test_the_last_paid_month_wins_over_an_older_withdrawal_date(): void
    {
        $s = $this->fourMonthStudent();
        $s->update(['withdrawn_at' => sprintf('%d-02-15', $this->year)]);
        Payment::create(['student_id' => $s->id, 'period_year' => $this->year, 'period_month' => 7, 'amount' => 30, 'paid_at' => now(), 'method' => 'bank']);

        DeleteRecord::deleteKeepingPayments($s);

        $this->assertSame(sprintf('%d-08-01', $this->year), Student::onlyTrashed()->find($s->id)->withdrawn_at->format('Y-m-d'));
    }

    public function test_a_student_that_never_paid_owes_nothing_at_all(): void
    {
        $enrolled = Student::create(['name' => 'Never Paid', 'default_fee_amount' => 30, 'enrolled_at' => sprintf('%d-03-01', $this->year)]);
        $undated = Student::create(['name' => 'No Dates', 'default_fee_amount' => 30]);

        DeleteRecord::deleteKeepingPayments($enrolled);
        DeleteRecord::deleteKeepingPayments($undated);

        foreach ([$enrolled->id, $undated->id] as $id) {
            $s = Student::onlyTrashed()->findOrFail($id)->load(['payments', 'markers', 'feeOverrides', 'surcharges']);
            $this->assertEquals(0, array_sum(FeeResolver::dueAllMonths($s, $this->year)), $s->name . ' must owe nothing');
        }
    }

    public function test_deleting_a_family_uses_each_childs_own_last_paid_month(): void
    {
        $family = Family::create(['guardian_name' => 'Leaving Family', 'phone_primary_e164' => '+31622222222']);
        $a = $this->fourMonthStudent($family, 'Child A');
        $b = Student::create(['name' => 'Child B', 'family_id' => $family->id, 'default_fee_amount' => 30]);
        Payment::create(['student_id' => $b->id, 'period_year' => $this->year, 'period_month' => 2, 'amount' => 30, 'paid_at' => now(), 'method' => 'bank']);
        $other = Student::create(['name' => 'Other Family Kid']);

        Livewire::test(DeleteRecord::class)
            ->dispatch('open-delete-family', familyId: $family->id)
            ->assertSee('Child A')
            ->assertSee('Child B')
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertSame(0, Student::where('family_id', $family->id)->count());
        $this->assertSame(sprintf('%d-05-01', $this->year), Student::onlyTrashed()->find($a->id)->withdrawn_at->format('Y-m-d'));
        $this->assertSame(sprintf('%d-03-01', $this->year), Student::onlyTrashed()->find($b->id)->withdrawn_at->format('Y-m-d'));
        $this->assertSame(5, Payment::whereIn('student_id', [$a->id, $b->id])->count());
        $this->assertNotNull(Family::find($family->id));
        $this->assertNotNull(Student::find($other->id));
    }

    public function test_restoring_puts_the_dates_back(): void
    {
        $s = $this->fourMonthStudent();           // enrolled January, no withdrawal date
        DeleteRecord::deleteKeepingPayments($s);

        Livewire::test(StudentsGrid::class)->call('restoreStudent', $s->id);

        $back = Student::findOrFail($s->id);
        $this->assertNull($back->withdrawn_at);
        $this->assertSame(sprintf('%d-01-01', $this->year), $back->enrolled_at->format('Y-m-d'));
        $this->assertSame(4, $back->payments()->count());
    }

    public function test_reports_still_count_a_deleted_students_payments(): void
    {
        $s = $this->fourMonthStudent();
        $before = (float) Payment::where('period_year', $this->year)->sum('amount');

        Livewire::test(DeleteRecord::class)->call('openStudent', $s->id)->call('delete');

        $this->assertSame($before, (float) Payment::where('period_year', $this->year)->sum('amount'));
        $this->get(route('exports.statement', $s->id) . '?year=' . $this->year)->assertOk()->assertSee('Left After April');
    }

    public function test_the_deleted_filter_lists_them_read_only_and_restore_brings_them_back(): void
    {
        $s = $this->fourMonthStudent(null, 'Record Kid');
        Livewire::test(DeleteRecord::class)->call('openStudent', $s->id)->call('delete');

        Livewire::test(StudentsGrid::class)->assertDontSee('Record Kid');

        $grid = Livewire::test(StudentsGrid::class)
            ->set('filterStatus', 'deleted')
            ->assertSee('Record Kid')
            ->assertSeeHtml('row-deleted')
            ->assertDontSeeHtml('data-act="pay" data-m');   // nothing opens on a deleted row

        $grid->call('restoreStudent', $s->id)->assertDontSee('Record Kid');

        $restored = Student::findOrFail($s->id);
        $this->assertSame(4, $restored->payments()->count());
    }

    public function test_staff_and_viewers_cannot_delete_or_restore(): void
    {
        $s = $this->fourMonthStudent();
        $gone = $this->fourMonthStudent(null, 'Already Gone');
        $gone->delete();

        foreach ([User::ROLE_STAFF, User::ROLE_VIEWER] as $role) {
            $this->actingAs($this->user($role));

            Livewire::test(DeleteRecord::class)->call('openStudent', $s->id)->assertForbidden();
            Livewire::test(DeleteRecord::class)->call('delete')->assertForbidden();
            Livewire::test(StudentsGrid::class)->call('restoreStudent', $gone->id)->assertForbidden();
        }

        $this->assertNotNull(Student::find($s->id));
        $this->assertNull(Student::find($gone->id));
    }

    public function test_only_admins_see_the_delete_buttons(): void
    {
        Student::create(['name' => 'Button Kid']);

        $this->get('/')->assertOk()->assertSee(__('delete.student_button'));

        $this->actingAs($this->user(User::ROLE_STAFF));
        $this->get('/')->assertOk()->assertDontSee(__('delete.student_button'));
    }

    public function test_the_grid_and_the_side_panel_let_go_of_a_deleted_student(): void
    {
        $s = Student::create(['name' => 'Vanishing Kid']);

        $grid = Livewire::test(StudentsGrid::class)->assertSee('Vanishing Kid');
        $panel = Livewire::test(StudentPanel::class, ['studentId' => $s->id])->assertSet('studentId', $s->id);

        $s->delete();

        $grid->dispatch('students-deleted', studentIds: [$s->id])->assertDontSee('Vanishing Kid');
        $panel->dispatch('students-deleted', studentIds: [$s->id])->assertSet('studentId', null);
    }

    public function test_deleting_the_last_row_of_the_last_page_goes_back_a_page(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            Student::create(['name' => "S{$i}"]);
        }
        $last = Student::orderByDesc('id')->first();

        $grid = Livewire::test(StudentsGrid::class)->set('perPage', 50)->call('setPage', 2);
        $last->delete();

        $grid->dispatch('students-deleted', studentIds: [$last->id])->assertSet('paginators.page', 1);
    }
}
