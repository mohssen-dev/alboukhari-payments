<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four modals (PaymentModal, FamilyModal, SendSingleMessage, StudentPanel)
 * are mounted in layouts/app.blade.php — i.e. on EVERY authenticated page,
 * including pages a 'viewer' may open (/reports, /campaigns, /profile).
 * Route middleware therefore does NOT protect their write methods; only the
 * assertCanWrite() calls inside them do. These tests pin that down so a future
 * refactor cannot silently reopen the privilege-escalation hole.
 *
 * NOTE on style: Livewire's test harness swallows the HttpException raised by
 * abort(403) instead of rethrowing it, so asserting on the exception is
 * unreliable. We assert on the DATABASE instead — "the write did not happen"
 * is the security property we actually care about.
 */
class LivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => strtoupper($role),
            'email' => $role . '@authz.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Test Student', 'default_fee_amount' => 30]);
    }

    public function test_viewer_cannot_save_a_payment(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\PaymentModal::class)
            ->call('open', $student->id, (int) date('Y'), (int) date('n'))
            ->set('amount', 30)
            ->call('save');

        $this->assertSame(0, Payment::count(),
            'SECURITY: a viewer created a payment through PaymentModal::save');
    }

    public function test_viewer_cannot_delete_a_payment(): void
    {
        $student = $this->student();
        $payment = Payment::create([
            'student_id' => $student->id,
            'period_year' => (int) date('Y'),
            'period_month' => (int) date('n'),
            'amount' => 30,
            'method' => 'cash',
            'paid_at' => date('Y-m-d'),
        ]);
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\PaymentModal::class)
            ->call('open', $student->id, (int) date('Y'), (int) date('n'))
            ->call('deletePayment', $payment->id);

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertSame(1, Payment::count(),
            'SECURITY: a viewer deleted a payment through PaymentModal::deletePayment');
    }

    public function test_viewer_cannot_edit_a_student(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\StudentPanel::class)
            ->call('switchStudent', $student->id)
            ->set('name', 'HACKED')
            ->call('saveBasic');

        $this->assertSame('Test Student', $student->fresh()->name,
            'SECURITY: a viewer renamed a student through StudentPanel::saveBasic');
    }

    public function test_viewer_cannot_toggle_student_flags(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\StudentPanel::class)
            ->call('switchStudent', $student->id)
            ->call('toggleFlag', 'is_hidden');

        $this->assertFalse((bool) $student->fresh()->is_hidden,
            'SECURITY: a viewer toggled a flag through StudentPanel::toggleFlag');
    }

    public function test_viewer_cannot_bulk_edit_from_the_grid(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\StudentsGrid::class)
            ->call('bulkAction', [$student->id], 'is_hidden', true);

        $this->assertFalse((bool) $student->fresh()->is_hidden,
            'SECURITY: a viewer bulk-edited students through StudentsGrid::bulkAction');
    }

    public function test_viewer_cannot_add_a_fee_override(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(\App\Livewire\StudentPanel::class)
            ->call('switchStudent', $student->id)
            ->set('override_month', 3)
            ->set('override_amount', 0)
            ->call('addOverride');

        $this->assertDatabaseCount('student_monthly_fee_overrides', 0);
    }

    public function test_staff_can_still_save_a_payment(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(\App\Livewire\PaymentModal::class)
            ->call('open', $student->id, (int) date('Y'), (int) date('n'))
            ->set('amount', 30)
            ->call('save');

        $this->assertSame(1, Payment::count(),
            'Staff must still be able to record payments');
    }

    public function test_staff_can_still_edit_a_student(): void
    {
        $student = $this->student();
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(\App\Livewire\StudentPanel::class)
            ->call('switchStudent', $student->id)
            ->set('name', 'Renamed By Staff')
            ->call('saveBasic');

        $this->assertSame('Renamed By Staff', $student->fresh()->name);
    }
}
