<?php

namespace App\Http\Controllers;

use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index()
    {
        $activities = Activity::with('causer')
            ->latest()
            ->paginate(50);

        return view('activity.index', compact('activities'));
    }
}
