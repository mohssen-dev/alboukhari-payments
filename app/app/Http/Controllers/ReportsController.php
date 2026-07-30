<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MessageLog;
use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $month = (int) ($request->input('month') ?: date('n'));

        // ملخص اليوم
        $todayMessages = MessageLog::whereDate('created_at', today())->count();
        $todayMessagesCost = (float) MessageLog::whereDate('created_at', today())->sum('cost');
        $todayPayments = (float) Payment::whereDate('created_at', today())->whereIn('method', ['cash', 'bank'])->sum('amount');

        // إجمالي الشهر
        $monthPaidCash = (float) Payment::where('period_year', $year)->where('period_month', $month)->where('method', 'cash')->sum('amount');
        $monthPaidBank = (float) Payment::where('period_year', $year)->where('period_month', $month)->where('method', 'bank')->sum('amount');
        $monthTotal = $monthPaidCash + $monthPaidBank;

        // Single query load, batch resolvers — avoids N+1 and 12× per-cell work.
        $allStudents = Student::canMessage()
            ->with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])
            ->get();

        $overdue = [];
        $topDebtors = [];
        foreach ($allStudents as $st) {
            $statuses = MonthStatusResolver::resolveAll($st, $year);
            $paidAll = FeeResolver::paidAllMonths($st, $year);
            $dueAll = FeeResolver::dueAllMonths($st, $year);

            // Current-month overdue
            $curStatus = $statuses[$month];
            if ($curStatus === 'unpaid' || $curStatus === 'late' || $curStatus === 'partial') {
                $overdue[] = [
                    'student' => $st,
                    'status' => $curStatus,
                    'balance' => $dueAll[$month] - $paidAll[$month],
                ];
            }

            // Full-year accumulation for top debtors
            $totalBal = 0;
            $monthsBehind = 0;
            for ($m = 1; $m <= 12; $m++) {
                $s = $statuses[$m];
                if ($s === 'unpaid' || $s === 'late' || $s === 'partial') {
                    $totalBal += ($dueAll[$m] - $paidAll[$m]);
                    if ($s === 'unpaid' || $s === 'late') $monthsBehind++;
                }
            }
            if ($monthsBehind >= 2) {
                $topDebtors[] = compact('st', 'totalBal', 'monthsBehind');
            }
        }
        usort($overdue, fn($a, $b) => $b['balance'] <=> $a['balance']);
        usort($topDebtors, fn($a, $b) => $b['totalBal'] <=> $a['totalBal']);
        $topDebtors = array_slice($topDebtors, 0, 30);
        unset($allStudents);

        // التحصيل الشهري للسنة
        $monthlyTotals = [];
        for ($m = 1; $m <= 12; $m++) {
            $cash = (float) Payment::where('period_year', $year)->where('period_month', $m)->where('method', 'cash')->sum('amount');
            $bank = (float) Payment::where('period_year', $year)->where('period_month', $m)->where('method', 'bank')->sum('amount');
            $monthlyTotals[$m] = ['cash' => $cash, 'bank' => $bank, 'total' => $cash + $bank];
        }

        // تكلفة الرسائل بالشهر
        $messagesCostByMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $messagesCostByMonth[$m] = (float) MessageLog::whereYear('created_at', $year)
                ->whereMonth('created_at', $m)
                ->sum('cost');
        }

        $months = MonthNames::full();

        return view('reports', compact(
            'year', 'month', 'months',
            'todayMessages', 'todayMessagesCost', 'todayPayments',
            'monthPaidCash', 'monthPaidBank', 'monthTotal',
            'overdue', 'topDebtors', 'monthlyTotals', 'messagesCostByMonth'
        ));
    }
}
