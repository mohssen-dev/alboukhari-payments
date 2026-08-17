<?php

namespace App\Http\Controllers;

use App\Models\Campaign;

class CampaignsController extends Controller
{
    public function index()
    {
        $campaigns = Campaign::latest()->paginate(30);
        return view('campaigns-index', compact('campaigns'));
    }

    public function show(Campaign $campaign)
    {
        // recipients.student eager-loaded — the blade shows $r->student?->name
        // per row, which lazy-fired one query per recipient (~300/page view).
        $campaign->load(['recipients.student:id,name']);
        return view('campaigns-show', compact('campaign'));
    }
}
