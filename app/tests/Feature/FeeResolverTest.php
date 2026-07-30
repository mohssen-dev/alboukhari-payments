<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentMonthlyFeeOverride;
use App\Models\StudentSurcharge;
use App\Services\FeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('default_monthly_fee', '30.00');
        $reflection = new \ReflectionClass(FeeResolver::class);
        $prop = $reflection->getProperty('defaultMonthlyFeeCached');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeStudent(?float $defaultFee = null): Student
    {
        $family = Family::create(['guardian_name' => 'G', 'is_blocked_messages' => false]);
        return Student::create([
            'name' => 'Test Student',
            'family_id' => $family->id,
            'allow_sms' => true,
            'is_hidden' => false,
            'is_blocked_messages' => false,
            'is_in_person' => false,
            'included_in_send_all' => true,
            'default_fee_amount' => $defaultFee,
        ]);
    }

    public function test_default_fee_used_when_no_override(): void
    {
        $st = $this->makeStudent();
        $this->assertSame(30.0, FeeResolver::resolve($st, 2026, 6));
    }

    public function test_student_default_fee_overrides_global(): void
    {
        $st = $this->makeStudent(45.50);
        $this->assertSame(45.5, FeeResolver::resolve($st, 2026, 6));
    }

    public function test_monthly_override_takes_precedence(): void
    {
        $st = $this->makeStudent(45.50);
        StudentMonthlyFeeOverride::create([
            'student_id'    => $st->id,
            'period_year'   => 2026,
            'period_month'  => 6,
            'amount'        => 10.00,
            'reason'        => 'special',
        ]);
        $this->assertSame(10.0, FeeResolver::resolve($st, 2026, 6));
    }

    public function test_surcharges_increase_due_amount(): void
    {
        $st = $this->makeStudent();
        StudentSurcharge::create([
            'student_id'   => $st->id,
            'period_year'  => 2026,
            'period_month' => 6,
            'amount'       => 7.50,
            'reason'       => 'late',
        ]);
        $this->assertSame(37.5, FeeResolver::dueAmount($st, 2026, 6));
    }

    public function test_paid_amount_sums_cash_and_bank_only(): void
    {
        $st = $this->makeStudent();
        Payment::create([
            'student_id' => $st->id, 'period_year' => 2026, 'period_month' => 6,
            'amount' => 10.00, 'method' => 'cash', 'paid_at' => now(),
        ]);
        Payment::create([
            'student_id' => $st->id, 'period_year' => 2026, 'period_month' => 6,
            'amount' => 5.00, 'method' => 'bank', 'paid_at' => now(),
        ]);
        Payment::create([
            'student_id' => $st->id, 'period_year' => 2026, 'period_month' => 6,
            'amount' => 1.00, 'method' => 'legacy_zero', 'paid_at' => now(),
        ]);
        $this->assertSame(15.0, FeeResolver::paidAmount($st, 2026, 6));
    }

    public function test_balance_is_due_minus_paid(): void
    {
        $st = $this->makeStudent();
        Payment::create([
            'student_id' => $st->id, 'period_year' => 2026, 'period_month' => 6,
            'amount' => 12.00, 'method' => 'cash', 'paid_at' => now(),
        ]);
        $this->assertSame(18.0, FeeResolver::balance($st, 2026, 6));
    }
}
