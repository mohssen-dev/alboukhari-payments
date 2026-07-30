<?php

namespace App\Http\Controllers;

use App\Services\HaltService;
use Illuminate\Http\Request;

class SendController extends Controller
{
    public function form()
    {
        return view('send');
    }

    public function halt(Request $request)
    {
        if ($request->input('action') === 'resume') {
            HaltService::resume();
            return back()->with('flash', __('flash.send_resumed'));
        }
        HaltService::halt();
        return back()->with('flash', __('flash.send_halted'));
    }
}
