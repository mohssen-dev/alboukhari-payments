<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Services\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The school re-imports the spreadsheet regularly.
 *
 * importMonths() used to clear a month by deleting EVERY payment with method
 * legacy_zero or bank for that student/year/month before inserting the sheet
 * value — and a bank payment recorded by an office worker in the app matches
 * that filter exactly. Re-importing therefore destroyed real recorded money
 * with no warning and no audit trail. Rows the importer owns are now tagged
 * source='excel_import' and only those are replaced.
 */
class ImportNonDestructiveTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/import_test_' . getmypid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** Builds a minimal sheet in the layout the importer expects. */
    private function makeSheet(int $externalId, string $name, array $monthValues): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $headers = ['id', 'Naam', 'Telefoon', 'sms', 'whatsapp', 'send_all', 'Tweede telefoonnummer',
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];
        $sheet->fromArray($headers, null, 'A1');

        $row = [$externalId, $name, '0612345678', 'TRUE', 'FALSE', 'TRUE', ''];
        for ($m = 1; $m <= 12; $m++) {
            $row[] = $monthValues[$m] ?? '';
        }
        $sheet->fromArray($row, null, 'A2');

        (new Xlsx($ss))->save($this->file);
        return $this->file;
    }

    public function test_reimport_does_not_delete_a_manually_entered_bank_payment(): void
    {
        $year = 2026;

        // First import: January = 30 from the sheet.
        (new StudentImporter())->import($this->makeSheet(1, 'Kid One', [1 => 30]), $year);
        $student = Student::where('external_id', 1)->firstOrFail();

        // The office then records a second, real bank payment for January
        // through the app (no source tag — it is not an import row).
        $manual = Payment::create([
            'student_id' => $student->id,
            'period_year' => $year,
            'period_month' => 1,
            'amount' => 45,
            'method' => 'bank',
            'paid_at' => "$year-01-20",
            'note' => 'Overboeking ouder',
        ]);

        // Someone re-imports the same sheet.
        (new StudentImporter())->import($this->makeSheet(1, 'Kid One', [1 => 30]), $year);

        $this->assertDatabaseHas('payments', ['id' => $manual->id, 'amount' => 45.00]);
        $this->assertNotNull(Payment::find($manual->id),
            'Re-import destroyed a bank payment entered by staff in the app.');
    }

    public function test_reimport_does_not_delete_a_cash_payment(): void
    {
        $year = 2026;
        (new StudentImporter())->import($this->makeSheet(2, 'Kid Two', [2 => 30]), $year);
        $student = Student::where('external_id', 2)->firstOrFail();

        $cash = Payment::create([
            'student_id' => $student->id,
            'period_year' => $year,
            'period_month' => 2,
            'amount' => 10,
            'method' => 'cash',
            'paid_at' => "$year-02-10",
        ]);

        (new StudentImporter())->import($this->makeSheet(2, 'Kid Two', [2 => 30]), $year);

        $this->assertDatabaseHas('payments', ['id' => $cash->id, 'amount' => 10.00]);
    }

    public function test_reimport_is_still_idempotent_for_its_own_rows(): void
    {
        $year = 2026;
        // '0' as a STRING: PhpSpreadsheet writes a numeric 0 as an empty cell,
        // which reads back as null and never reaches the importer. Real sheets
        // from Excel/Sheets do carry the zero, so a string keeps the fixture
        // faithful to production (a 0 month becomes a legacy_zero row).
        $sheet = fn () => $this->makeSheet(3, 'Kid Three', [1 => 30, 2 => 30, 3 => '0']);

        (new StudentImporter())->import($sheet(), $year);
        $afterFirst = Payment::count();

        (new StudentImporter())->import($sheet(), $year);
        $afterSecond = Payment::count();

        $this->assertSame($afterFirst, $afterSecond,
            'Re-importing the same sheet must not duplicate rows.');
        $this->assertSame(3, $afterSecond);
    }

    public function test_importer_tags_the_rows_it_creates(): void
    {
        (new StudentImporter())->import($this->makeSheet(4, 'Kid Four', [1 => 30]), 2026);

        $this->assertSame(1, Payment::where('source', StudentImporter::SOURCE)->count());
        $this->assertSame(0, Payment::whereNull('source')->count());
    }

    public function test_updated_sheet_value_replaces_the_previous_import_row(): void
    {
        $year = 2026;
        (new StudentImporter())->import($this->makeSheet(5, 'Kid Five', [1 => 30]), $year);
        $this->assertSame(30.00, (float) Payment::where('source', StudentImporter::SOURCE)->first()->amount);

        // The sheet is corrected to 25 and re-imported.
        (new StudentImporter())->import($this->makeSheet(5, 'Kid Five', [1 => 25]), $year);

        $rows = Payment::where('source', StudentImporter::SOURCE)->get();
        $this->assertCount(1, $rows, 'the corrected value must replace, not stack');
        $this->assertSame(25.00, (float) $rows->first()->amount);
    }
    public function test_clearing_a_cell_in_the_sheet_removes_its_import_row(): void
    {
        $year = 2026;

        // Sheet says January = 30.
        (new StudentImporter())->import($this->makeSheet(6, 'Kid Six', [1 => 30]), $year);
        $this->assertSame(1, Payment::where('source', StudentImporter::SOURCE)->count());

        // The office clears that cell in the spreadsheet and re-imports.
        (new StudentImporter())->import($this->makeSheet(6, 'Kid Six', []), $year);

        $this->assertSame(0, Payment::where('source', StudentImporter::SOURCE)->count(),
            'A cleared cell must drop its import row so the app mirrors the sheet.');
    }

    public function test_clearing_a_cell_does_not_touch_a_manual_payment(): void
    {
        $year = 2026;
        (new StudentImporter())->import($this->makeSheet(7, 'Kid Seven', [1 => 30]), $year);
        $student = Student::where('external_id', 7)->firstOrFail();

        $manual = Payment::create([
            'student_id' => $student->id,
            'period_year' => $year,
            'period_month' => 1,
            'amount' => 20,
            'method' => 'cash',
            'paid_at' => "$year-01-09",
        ]);

        // Same month, now blank in the sheet.
        (new StudentImporter())->import($this->makeSheet(7, 'Kid Seven', []), $year);

        $this->assertNotNull(Payment::find($manual->id),
            'Clearing a sheet cell must never delete money recorded in the app.');
        $this->assertSame(0, Payment::where('source', StudentImporter::SOURCE)->count());
    }

    public function test_clearing_an_x_marker_removes_it(): void
    {
        $year = 2026;
        (new StudentImporter())->import($this->makeSheet(8, 'Kid Eight', [3 => 'x']), $year);
        $this->assertDatabaseCount('student_monthly_markers', 1);

        (new StudentImporter())->import($this->makeSheet(8, 'Kid Eight', []), $year);

        $this->assertDatabaseCount('student_monthly_markers', 0);
    }

    public function test_a_month_with_no_column_in_the_sheet_is_left_alone(): void
    {
        // Guards the blast radius: only months present as columns can be
        // cleared, so another school year is never touched.
        $year = 2026;
        (new StudentImporter())->import($this->makeSheet(9, 'Kid Nine', [1 => 30]), $year);
        $student = Student::where('external_id', 9)->firstOrFail();

        $otherYear = Payment::create([
            'student_id' => $student->id,
            'period_year' => $year - 1,
            'period_month' => 1,
            'amount' => 30,
            'method' => 'bank',
            'source' => StudentImporter::SOURCE,
            'paid_at' => ($year - 1) . '-01-01',
        ]);

        (new StudentImporter())->import($this->makeSheet(9, 'Kid Nine', []), $year);

        $this->assertNotNull(Payment::find($otherYear->id),
            'Another school year must not be affected by clearing cells in this one.');
    }
}
