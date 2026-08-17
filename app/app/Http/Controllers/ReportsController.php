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

        // Today summary — whereBetween is sargable (whereDate forces DATE()
        // around the column = full scan); count+sum merged into one query.
        $todayRange = [today(), today()->addDay()];
        $msgAgg = MessageLog::whereBetween('created_at', $todayRange)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(cost), 0) AS s')
            ->first();
        $todayMessages = (int) $msgAgg->c;
        $todayMessagesCost = (float) $msgAgg->s;
        $todayPayments = (float) Payment::whereBetween('created_at', $todayRange)
            ->whereIn('method', ['cash', 'bank'])->sum('amount');

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

        // Monthly payment totals — one GROUP BY query instead of 24 individual sums.
        $monthlyTotals = array_fill(1, 12, ['cash' => 0.0, 'bank' => 0.0, 'total' => 0.0]);
        $rows = Payment::query()
            ->where('period_year', $year)
            ->whereIn('method', ['cash', 'bank'])
            ->selectRaw('period_month, method, SUM(amount) as total')
            ->groupBy('period_month', 'method')
            ->get();
        foreach ($rows as $row) {
            $m = (int) $row->period_month;
            if ($m < 1 || $m > 12) continue;
            $monthlyTotals[$m][$row->method] = (float) $row->total;
            $monthlyTotals[$m]['total'] += (float) $row->total;
        }

        // Message cost per month — one query, bucketed in PHP for DB-portability.
        $messagesCostByMonth = array_fill(1, 12, 0.0);
        MessageLog::query()
            ->whereYear('created_at', $year)
            ->select(['created_at', 'cost'])
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$messagesCostByMonth) {
                foreach ($chunk as $row) {
                    $m = (int) $row->created_at->format('n');
                    $messagesCostByMonth[$m] += (float) $row->cost;
                }
            });

        $months = MonthNames::full();

        return view('reports', compact(
            'year', 'month', 'months',
            'todayMessages', 'todayMessagesCost', 'todayPayments',
            'monthPaidCash', 'monthPaidBank', 'monthTotal',
            'overdue', 'topDebtors', 'monthlyTotals', 'messagesCostByMonth'
        ));
    }
}
