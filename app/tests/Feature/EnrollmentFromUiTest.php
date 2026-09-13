<?php

namespace Tests\Feature;

use App\Livewire\FamilyModal;
use App\Livewire\PaymentModal;
use App\Models\Family;
use App\Models\Student;
use App\Models\User;
use App\Services\MonthStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The enrolment month can be set straight from a month cell (payment modal)
 * or from the family window: months before it stop being owed.
 */
class EnrollmentFromUiTest extends TestCase
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
            'name' => $role, 'email' => $role . '@enroll.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function statuses(Student $s): array
    {
        $loaded = Student::with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])->find($s->id);

        return MonthStatusResolver::resolveAll($loaded, $this->year);
    }

    public function test_a_month_cell_sets_the_enrolment_month(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, $this->year, 3)
            ->call('setEnrollmentMonth')
            ->assertDispatched('grid-row-updated')
            ->assertSet('enrolledAt', "{$this->year}-03-01");

        $this->assertSame("{$this->year}-03-01", $s->fresh()->enrolled_at->format('Y-m-d'));
        $st = $this->statuses($s);
        $this->assertSame('not_enrolled', $st[1], 'earlier months are no longer owed');
        $this->assertSame('not_enrolled', $st[2]);
        $this->assertNotSame('not_enrolled', $st[3]);
    }

    public function test_the_enrolment_date_can_be_cleared(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30, 'enrolled_at' => "{$this->year}-05-01"]);

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, $this->year, 5)
            ->assertSet('enrolledAt', "{$this->year}-05-01")
            ->call('clearEnrollment')
            ->assertSet('enrolledAt', null);

        $this->assertNull($s->fresh()->enrolled_at);
    }

    public function test_enrolment_cannot_start_on_or_after_withdrawal(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30, 'withdrawn_at' => "{$this->year}-04-01"]);

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, $this->year, 6)
            ->call('setEnrollmentMonth')
            ->assertDispatched('toast');

        $this->assertNull($s->fresh()->enrolled_at);
    }

    public function test_the_family_window_sets_enrolment_for_one_child(): void
    {
        $f = Family::create(['phone_primary_e164' => '+31612345678']);
        $a = Student::create(['name' => 'Kid A', 'family_id' => $f->id, 'default_fee_amount' => 30]);
        $b = Student::create(['name' => 'Kid B', 'family_id' => $f->id, 'default_fee_amount' => 30]);

        $lw = Livewire::test(FamilyModal::class)
            ->call('open', $a->id)
            ->set('month', 4)
            ->call('setEnrollmentMonth', $b->id);

        $this->assertSame("{$this->year}-04-01", $b->fresh()->enrolled_at->format('Y-m-d'));
        $this->assertNull($a->fresh()->enrolled_at, 'only that child changes');
        $this->assertTrue(collect($lw->get('members'))->firstWhere('id', $b->id)['is_enroll_month']);

        $lw->call('clearEnrollment', $b->id);
        $this->assertNull($b->fresh()->enrolled_at);
    }

    public function test_the_family_window_cannot_change_a_child_outside_the_family(): void
    {
        $f = Family::create(['phone_primary_e164' => '+31612345678']);
        $a = Student::create(['name' => 'Kid A', 'family_id' => $f->id]);
        $stranger = Student::create(['name' => 'Stranger']);

        Livewire::test(FamilyModal::class)->call('open', $a->id)->call('setEnrollmentMonth', $stranger->id);

        $this->assertNull($stranger->fresh()->enrolled_at);
    }

    public function test_viewer_cannot_change_enrolment(): void
    {
        $s = Student::create(['name' => 'Kid']);
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(PaymentModal::class)->call('open', $s->id, $this->year, 3)->call('setEnrollmentMonth');

        $this->assertNull($s->fresh()->enrolled_at, 'SECURITY: a viewer changed an enrolment date');
    }
}
