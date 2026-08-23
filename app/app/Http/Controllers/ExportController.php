<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function receipt(Payment $payment)
    {
        return view('exports.receipt', [
            'payment' => $payment->load('student.family'),
            'months'  => MonthNames::full(),
        ]);
    }

    public function statement(Student $student, Request $request)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        // markers included — without it legacy_late months print as 'unpaid'.
        $student->load(['payments', 'markers', 'feeOverrides', 'surcharges', 'family', 'suspensions']);

        // The statement is what a PARENT receives, so its balance must equal
        // the balance the office sees in the app. Raw balance() counted every
        // month of the year, which billed months that are not due yet (a row
        // could read status "not_due" and balance 30.00 side by side) and
        // inflated the total — a student the app showed at €60 printed €240.
        // Mirror the UI: only months that are actually owed to date count.
        $statuses = MonthStatusResolver::resolveAll($student, $year);
        $dueAll   = FeeResolver::dueAllMonths($student, $year);
        $paidAll  = FeeResolver::paidAllMonths($student, $year);
        $nowYm    = ((int) date('Y') * 12) + (int) date('n');

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $isFuture = (($year * 12) + $m) > $nowYm;
            $owed = (!$isFuture && in_array($statuses[$m], ['unpaid', 'late', 'partial'], true))
                ? max(0.0, $dueAll[$m] - $paidAll[$m])
                : 0.0;

            $rows[$m] = [
                'due'     => $dueAll[$m],
                'paid'    => $paidAll[$m],
                'balance' => $owed,
                'status'  => $statuses[$m],
            ];
        }

        return view('exports.statement', [
            'student' => $student,
            'rows'    => $rows,
            'year'    => $year,
            'months'  => MonthNames::full(),
        ]);
    }

    public function students(Request $request): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Students');

        $headers = ['ID', 'External ID', 'Name', 'Family', 'Phone', 'Allow SMS', 'Hidden', 'Blocked', 'In-person', 'Default fee', 'Enrolled', 'Withdrawn'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $students = Student::with('family')->orderBy('name')->get();
        $row = 2;
        foreach ($students as $s) {
            $sheet->fromArray([
                $s->id,
                $s->external_id,
                $s->name,
                $s->family?->guardian_name,
                $s->phone_primary_e164 ?: $s->phone_primary_raw,
                $s->allow_sms ? 'yes' : 'no',
                $s->is_hidden ? 'yes' : 'no',
                $s->is_blocked_messages ? 'yes' : 'no',
                $s->is_in_person ? 'yes' : 'no',
                $s->default_fee_amount,
                $s->enrolled_at?->format('Y-m-d'),
                $s->withdrawn_at?->format('Y-m-d'),
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, 'students-'.now()->format('Y-m-d').'.xlsx');
    }

    public function payments(Request $request): StreamedResponse
    {
        $year  = (int) ($request->input('year') ?: date('Y'));
        $month = (int) ($request->input('month') ?: 0);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payments');

        $sheet->fromArray(['Date', 'Student', 'Period', 'Amount (€)', 'Method', 'Note'], null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $q = Payment::with('student')->whereIn('method', ['cash', 'bank']);
        if ($year)  $q->where('period_year', $year);
        if ($month) $q->where('period_month', $month);
        $payments = $q->orderBy('paid_at', 'desc')->get();

        $row = 2;
        $total = 0.0;
        foreach ($payments as $p) {
            $sheet->fromArray([
                optional($p->paid_at)->format('Y-m-d'),
                $p->student?->name,
                sprintf('%04d-%02d', $p->period_year, $p->period_month),
                (float) $p->amount,
                $p->method,
                $p->note,
            ], null, "A{$row}");
            $total += (float) $p->amount;
            $row++;
        }
        $sheet->setCellValue("C{$row}", 'TOTAL');
        $sheet->setCellValue("D{$row}", $total);
        $sheet->getStyle("C{$row}:D{$row}")->getFont()->setBold(true);

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $name = 'payments';
        if ($year) $name .= "-{$year}";
        if ($month) $name .= '-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return $this->streamXlsx($spreadsheet, $name.'.xlsx');
    }

    public function monthly(Request $request): StreamedResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Monthly {$year}");

        $months = MonthNames::full();
        $sheet->fromArray(['Student', ...array_values($months), 'Total balance'], null, 'A1');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);

        // 'markers' is needed for legacy_late to resolve correctly.
        $students = Student::with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])
            ->orderBy('name')
            ->get();

        // Same rule as the app UI and the statement: only months owed to date.
        $nowYm = ((int) date('Y') * 12) + (int) date('n');

        $row = 2;
        foreach ($students as $st) {
            $statuses = MonthStatusResolver::resolveAll($st, $year);
            $dueAll   = FeeResolver::dueAllMonths($st, $year);
            $paidAll  = FeeResolver::paidAllMonths($st, $year);

            $line = [$st->name];
            $totalBal = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $isFuture = (($year * 12) + $m) > $nowYm;
                $bal = (!$isFuture && in_array($statuses[$m], ['unpaid', 'late', 'partial'], true))
                    ? max(0.0, $dueAll[$m] - $paidAll[$m])
                    : 0.0;
                $line[] = $bal;
                $totalBal += $bal;
            }
            $line[] = $totalBal;
            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, "monthly-{$year}.xlsx");
    }

    private function streamXlsx(Spreadsheet $sheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($sheet) {
            $writer = new Xlsx($sheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
