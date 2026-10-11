{{--
    "Send now" / "Cancel" for a campaign that is waiting — made while sending
    was halted, paused by the hourly quota, or scheduled. Used on the
    campaigns list and on a campaign's page (CampaignsController::send/cancel).
--}}
@php
    $canWrite = (bool) auth()->user()?->canWrite();
    $halted = \App\Services\HaltService::isHalted();
    $canSend = $canWrite && \App\Services\CampaignLauncher::canSendNow($campaign);
    $canCancel = $canWrite && \App\Services\CampaignLauncher::canCancel($campaign);
    $waiting = $campaign->waiting_count ?? $campaign->recipients()->whereIn('status', ['pending', 'sending'])->count();
    $sendConfirm = $campaign->status === 'queued'
        ? __('campaign.send_now_confirm', ['id' => $campaign->id])
        : __('campaign.resume_confirm', ['id' => $campaign->id, 'count' => $waiting]);
@endphp
@if ($canSend)
    <form method="POST" action="{{ route('campaigns.send', $campaign) }}"
          onsubmit="if (!confirm({{ \Illuminate\Support\Js::from($sendConfirm) }})) return false; const b = this.querySelector('button'); b.disabled = true; b.textContent = {{ \Illuminate\Support\Js::from('⏳ ' . __('send.launching')) }};">
        @csrf
        <button type="submit" class="btn btn-sm btn-success" @disabled($halted)
                title="{{ $halted ? __('send.halted_inline') : __('campaign.send_now_title') }}">
            🚀 {{ $campaign->status === 'queued' ? __('campaign.send_now') : __('campaign.resume_now') }}
        </button>
    </form>
@endif
@if ($canCancel)
    <form method="POST" action="{{ route('campaigns.cancel', $campaign) }}"
          onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('campaign.cancel_confirm', ['id' => $campaign->id])) }})">
        @csrf
        <button type="submit" class="btn btn-sm btn-soft-danger">✕ {{ __('campaign.cancel_scheduled') }}</button>
    </form>
@endif
