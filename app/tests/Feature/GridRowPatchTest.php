<?php

namespace Tests\Feature;

use App\Livewire\PaymentModal;
use App\Livewire\StudentPanel;
use App\Livewire\StudentsGrid;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\GridRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The grid no longer re-renders after a change: the touched row is rebuilt
 * from the shared partial and pushed to the browser as 'grid-row-updated',
 * which swaps it in place. A full re-render was ~1.6 MB of HTML per save.
 *
 * These tests pin the contract: every write path sends the row, a patched
 * row is identical to what a full render produces, and the grid itself no
 * longer subscribes to a full $refresh.
 */
class GridRowPatchTest extends TestCase
{
    use RefreshDatabase;

    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = (int) date('Y');
        $this->month = (int) date('n');

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@gridrow.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]));
    }

    private function student(string $name = 'Row Student'): Student
    {
        return Student::create(['name' => $name, 'default_fee_amount' => 30]);
    }

    private function pay(Student $s, float $amount, ?int $month = null): Payment
    {
        return Payment::create([
            'student_id' => $s->id,
            'period_year' => $this->year,
            'period_month' => $month ?? $this->month,
            'amount' => $amount,
            'method' => 'cash',
            'paid_at' => date('Y-m-d'),
        ]);
    }

    /** One <tr data-sid=…> out of rendered HTML, whitespace-normalised. */
    private function rowFrom(string $html, int $id): string
    {
        $found = preg_match('#<tr wire:key="row-' . $id . '" data-sid="' . $id . '">.*?</tr>#s', $html, $m);
        $this->assertSame(1, $found, "row {$id} not found in the rendered HTML");

        return trim(preg_replace('/\s+/', ' ', $m[0]));
    }

    /** The month cell (<td data-m=N>) of a row. */
    private function cell(string $rowHtml, int $month): string
    {
        $this->assertSame(1, preg_match('#<td class="cell-month [^"]*" data-act="pay" data-m="' . $month . '".*?</td>#s', $rowHtml, $m));

        return $m[0];
    }

    public function test_saving_a_payment_pushes_the_updated_row(): void
    {
        $s = $this->student();

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, $this->year, $this->month)
            ->set('amount', 30)
            ->call('save')
            ->assertDispatched('grid-row-updated', function ($name, $p) use ($s) {
                $cell = $this->cell($p['html'], $this->month);

                return $p['id'] === $s->id
                    && $p['year'] === $this->year
                    && str_contains($cell, 'cell-paid')
                    && str_contains($cell, '<span class="amount">30</span>');
            });

        $this->assertSame(1, Payment::count());
    }

    public function test_deleting_a_payment_pushes_the_updated_row(): void
    {
        $s = $this->student();
        $payment = $this->pay($s, 30);

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, $this->year, $this->month)
            ->call('deletePayment', $payment->id)
            ->assertDispatched('grid-row-updated', function ($name, $p) use ($s) {
                return $p['id'] === $s->id
                    && !str_contains($this->cell($p['html'], $this->month), 'cell-paid');
            });

        $this->assertSame(0, Payment::count());
    }

    public function test_patched_row_is_identical_to_the_full_render(): void
    {
        $s = $this->student();
        $this->pay($s, 15);                       // partial this month
        $this->pay($s, 30, 1);                    // paid in January
        $this->student('Another Student');        // a neighbour row

        $full = Livewire::test(StudentsGrid::class)->html();
        $patch = GridRow::payload($s->id, $this->year);

        $this->assertNotNull($patch);
        $this->assertSame($this->rowFrom($full, $s->id), $this->rowFrom($patch['html'], $s->id),
            'the patched row must be exactly the row a full grid render produces');
    }

    public function test_grid_no_longer_rerenders_on_payment_or_student_events(): void
    {
        $grid = Livewire::test(StudentsGrid::class)->instance();
        $listeners = (fn () => $this->getListeners())->call($grid);

        $this->assertArrayNotHasKey('payment-saved', $listeners,
            'a payment-saved → $refresh listener re-renders the whole grid on every save');
        $this->assertArrayNotHasKey('student-updated', $listeners);
    }

    public function test_grid_flag_toggle_patches_the_row(): void
    {
        $s = $this->student();

        Livewire::test(StudentsGrid::class)
            ->call('toggleFlag', $s->id, 'is_blocked_messages')
            ->assertDispatched('grid-row-updated', fn ($name, $p) => $p['id'] === $s->id && $p['row']['isBlocked'] === true);

        $this->assertTrue((bool) $s->fresh()->is_blocked_messages);
    }

    public function test_panel_fee_change_pushes_the_updated_row(): void
    {
        $s = $this->student();

        // Exempting the current month (override 0) settles it.
        Livewire::test(StudentPanel::class, ['studentId' => $s->id])
            ->set('override_month', $this->month)
            ->set('override_amount', 0)
            ->call('addOverride')
            ->assertDispatched('grid-row-updated', function ($name, $p) use ($s) {
                return $p['id'] === $s->id
                    && str_contains($this->cell($p['html'], $this->month), 'cell-paid');
            });
    }

    public function test_edit_cannot_target_another_students_payment(): void
    {
        $a = $this->student('Student A');
        $b = $this->student('Student B');
        $other = $this->pay($b, 30);

        // editingPaymentId is plain client state now (the ✏️ button sets it in
        // the browser), so it must be scoped to the student in the modal.
        Livewire::test(PaymentModal::class)
            ->call('open', $a->id, $this->year, $this->month)
            ->set('editingPaymentId', $other->id)
            ->set('amount', 1)
            ->call('save');

        $this->assertEquals(30.0, (float) $other->fresh()->amount);
        $this->assertSame(1, Payment::count());
    }

    public function test_grid_rows_carry_no_per_cell_livewire_bindings(): void
    {
        foreach (range(1, 5) as $i) {
            $this->student("Student {$i}");
        }

        $html = Livewire::test(StudentsGrid::class)->html();
        preg_match_all('#<tr wire:key="row-\d+".*?</tr>#s', $html, $rows);

        $this->assertCount(5, $rows[0]);
        foreach ($rows[0] as $tr) {
            $this->assertStringNotContainsString('wire:click', $tr);
            $this->assertStringNotContainsString('wire:loading', $tr);
            $this->assertStringNotContainsString('x-data', $tr);
        }
    }
}
