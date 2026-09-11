<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Services\MonthStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the one-time correction of imported payment methods
 * (2026_09_11_100000_reclassify_imported_payment_methods): imported 'bank'
 * rows were really cash, imported 0 ('legacy_zero') rows were really 30 € by
 * bank. Manual entries must never be touched.
 */
class ReclassifyImportedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_11_100000_reclassify_imported_payment_methods.php');
    }

    private function row(Student $s, int $month, float $amount, string $method, ?string $source): Payment
    {
        return Payment::create([
            'student_id' => $s->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount' => $amount,
            'method' => $method,
            'source' => $source,
            'paid_at' => sprintf('2026-%02d-01', $month),
        ]);
    }

    public function test_import_rows_are_reclassified_and_manual_rows_are_not(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);

        $importBank30 = $this->row($s, 1, 30, 'bank', 'excel_import');
        $importBank15 = $this->row($s, 2, 15, 'bank', 'excel_import');
        $importZero   = $this->row($s, 3, 0, 'legacy_zero', 'excel_import');
        $manualBank   = $this->row($s, 4, 30, 'bank', null);
        $manualCash   = $this->row($s, 5, 20, 'cash', null);

        $this->migration()->up();

        $this->assertSame('cash', $importBank30->fresh()->method);
        $this->assertSame(30.00, (float) $importBank30->fresh()->amount);
        $this->assertSame('cash', $importBank15->fresh()->method);
        $this->assertSame(15.00, (float) $importBank15->fresh()->amount);

        // Converted 0 rows must NOT be caught by the bank→cash step.
        $this->assertSame('bank', $importZero->fresh()->method);
        $this->assertSame(30.00, (float) $importZero->fresh()->amount);

        $this->assertSame('bank', $manualBank->fresh()->method, 'manual entries keep the method the office chose');
        $this->assertSame('cash', $manualCash->fresh()->method);
        $this->assertSame(20.00, (float) $manualCash->fresh()->amount);

        // A month that was "settled at 0" is now a real, fully paid month.
        // (resolveAll reads eager-loaded relations only, as the grid does.)
        $loaded = Student::with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])->find($s->id);
        $this->assertSame('paid', MonthStatusResolver::resolveAll($loaded, 2026)[3]);
    }

    public function test_down_restores_the_previous_state(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);
        $bank = $this->row($s, 1, 30, 'bank', 'excel_import');
        $zero = $this->row($s, 2, 0, 'legacy_zero', 'excel_import');

        $m = $this->migration();
        $m->up();
        $m->down();

        $this->assertSame('bank', $bank->fresh()->method);
        $this->assertSame('legacy_zero', $zero->fresh()->method);
        $this->assertSame(0.00, (float) $zero->fresh()->amount);
    }
}
