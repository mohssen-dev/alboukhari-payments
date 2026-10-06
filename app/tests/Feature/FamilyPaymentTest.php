<?php

namespace Tests\Feature;

use App\Livewire\FamilyModal;
use App\Models\Family;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The family window: the year's payments per child on top, and one amount
 * per child for the chosen month below. Each amount is the month's TOTAL —
 * raising it records the difference, lowering it reduces what was recorded.
 */
class FamilyPaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = (int) date('Y');
        $this->month = (int) date('n');
        $this->actingAs($this->user(User::ROLE_ADMIN));
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $role . '@family.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array{0: Family, 1: Student, 2: Student} */
    private function family(): array
    {
        $f = Family::create(['guardian_name' => 'Test Family', 'phone_primary_e164' => '+31612345678']);
        $a = Student::create(['name' => 'Kid A', 'family_id' => $f->id, 'default_fee_amount' => 30]);
        $b = Student::create(['name' => 'Kid B', 'family_id' => $f->id, 'default_fee_amount' => 30]);

        return [$f, $a, $b];
    }

    private function pay(Student $s, float $amount, ?int $month = null, ?string $paidAt = null, string $method = 'cash'): Payment
    {
        return Payment::create([
            'student_id' => $s->id, 'period_year' => $this->year, 'period_month' => $month ?? $this->month,
            'amount' => $amount, 'method' => $method, 'paid_at' => $paidAt ?? date('Y-m-d'),
        ]);
    }

    private function member($lw, Student $s): array
    {
        return collect($lw->get('members'))->firstWhere('id', $s->id);
    }

    private function recorded(Student $s, ?int $year = null, ?int $month = null): float
    {
        return (float) Payment::where('student_id', $s->id)
            ->where('period_year', $year ?? $this->year)
            ->where('period_month', $month ?? $this->month)
            ->sum('amount');
    }

    public function test_the_window_opens_with_the_table_and_each_childs_amount_ready(): void
    {
        [, $a, $b] = $this->family();
        $this->pay($b, 30);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month);

        $this->assertTrue($lw->get('isOpen'));
        $this->assertSame('30', $lw->get('amounts')[$a->id], 'what A owes is suggested');
        $this->assertSame('30', $lw->get('amounts')[$b->id], 'B shows what is already recorded');
        $this->assertEquals(0, $this->member($lw, $a)['month_paid']);
        $this->assertEquals(30, $this->member($lw, $b)['month_paid']);
        $this->assertEquals(30, $this->member($lw, $b)['months'][$this->month]['paid'], 'the year table carries each month');
        $lw->assertSeeHtml('family-table')->assertSeeHtml('data-fm-amount');
    }

    public function test_saving_records_new_payments_and_patches_each_row(): void
    {
        [, $a, $b] = $this->family();

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '30')
            ->set("amounts.{$b->id}", '15')
            ->set('method', 'bank')
            ->call('saveAll');

        $this->assertSame(2, Payment::count());
        $this->assertDatabaseHas('payments', ['student_id' => $a->id, 'amount' => 30, 'method' => 'bank']);
        $this->assertDatabaseHas('payments', ['student_id' => $b->id, 'amount' => 15, 'method' => 'bank']);

        $patched = collect(data_get($lw->effects, 'dispatches'))->where('name', 'grid-row-updated');
        $this->assertSame([$a->id, $b->id], $patched->pluck('params.id')->sort()->values()->all());
        $this->assertSame(['year'], $patched->pluck('params.scope')->unique()->values()->all(),
            'a payment only changes that year — a grid showing another year must not re-render');

        $this->assertTrue($lw->get('isOpen'), 'the window stays open to show the result');
        $this->assertEquals(30, $this->member($lw, $a)['month_paid'], 'the table is refreshed');
    }

    public function test_unchanged_amounts_are_not_recorded_twice(): void
    {
        [, $a, $b] = $this->family();
        $this->pay($b, 30);

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '')   // A: nothing today
            ->call('saveAll');              // B still shows its recorded 30

        $this->assertSame(1, Payment::count(), 'showing what is recorded must never add it again');
        $lw->assertDispatched('toast');
    }

    public function test_lowering_an_amount_reduces_the_recorded_payment(): void
    {
        [, $a] = $this->family();
        $p = $this->pay($a, 30, null, null, 'cash');

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '20')
            ->set('method', 'bank')
            ->call('saveAll');

        $this->assertSame(1, Payment::where('student_id', $a->id)->count());
        $this->assertEquals(20, (float) $p->fresh()->amount);
        $this->assertSame('cash', $p->fresh()->method, 'a correction keeps how it was paid');
    }

    public function test_clearing_an_amount_removes_the_payment(): void
    {
        [, $a] = $this->family();
        $this->pay($a, 30);

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '')
            ->call('saveAll');

        $this->assertSame(0, Payment::where('student_id', $a->id)->count());
    }

    public function test_raising_an_amount_records_only_the_difference(): void
    {
        [, $a] = $this->family();
        $this->pay($a, 15, null, null, 'cash');

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '30')
            ->set('method', 'bank')
            ->call('saveAll');

        $this->assertEquals(30, $this->recorded($a));
        $this->assertDatabaseHas('payments', ['student_id' => $a->id, 'amount' => 15, 'method' => 'cash']);
        $this->assertDatabaseHas('payments', ['student_id' => $a->id, 'amount' => 15, 'method' => 'bank']);
    }

    public function test_reducing_across_several_payments_takes_from_the_newest_first(): void
    {
        [, $a] = $this->family();
        $older = $this->pay($a, 30, null, date('Y-m-01'));
        $newer = $this->pay($a, 15, null, date('Y-m-d', strtotime(date('Y-m-01') . ' +4 days')));

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '20')
            ->call('saveAll');

        $this->assertNull(Payment::find($newer->id), 'the newest payment goes first');
        $this->assertEquals(20, (float) $older->fresh()->amount);
        $this->assertEquals(20, $this->recorded($a));
    }

    public function test_next_year_can_be_paid_ahead_and_the_table_follows_the_year(): void
    {
        [, $a] = $this->family();
        $next = $this->year + 1;

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set('year', $next)
            ->set('month', 1)
            ->set("amounts.{$a->id}", '30')
            ->call('saveAll');

        $this->assertEquals(30, $this->recorded($a, $next, 1));
        $this->assertSame($next, $lw->get('year'));
        $this->assertEquals(30, $this->member($lw, $a)['months'][1]['paid'], 'the table shows next year');
    }

    public function test_a_year_outside_the_offered_range_is_clamped(): void
    {
        [, $a] = $this->family();

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month)->set('year', 1990);

        $this->assertSame($this->year - 1, $lw->get('year'));
    }

    public function test_a_child_outside_this_family_cannot_be_paid_through_it(): void
    {
        [, $a] = $this->family();
        $stranger = Student::create(['name' => 'Stranger', 'default_fee_amount' => 30]);

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$stranger->id}", '30')
            ->set("amounts.{$a->id}", '30')
            ->call('saveAll');

        $this->assertSame(0, Payment::where('student_id', $stranger->id)->count(),
            'amount keys are client state — only this family\'s children may be paid');
        $this->assertSame(1, Payment::where('student_id', $a->id)->count());
    }

    public function test_viewer_cannot_save_family_payments(): void
    {
        [, $a] = $this->family();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id, null, $this->month)
            ->set("amounts.{$a->id}", '30')
            ->call('saveAll');

        $this->assertSame(0, Payment::count(), 'SECURITY: a viewer recorded a payment through the family window');
    }

    public function test_picking_another_month_recomputes_the_form(): void
    {
        [, $a] = $this->family();
        $this->pay($a, 30, 1);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month);

        $lw->set('month', 1);
        $this->assertEquals(30, $this->member($lw, $a)['month_paid']);
        $this->assertSame('30', $lw->get('amounts')[$a->id]);

        $lw->set('month', 2);
        $this->assertEquals(0, $this->member($lw, $a)['month_paid']);
        $this->assertSame('30', $lw->get('amounts')[$a->id], 'nothing recorded → the fee is suggested');
    }

    public function test_a_child_not_enrolled_that_month_is_skipped(): void
    {
        [$f, $a] = $this->family();
        $later = Student::create([
            'name' => 'Kid Later', 'family_id' => $f->id, 'default_fee_amount' => 30,
            'enrolled_at' => ($this->year + 2) . '-01-01',
        ]);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month);
        $this->assertSame('', $lw->get('amounts')[$later->id]);

        $lw->set("amounts.{$later->id}", '30')->call('saveAll');
        $this->assertSame(0, Payment::where('student_id', $later->id)->count());
    }

    // ---- the month it opens on ----

    public function test_it_opens_on_the_oldest_unpaid_month_not_the_current_one(): void
    {
        [, $a, $b] = $this->family();
        // Everything before $owed is paid; in $owed only A paid — B still owes it.
        $owed = max(1, $this->month - 1);
        foreach (range(1, $owed) as $m) {
            $this->pay($a, 30, $m);
            if ($m < $owed) $this->pay($b, 30, $m);
        }

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id)
            ->assertSet('selectedMonths', [$owed])
            ->assertSet('month', $owed);
        $this->assertSame('30', $lw->get('amounts')[$a->id], 'A paid it: shows what is recorded');
        $this->assertSame('30', $lw->get('amounts')[$b->id], 'B owes it: the fee is suggested');
    }

    public function test_a_family_paid_up_to_now_opens_on_the_next_month_to_pay(): void
    {
        [, $a, $b] = $this->family();
        foreach (range(1, $this->month) as $m) {
            $this->pay($a, 30, $m);
            $this->pay($b, 30, $m);
        }

        $next = $this->month === 12 ? 12 : $this->month + 1;
        Livewire::test(FamilyModal::class)->call('open', $a->id)->assertSet('selectedMonths', [$next]);
    }

    public function test_months_before_enrolment_are_not_counted_as_unpaid(): void
    {
        [, $a, $b] = $this->family();
        $a->update(['enrolled_at' => sprintf('%04d-%02d-01', $this->year, $this->month)]);
        $b->update(['enrolled_at' => sprintf('%04d-%02d-01', $this->year, $this->month)]);

        Livewire::test(FamilyModal::class)->call('open', $a->id)->assertSet('selectedMonths', [$this->month]);
    }

    // ---- several months at once ----

    public function test_several_months_are_paid_in_one_save_topping_up_only_what_is_missing(): void
    {
        [, $a, $b] = $this->family();
        $this->pay($a, 30, 2);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month)->call('pickMonths', [1, 2, 3]);
        $this->assertSame([1, 2, 3], $lw->get('selectedMonths'));
        $this->assertSame('30', $lw->get('amounts')[$a->id], 'the amount is per month');
        $this->assertSame('30', $lw->get('amounts')[$b->id]);

        $lw->call('saveAll')->assertDispatched('toast', type: 'success');

        foreach ([1, 2, 3] as $m) {
            $this->assertEquals(30, $this->recorded($a, null, $m));
            $this->assertEquals(30, $this->recorded($b, null, $m));
        }
        $this->assertSame(6, Payment::count(), 'the one before + A: months 1 and 3 · B: months 1, 2 and 3');
        $this->assertSame([1, 2, 3], $lw->get('selectedMonths'), 'the window stays on the picked months');
    }

    public function test_several_months_never_reduce_a_month_holding_more(): void
    {
        [, $a, $b] = $this->family();
        $this->pay($a, 40, 1);

        Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month)
            ->call('pickMonths', [1, 2])
            ->set("amounts.{$a->id}", '30')
            ->set("amounts.{$b->id}", '')
            ->call('saveAll');

        $this->assertEquals(40, $this->recorded($a, null, 1));
        $this->assertEquals(30, $this->recorded($a, null, 2));
        $this->assertSame(0, Payment::where('student_id', $b->id)->count(), 'an empty amount pays nothing');
    }

    public function test_picked_months_are_cleaned_and_a_single_month_choice_ends_multi_mode(): void
    {
        [, $a] = $this->family();

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month)
            ->call('pickMonths', [13, 0, 4, 2, 2, '3'])
            ->assertSet('selectedMonths', [2, 3, 4])
            ->assertSet('month', 2);

        $lw->call('pickMonths', [])->assertSet('selectedMonths', [2, 3, 4]);
        $lw->set('month', 6)->assertSet('selectedMonths', [6]);
    }

    public function test_a_failed_reload_closes_the_window_instead_of_an_error_page(): void
    {
        [, $a] = $this->family();
        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id, null, $this->month);

        $a->forceDelete();
        $lw->set('year', $this->year - 1)->assertOk()->assertSet('isOpen', false)->assertDispatched('toast', type: 'error');
    }
}
