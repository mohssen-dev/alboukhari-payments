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

    /** Cancel a scheduled (queued + scheduled_at) campaign before it fires. */
    public function cancel(Campaign $campaign)
    {
        if (! $campaign->isScheduled()) {
            return back()->with('flash', __('campaign.cancel_not_allowed'))->with('flash_type', 'error');
        }
        $campaign->update(['status' => 'canceled']);
        return back()->with('flash', __('campaign.canceled_ok'))->with('flash_type', 'success');
    }
}
