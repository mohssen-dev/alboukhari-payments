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
 * The family window is a family payment form: one amount per child for one
 * month, prefilled with what is still owed, saved in one go.
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

    private function pay(Student $s, float $amount, int $month): void
    {
        Payment::create([
            'student_id' => $s->id, 'period_year' => $this->year, 'period_month' => $month,
            'amount' => $amount, 'method' => 'cash', 'paid_at' => date('Y-m-d'),
        ]);
    }

    public function test_the_window_opens_with_each_childs_amount_ready(): void
    {
        [, $a, $b] = $this->family();
        $this->pay($b, 30, $this->month); // B already paid this month

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id);

        $this->assertTrue($lw->get('isOpen'));
        $this->assertSame('30', $lw->get('amounts')[$a->id], 'what A still owes is prefilled');
        $this->assertSame('', $lw->get('amounts')[$b->id], 'a fully paid child starts empty');
        $lw->assertSeeHtml('data-fm-amount');
    }

    public function test_save_all_records_a_payment_per_child_and_patches_their_rows(): void
    {
        [, $a, $b] = $this->family();

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id)
            ->set("amounts.{$a->id}", '30')
            ->set("amounts.{$b->id}", '15')
            ->set('method', 'bank')
            ->call('saveAll');

        $this->assertSame(2, Payment::count());
        $this->assertDatabaseHas('payments', ['student_id' => $a->id, 'period_year' => $this->year, 'period_month' => $this->month, 'amount' => 30, 'method' => 'bank']);
        $this->assertDatabaseHas('payments', ['student_id' => $b->id, 'period_year' => $this->year, 'period_month' => $this->month, 'amount' => 15, 'method' => 'bank']);

        $patched = collect(data_get($lw->effects, 'dispatches'))->where('name', 'grid-row-updated')->pluck('params.id')->sort()->values()->all();
        $this->assertSame([$a->id, $b->id], $patched, 'each paid child\'s grid row is patched in place');
        $this->assertFalse($lw->get('isOpen'), 'the window closes after a successful save');
    }

    public function test_a_child_outside_this_family_cannot_be_paid_through_it(): void
    {
        [, $a] = $this->family();
        $stranger = Student::create(['name' => 'Stranger', 'default_fee_amount' => 30]);

        Livewire::test(FamilyModal::class)
            ->call('open', $a->id)
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
            ->call('open', $a->id)
            ->set("amounts.{$a->id}", '30')
            ->call('saveAll');

        $this->assertSame(0, Payment::count(), 'SECURITY: a viewer recorded a payment through the family window');
    }

    public function test_nothing_to_save_keeps_the_window_open(): void
    {
        [, $a, $b] = $this->family();

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id)
            ->set("amounts.{$a->id}", '')
            ->set("amounts.{$b->id}", '0')
            ->call('saveAll');

        $this->assertSame(0, Payment::count());
        $this->assertTrue($lw->get('isOpen'));
        $lw->assertDispatched('toast');
    }

    public function test_picking_another_month_recomputes_what_is_owed(): void
    {
        [, $a] = $this->family();
        $this->pay($a, 30, 1);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id);

        $lw->set('month', 1);
        $this->assertSame('', $lw->get('amounts')[$a->id], 'January is already paid');

        $lw->set('month', 2);
        $this->assertSame('30', $lw->get('amounts')[$a->id]);
    }

    public function test_a_child_not_enrolled_that_month_is_skipped(): void
    {
        [$f, $a] = $this->family();
        $later = Student::create([
            'name' => 'Kid Later', 'family_id' => $f->id, 'default_fee_amount' => 30,
            'enrolled_at' => ($this->year + 1) . '-01-01',
        ]);

        $lw = Livewire::test(FamilyModal::class)->call('open', $a->id);
        $this->assertSame('', $lw->get('amounts')[$later->id]);

        $lw->set("amounts.{$later->id}", '30')->call('saveAll');
        $this->assertSame(0, Payment::where('student_id', $later->id)->count());
    }
}
