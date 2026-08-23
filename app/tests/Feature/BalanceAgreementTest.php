<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FeeResolver;
use App\Services\MonthStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A parent must never be handed a number the office cannot see.
 *
 * Two real defects motivated these tests:
 *  1. FeeResolver knew nothing about `legacy_zero` (the importer's marker for
 *     "settled at import, nothing owed"), so it billed the full fee for months
 *     MonthStatusResolver painted as settled — 81 students and €8,970 of
 *     phantom debt on the production dataset.
 *  2. The printed statement and monthly.xlsx summed raw balance() across all
 *     12 months, so they also billed months that are not due yet. A student
 *     the app showed at €60 printed €240.
 */
class BalanceAgreementTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = (int) date('Y');
    }

    private function studentWithLegacyZero(): Student
    {
        $student = Student::create(['name' => 'Legacy Kid', 'default_fee_amount' => 30]);

        // January settled by the importer at 0 — nothing is owed for it.
        Payment::create([
            'student_id' => $student->id,
            'period_year' => $this->year,
            'period_month' => 1,
            'amount' => 0,
            'method' => 'legacy_zero',
            'paid_at' => $this->year . '-01-01',
        ]);

        return $student->fresh();
    }

    private function loaded(Student $s): Student
    {
        return Student::with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])
            ->findOrFail($s->id);
    }

    public function test_legacy_zero_month_owes_nothing(): void
    {
        $s = $this->loaded($this->studentWithLegacyZero());

        $this->assertSame('legacy_zero', MonthStatusResolver::resolveAll($s, $this->year)[1]);
        $this->assertSame(0.0, FeeResolver::dueAmount($s, $this->year, 1),
            'A month settled at import must not be billed.');
        $this->assertSame(0.0, FeeResolver::dueAllMonths($s, $this->year)[1],
            'The batch resolver must agree with the single-month one.');
        $this->assertSame(0.0, FeeResolver::balance($s, $this->year, 1));
    }

    public function test_legacy_zero_month_with_a_real_payment_is_still_billed(): void
    {
        // Guard against over-correcting: if real money was recorded against a
        // legacy_zero month, the normal fee logic must still apply so the
        // month can read partial/paid rather than silently becoming free.
        $s = $this->studentWithLegacyZero();
        Payment::create([
            'student_id' => $s->id,
            'period_year' => $this->year,
            'period_month' => 1,
            'amount' => 10,
            'method' => 'cash',
            'paid_at' => $this->year . '-01-15',
        ]);
        $s = $this->loaded($s);

        $this->assertSame(30.0, FeeResolver::dueAmount($s, $this->year, 1));
        $this->assertSame(10.0, FeeResolver::paidAmount($s, $this->year, 1));
        $this->assertSame('partial', MonthStatusResolver::resolveAll($s, $this->year)[1]);
    }

    public function test_batch_and_single_month_resolvers_agree(): void
    {
        $s = $this->loaded($this->studentWithLegacyZero());
        $dueAll = FeeResolver::dueAllMonths($s, $this->year);
        $paidAll = FeeResolver::paidAllMonths($s, $this->year);
        $statuses = MonthStatusResolver::resolveAll($s, $this->year);

        for ($m = 1; $m <= 12; $m++) {
            $this->assertEqualsWithDelta(FeeResolver::dueAmount($s, $this->year, $m), $dueAll[$m], 0.001,
                "dueAmount and dueAllMonths disagree for month {$m}");
            $this->assertEqualsWithDelta(FeeResolver::paidAmount($s, $this->year, $m), $paidAll[$m], 0.001,
                "paidAmount and paidAllMonths disagree for month {$m}");
            $this->assertSame(MonthStatusResolver::resolve($s, $this->year, $m), $statuses[$m],
                "resolve and resolveAll disagree for month {$m}");
        }
    }

    public function test_printed_statement_total_equals_the_balance_the_app_shows(): void
    {
        $student = $this->studentWithLegacyZero();
        $admin = User::create([
            'name' => 'A', 'email' => 'a@bal.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        // What the app UI reports (same rule the grid and panel use).
        $s = $this->loaded($student);
        $statuses = MonthStatusResolver::resolveAll($s, $this->year);
        $dueAll = FeeResolver::dueAllMonths($s, $this->year);
        $paidAll = FeeResolver::paidAllMonths($s, $this->year);
        $nowMonth = (int) date('n');
        $appBalance = 0.0;
        for ($m = 1; $m <= $nowMonth; $m++) {
            if (in_array($statuses[$m], ['unpaid', 'late', 'partial'], true)) {
                $appBalance += max(0.0, $dueAll[$m] - $paidAll[$m]);
            }
        }

        $response = $this->actingAs($admin)
            ->get(route('exports.statement', $student) . '?year=' . $this->year)
            ->assertOk();

        $rows = $response->original->getData()['rows'];
        $statementTotal = 0.0;
        foreach ($rows as $r) {
            $statementTotal += max(0.0, $r['balance']);
        }

        $this->assertEqualsWithDelta($appBalance, $statementTotal, 0.01,
            'The printed statement total must equal the balance shown in the app.');
    }

    public function test_statement_never_bills_a_future_month(): void
    {
        $student = Student::create(['name' => 'Future Kid', 'default_fee_amount' => 30]);
        $admin = User::create([
            'name' => 'B', 'email' => 'b@bal.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $rows = $this->actingAs($admin)
            ->get(route('exports.statement', $student) . '?year=' . $this->year)
            ->assertOk()
            ->original->getData()['rows'];

        $nowMonth = (int) date('n');
        for ($m = $nowMonth + 1; $m <= 12; $m++) {
            $this->assertSame(0.0, $rows[$m]['balance'],
                "Month {$m} is not due yet and must not appear as debt on a parent's statement.");
        }
    }
}
