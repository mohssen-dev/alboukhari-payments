<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\CampaignSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 0;
    public int $backoff = 60;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(CampaignSender $sender): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            Log::warning("SendCampaignJob: campaign {$this->campaignId} not found");
            return;
        }
        if (in_array($campaign->status, ['completed', 'canceled'], true)) {
            return;
        }
        $sender->sendCampaign($campaign);
    }

    public function failed(\Throwable $e): void
    {
        $campaign = Campaign::find($this->campaignId);
        if ($campaign && ! in_array($campaign->status, ['completed', 'canceled'], true)) {
            $campaign->update(['status' => 'failed']);
        }
        Log::error('SendCampaignJob failed', ['campaign_id' => $this->campaignId, 'error' => $e->getMessage()]);
    }
}
