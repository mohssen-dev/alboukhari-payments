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
        $student->load(['payments', 'feeOverrides', 'surcharges', 'family', 'suspensions']);

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[$m] = [
                'due'     => FeeResolver::dueAmount($student, $year, $m),
                'paid'    => FeeResolver::paidAmount($student, $year, $m),
                'balance' => FeeResolver::balance($student, $year, $m),
                'status'  => MonthStatusResolver::resolve($student, $year, $m),
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

        $students = Student::with(['payments', 'feeOverrides', 'surcharges', 'suspensions'])
            ->orderBy('name')
            ->get();

        $row = 2;
        foreach ($students as $st) {
            $line = [$st->name];
            $totalBal = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $bal = FeeResolver::balance($st, $year, $m);
                $line[] = $bal;
                if ($bal > 0) $totalBal += $bal;
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
