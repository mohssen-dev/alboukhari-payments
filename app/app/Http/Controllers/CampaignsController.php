<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\CampaignLauncher;
use App\Services\HaltService;
use Illuminate\Support\Facades\DB;

class CampaignsController extends Controller
{
    public function index()
    {
        // waiting_count: recipients not sent yet — shown on "send now".
        $campaigns = Campaign::withCount(['recipients as waiting_count' => fn ($q) => $q->whereIn('status', ['pending', 'sending'])])
            ->latest()
            ->paginate(30);

        return view('campaigns-index', compact('campaigns'));
    }

    public function show(Campaign $campaign)
    {
        // recipients.student eager-loaded — the blade shows $r->student?->name
        // per row, which lazy-fired one query per recipient (~300/page view).
        // withTrashed: a deleted student still has a name in the send history.
        $campaign->load(['recipients.student' => fn ($q) => $q->withTrashed()->select('id', 'name')]);
        return view('campaigns-show', compact('campaign'));
    }

    /**
     * "Send now" for a waiting campaign — made while sending was halted,
     * paused by the hourly quota, or scheduled for later.
     */
    public function send(Campaign $campaign, CampaignLauncher $launcher)
    {
        if (HaltService::isHalted()) {
            return back()->with('flash', __('campaign.send_halted'))->with('flash_type', 'error');
        }
        if (!CampaignLauncher::canSendNow($campaign)) {
            return back()->with('flash', __('campaign.send_not_allowed'))->with('flash_type', 'error');
        }

        // Keep sending if the person closes the page; a large list takes a while.
        ignore_user_abort(true);
        @set_time_limit(0);

        try {
            $result = $launcher->sendNow($campaign);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('flash', __('flash.send_error') . ' ' . $e->getMessage())->with('flash_type', 'error');
        }

        return redirect()->route('campaigns.show', $campaign)
            ->with('flash', CampaignLauncher::resultMessage($result))
            ->with('flash_type', CampaignLauncher::resultType($result));
    }

    /** Cancel a campaign that is waiting (queued or paused): nothing more is sent. */
    public function cancel(Campaign $campaign)
    {
        if (!CampaignLauncher::canCancel($campaign)) {
            return back()->with('flash', __('campaign.cancel_not_allowed'))->with('flash_type', 'error');
        }

        DB::transaction(function () use ($campaign) {
            $campaign->update(['status' => 'canceled', 'finished_at' => now()]);
            // Recipients still waiting are marked, so the list shows why they got nothing.
            $skipped = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped', 'last_error' => __('campaign.skipped_canceled')]);
            if ($skipped) {
                $campaign->increment('skipped_count', $skipped);
            }
        });

        return back()->with('flash', __('campaign.canceled_ok'))->with('flash_type', 'success');
    }
}
