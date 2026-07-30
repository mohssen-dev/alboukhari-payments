<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Models\Payment;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Services\MonthNames;
use App\Services\MonthStatusResolver;

class HomeController extends Controller
{
    public function index()
    {
        $now    = now();
        $year   = (int) $now->year;
        $month  = (int) $now->month;

        $activeStudents = Student::query()
            ->where('is_hidden', false)
            ->whereDoesntHave('suspensions', function ($q) use ($now) {
                $q->where('starts_at', '<=', $now)
                  ->where(function ($w) use ($now) {
                      $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                  });
            })
            ->count();

        $collectedThisMonth = (float) Payment::where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('method', ['cash', 'bank'])
            ->sum('amount');

        $messagesToday = MessageLog::whereDate('created_at', today())->count();

        $overdueTotal = 0.0;
        $students = Student::query()
            ->where('is_hidden', false)
            ->with(['payments', 'markers', 'feeOverrides', 'surcharges', 'suspensions'])
            ->get();
        foreach ($students as $st) {
            $statuses = MonthStatusResolver::resolveAll($st, $year);
            $paidAll  = FeeResolver::paidAllMonths($st, $year);
            $dueAll   = FeeResolver::dueAllMonths($st, $year);
            for ($m = 1; $m <= $month; $m++) {
                $s = $statuses[$m];
                if ($s === 'unpaid' || $s === 'late' || $s === 'partial') {
                    $bal = $dueAll[$m] - $paidAll[$m];
                    if ($bal > 0) $overdueTotal += $bal;
                }
            }
        }
        unset($students);

        $monthlyTotals = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthlyTotals[$m] = (float) Payment::where('period_year', $year)
                ->where('period_month', $m)
                ->whereIn('method', ['cash', 'bank'])
                ->sum('amount');
        }
        $maxMonthly = max($monthlyTotals) ?: 1;

        $recentPayments = Payment::with('student')
            ->latest('created_at')
            ->limit(8)
            ->get();

        $months = MonthNames::full();

        return view('dashboard', compact(
            'activeStudents', 'collectedThisMonth', 'overdueTotal',
            'messagesToday', 'monthlyTotals', 'maxMonthly',
            'recentPayments', 'months', 'year', 'month'
        ));
    }
}
