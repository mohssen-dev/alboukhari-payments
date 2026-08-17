<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Family;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * يبني قائمة المستلمين لحملة بناءً على نوعها وشروطها.
 * يعالج: الاستثناءات (مخفي، محظور، مكاني، معلَّق، بدون رقم)،
 * التجميع العائلي (رسالة واحدة لعدّة إخوة).
 */
class RecipientListBuilder
{
    /**
     * @return array{recipients: array, skipped: array, stats: array}
     */
    public function build(Campaign $campaign, ?array $specificStudentIds = null): array
    {
        $year = $campaign->period_year ?? (int) date('Y');
        $month = $campaign->period_month ?? (int) date('n');

        // feeOverrides + surcharges MUST be eager-loaded: dueAllMonths() only
        // sees them via relationLoaded(), so omitting them makes campaign
        // targeting compute dues without overrides/surcharges (wrong lists)
        // AND triggers 2 lazy queries per student x month in balance_above.
        $query = Student::query()->with([
            'family.students', 'payments', 'markers', 'suspensions',
            'feeOverrides', 'surcharges',
        ]);

        if ($specificStudentIds !== null) {
            $query->whereIn('id', $specificStudentIds);
        }

        $students = $query->get();

        $recipients = [];
        $skipped = [];
        $seenFamilies = [];

        foreach ($students as $student) {
            // فلتر الاستثناءات الأساسية
            $skipReason = $student->skipReason();
            if ($skipReason !== null) {
                $skipped[] = [
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'reason' => $skipReason,
                ];
                continue;
            }

            // فلتر استثناء send_all
            if ($campaign->type === 'send_all') {
                if (!$student->included_in_send_all || $student->excluded_from_send_all) {
                    $skipped[] = [
                        'student_id' => $student->id,
                        'name' => $student->name,
                        'reason' => 'مستثنى من الإرسال الجماعي',
                    ];
                    continue;
                }
            }

            // فلتر حسب النوع
            if (!$this->matchesType($student, $campaign, $year, $month)) {
                $skipped[] = [
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'reason' => 'لا يطابق شرط الحملة',
                ];
                continue;
            }

            // التجميع العائلي
            if ($campaign->group_by_family && $student->family_id) {
                if (isset($seenFamilies[$student->family_id])) {
                    // أُضيف ضمن إخوته سابقاً
                    continue;
                }
                $seenFamilies[$student->family_id] = true;
                $family = $student->family;
                $body = TemplateRenderer::renderForFamily(
                    $campaign->body_template,
                    $family,
                    $year,
                    $month
                );
                $count = \App\Support\SmsCounter::count($body, $this->forceAscii());
                $recipients[] = [
                    'student_id' => $student->id,
                    'family_id' => $family->id,
                    'name' => 'عائلة ' . $student->name . ' (' . $family->students->count() . ')',
                    'phone' => $family->phone_primary_e164 ?: $student->phone_primary_e164,
                    'body' => $body,
                    'segments' => $count['segments'],
                    'idempotency_key' => $this->makeKey($campaign->id, 'fam-' . $family->id, $year, $month),
                ];
            } else {
                $body = TemplateRenderer::renderForStudent(
                    $campaign->body_template,
                    $student,
                    $year,
                    $month
                );
                $count = \App\Support\SmsCounter::count($body, $this->forceAscii());
                $recipients[] = [
                    'student_id' => $student->id,
                    'family_id' => $student->family_id,
                    'name' => $student->name,
                    'phone' => $student->phone_primary_e164,
                    'body' => $body,
                    'segments' => $count['segments'],
                    'idempotency_key' => $this->makeKey($campaign->id, 'stu-' . $student->id, $year, $month),
                ];
            }
        }

        return [
            'recipients' => $recipients,
            'skipped' => $skipped,
            'stats' => [
                'total_recipients' => count($recipients),
                'total_segments' => collect($recipients)->sum('segments'),
                'total_skipped' => count($skipped),
            ],
        ];
    }

    private function matchesType(Student $student, Campaign $campaign, int $year, int $month): bool
    {
        switch ($campaign->type) {
            case 'send_all':
                return true;

            case 'unpaid_by_month':
                $status = MonthStatusResolver::resolve($student, $year, $month);
                return $status === 'unpaid';

            case 'late_mid_month':
                $status = MonthStatusResolver::resolve($student, $year, $month);
                return $status === 'late';

            case 'paid_less_than':
                $paid = FeeResolver::paidAmount($student, $year, $month);
                $threshold = (float) ($campaign->threshold_amount ?? 0);
                return $paid < $threshold;

            case 'balance_above':
                // Accumulated balance across the year — batch resolvers run
                // once per student instead of resolve()x12 (which recomputes
                // all 12 months on every call).
                $statuses = MonthStatusResolver::resolveAll($student, $year);
                $dueAll   = FeeResolver::dueAllMonths($student, $year);
                $paidAll  = FeeResolver::paidAllMonths($student, $year);
                $totalBalance = 0;
                for ($m = 1; $m <= 12; $m++) {
                    if (in_array($statuses[$m], ['unpaid', 'late', 'partial'], true)) {
                        $totalBalance += ($dueAll[$m] - $paidAll[$m]);
                    }
                }
                $threshold = (float) ($campaign->threshold_amount ?? 0);
                return $totalBalance > $threshold;

            case 'first_friday':
            case 'mid_month_auto':
                $status = MonthStatusResolver::resolve($student, $year, $month);
                if ($campaign->type === 'first_friday') return $status === 'unpaid';
                return $status === 'late';

            case 'specific_students':
                return true; // الفلترة بـ $specificStudentIds في build()

            default:
                return false;
        }
    }

    private function forceAscii(): bool
    {
        return \App\Models\Setting::get('force_ascii', '1') === '1';
    }

    private function makeKey(?int $campaignId, string $part, int $year, int $month): string
    {
        $cid = $campaignId ?? 'preview-' . uniqid();
        return hash('sha1', "$cid|$part|$year|$month");
    }
}
