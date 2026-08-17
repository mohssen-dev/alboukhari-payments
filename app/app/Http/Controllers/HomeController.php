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

        // whereBetween is index-sargable; whereDate(created_at) wraps the
        // column in DATE() and forces a full scan on MySQL.
        $messagesToday = MessageLog::whereBetween('created_at', [today(), today()->addDay()])->count();

        $overdueTotal = 0.0;
        $students = Student::query()
            ->where('is_hidden', false)
            ->with([
                'payments' => fn ($q) => $q->where('period_year', $year),
                'markers' => fn ($q) => $q->where('period_year', $year),
                'feeOverrides' => fn ($q) => $q->where('period_year', $year),
                'surcharges' => fn ($q) => $q->where('period_year', $year),
                'suspensions',
            ])
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

        // One GROUP BY instead of 12 separate SUM queries.
        $monthlyTotals = array_fill(1, 12, 0.0);
        $rows = Payment::query()
            ->where('period_year', $year)
            ->whereIn('method', ['cash', 'bank'])
            ->selectRaw('period_month, SUM(amount) AS total')
            ->groupBy('period_month')
            ->get();
        foreach ($rows as $row) {
            $m = (int) $row->period_month;
            if ($m >= 1 && $m <= 12) $monthlyTotals[$m] = (float) $row->total;
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
