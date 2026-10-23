<?php

namespace App\Http\Controllers;

class ActivityLogController extends Controller
{
    /** The logs page — App\Livewire\LogsPage does the work. */
    public function index()
    {
        return view('activity.index');
    }
}
