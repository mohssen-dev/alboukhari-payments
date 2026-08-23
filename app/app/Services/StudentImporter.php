<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentMonthlyMarker;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * يستورد ملف Excel بالبنية القديمة (Sheet1) إلى الجداول الجديدة:
 *  - يُنشئ Students
 *  - يكتشف العائلات تلقائياً عبر الرقم المشترك
 *  - يحوّل خلايا الأشهر إلى Payments و Markers
 *
 * يعمل بشكل Idempotent: إذا أعدت الاستيراد، يُحدّث ولا يكرّر.
 */
class StudentImporter
{
    /** Marks payment rows this importer owns, so re-import never deletes manual entries. */
    public const SOURCE = 'excel_import';

    private const MONTHS = [
        'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4,
        'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
        'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
    ];

    public array $stats = [
        'students_created' => 0,
        'students_updated' => 0,
        'families_created' => 0,
        'payments_created' => 0,
        'markers_created' => 0,
        'phones_invalid' => 0,
        'skipped_rows' => 0,
        'enrolled_at_set' => 0,
        'payments_replaced' => 0,
    ];

    public function import(string $filePath, ?int $yearForMonths = null): array
    {
        $yearForMonths = $yearForMonths ?? (int) date('Y');

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('Sheet1') ?? $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false); // 0-indexed columns

        if (count($data) < 2) {
            throw new \RuntimeException(__('error.no_data_rows'));
        }

        $headerRow = $data[0];
        $headers = array_map(fn($h) => trim((string) $h), $headerRow);

        $idx = [
            'id' => array_search('id', $headers, true),
            'Naam' => array_search('Naam', $headers, true),
            'Telefoon' => array_search('Telefoon', $headers, true),
            'sms' => array_search('sms', $headers, true),
            'whatsapp' => array_search('whatsapp', $headers, true),
            'send_all' => array_search('send_all', $headers, true),
            'Tweede telefoonnummer' => array_search('Tweede telefoonnummer', $headers, true),
        ];

        $monthCols = [];
        foreach (self::MONTHS as $name => $num) {
            $i = array_search($name, $headers, true);
            if ($i !== false) {
                $monthCols[$num] = $i;
            }
        }

        DB::beginTransaction();
        try {
            for ($r = 1; $r < count($data); $r++) {
                $row = $data[$r];
                if (!$this->rowHasData($row, $idx)) {
                    $this->stats['skipped_rows']++;
                    continue;
                }

                $externalId = $this->cellInt($row, $idx['id']);
                $name = trim((string) $this->cell($row, $idx['Naam']));
                if (!$name) { $this->stats['skipped_rows']++; continue; }

                $phonePrimaryRaw = trim((string) $this->cell($row, $idx['Telefoon']));
                $phoneSecondaryRaw = trim((string) $this->cell($row, $idx['Tweede telefoonnummer']));

                $phonePrimaryE164 = PhoneNormalizer::normalize($phonePrimaryRaw);
                $phoneSecondaryE164 = PhoneNormalizer::normalize($phoneSecondaryRaw);

                if ($phonePrimaryRaw && !PhoneNormalizer::isValid($phonePrimaryE164)) {
                    $this->stats['phones_invalid']++;
                    $phonePrimaryE164 = null;
                }

                // كشف العائلة عبر الرقم
                $family = $this->ensureFamily($phonePrimaryE164);

                $student = Student::updateOrCreate(
                    ['external_id' => $externalId],
                    [
                        'name' => $name,
                        'family_id' => $family?->id,
                        'phone_primary_raw' => $phonePrimaryRaw ?: null,
                        'phone_primary_e164' => $phonePrimaryE164,
                        'phone_secondary_raw' => $phoneSecondaryRaw ?: null,
                        'phone_secondary_e164' => $phoneSecondaryE164,
                        'allow_sms' => $this->cellBool($row, $idx['sms']),
                        'allow_whatsapp' => $this->cellBool($row, $idx['whatsapp']),
                        'included_in_send_all' => $this->cellBool($row, $idx['send_all']),
                    ]
                );

                if ($student->wasRecentlyCreated) {
                    $this->stats['students_created']++;
                } else {
                    $this->stats['students_updated']++;
                }

                $firstActivityMonth = $this->importMonths($student, $row, $monthCols, $yearForMonths);

                // Auto-set enrolled_at from the first month with real activity,
                // but never overwrite a value the admin explicitly set.
                if ($firstActivityMonth !== null && $student->enrolled_at === null) {
                    $student->enrolled_at = sprintf('%04d-%02d-01', $yearForMonths, $firstActivityMonth);
                    $student->save();
                    $this->stats['enrolled_at_set']++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->stats;
    }

    private function ensureFamily(?string $phoneE164): ?Family
    {
        if (!$phoneE164) return null;

        $family = Family::where('phone_primary_e164', $phoneE164)->first();
        if (!$family) {
            $family = Family::create([
                'phone_primary_e164' => $phoneE164,
                'preferred_language' => 'nl',
            ]);
            $this->stats['families_created']++;
        }
        return $family;
    }

    /**
     * Returns the first month (1-12) that had any activity — payment or marker —
     * or null if the row had none. Used to infer enrolled_at.
     */
    private function importMonths(Student $student, array $row, array $monthCols, int $year): ?int
    {
        $firstActivity = null;
        ksort($monthCols);

        foreach ($monthCols as $monthNum => $colIdx) {
            $raw = $this->cell($row, $colIdx);
            if ($raw === null || $raw === '') continue;

            $strVal = trim((string) $raw);
            $lower = strtolower($strVal);

            // قيمة "X" = متأخر يدوياً
            if ($lower === 'x') {
                StudentMonthlyMarker::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'period_year' => $year,
                        'period_month' => $monthNum,
                        'type' => 'legacy_late',
                    ],
                    []
                );
                $this->stats['markers_created']++;
                $firstActivity = $firstActivity ?? $monthNum;
                continue;
            }

            // قيمة عددية
            if (is_numeric($strVal)) {
                $amount = (float) $strVal;
                $method = $amount == 0 ? 'legacy_zero' : 'bank';

                // Re-import replaces ONLY rows this importer created.
                //
                // This used to delete every legacy_zero/bank payment for the
                // month, which matched bank payments an office worker had
                // entered in the app — so re-importing the sheet silently
                // destroyed real recorded money. Scoping the delete to
                // source='excel_import' keeps the import idempotent while
                // leaving manual entries untouched.
                $removed = Payment::where('student_id', $student->id)
                    ->where('period_year', $year)
                    ->where('period_month', $monthNum)
                    ->where('source', self::SOURCE)
                    ->delete();
                $this->stats['payments_replaced'] += $removed;

                Payment::create([
                    'student_id' => $student->id,
                    'period_year' => $year,
                    'period_month' => $monthNum,
                    'amount' => $amount,
                    'paid_at' => sprintf('%04d-%02d-01', $year, $monthNum),
                    'method' => $method,
                    'note' => 'مستورد من Excel',
                    'source' => self::SOURCE,
                ]);
                $this->stats['payments_created']++;
                $firstActivity = $firstActivity ?? $monthNum;
            }
        }

        return $firstActivity;
    }

    private function rowHasData(array $row, array $idx): bool
    {
        return $this->cellInt($row, $idx['id']) > 0
            || trim((string) $this->cell($row, $idx['Naam'])) !== '';
    }

    private function cell(array $row, int|false $idx): mixed
    {
        if ($idx === false) return null;
        return $row[$idx] ?? null;
    }

    private function cellInt(array $row, int|false $idx): int
    {
        $v = $this->cell($row, $idx);
        return is_numeric($v) ? (int) $v : 0;
    }

    private function cellBool(array $row, int|false $idx): bool
    {
        $v = $this->cell($row, $idx);
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['true', '1', 'yes', 'y'], true);
    }
}
