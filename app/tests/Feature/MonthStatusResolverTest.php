<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentMonthlyFeeOverride;
use App\Services\MonthStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(array $attrs = []): Student
    {
        return Student::create(array_merge([
            'name' => 'Test Student',
            'default_fee_amount' => 30,
        ], $attrs));
    }

    private function statusesFor(Student $student, int $year): array
    {
        // Mirror production paths: resolvers read eager-loaded relations.
        $student = Student::with(['payments', 'markers', 'feeOverrides', 'surcharges'])
            ->findOrFail($student->id);

        return MonthStatusResolver::resolveAll($student, $year);
    }

    public function test_zero_fee_override_means_exempt_not_late(): void
    {
        $student = $this->makeStudent();
        $year = (int) date('Y');
        $pastMonth = 1; // January is always past or current

        StudentMonthlyFeeOverride::create([
            'student_id' => $student->id,
            'period_year' => $year,
            'period_month' => $pastMonth,
            'amount' => 0,
        ]);

        $statuses = $this->statusesFor($student, $year);

        $this->assertSame('paid', $statuses[$pastMonth],
            'A month with an explicit 0-fee override is exempt — it must not show unpaid/late.');
    }

    public function test_float_accumulation_still_counts_as_paid(): void
    {
        $student = $this->makeStudent();
        $year = (int) date('Y');

        // 25.10 + 4.90 = 30.000000000000004 in floats — strict >= 30 fails.
        foreach ([25.10, 4.90] as $amount) {
            Payment::create([
                'student_id' => $student->id,
                'period_year' => $year,
                'period_month' => 1,
                'amount' => $amount,
                'method' => 'cash',
                'paid_at' => "$year-01-05",
            ]);
        }

        $statuses = $this->statusesFor($student, $year);

        $this->assertSame('paid', $statuses[1],
            'Two payments summing to the fee must resolve as paid, not partial.');
    }

    public function test_legacy_zero_month_stays_settled(): void
    {
        $student = $this->makeStudent();
        $year = (int) date('Y');

        Payment::create([
            'student_id' => $student->id,
            'period_year' => $year,
            'period_month' => 1,
            'amount' => 0,
            'method' => 'legacy_zero',
            'paid_at' => "$year-01-01",
        ]);

        $statuses = $this->statusesFor($student, $year);

        $this->assertSame('legacy_zero', $statuses[1]);
    }

    public function test_month_before_enrollment_is_not_enrolled(): void
    {
        $year = (int) date('Y');
        $student = $this->makeStudent(['enrolled_at' => "$year-03-01"]);

        $statuses = $this->statusesFor($student, $year);

        $this->assertSame('not_enrolled', $statuses[1]);
        $this->assertSame('not_enrolled', $statuses[2]);
        $this->assertNotSame('not_enrolled', $statuses[3]);
    }
}
