@extends('layouts.app')

@php
    $campaignStatusPills = [
        'draft'     => 'pill-muted',
        'queued'    => 'pill-info',
        'running'   => 'pill-info',
        'paused'    => 'pill-warning',
        'completed' => 'pill-success',
        'failed'    => 'pill-danger',
        'canceled'  => 'pill-muted',
    ];
    $recipientStatusPills = [
        'pending' => 'pill-muted',
        'sending' => 'pill-info',
        'sent'    => 'pill-success',
        'failed'  => 'pill-danger',
        'skipped' => 'pill-warning',
    ];
@endphp

@section('content')
<div style="max-width:1200px;margin:0 auto">
    <div class="page-header">
        <h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>📨 #{{ $campaign->id }} — {{ $campaign->typeLabel() }}</span>
            @include('partials.campaign-channel', ['campaign' => $campaign])
            @php
                // 'campaigns.status.*' — the list's keys. This page looked up
                // 'campaign.status.*', which does not exist, so it showed "queued".
                $statusKey = 'campaigns.status.' . $campaign->status;
                $statusLabel = __($statusKey);
                if ($statusLabel === $statusKey) { $statusLabel = $campaign->status; }
                $statusCls = $campaignStatusPills[$campaign->status] ?? 'pill-muted';
            @endphp
            <span class="pill {{ $statusCls }}" style="font-size:12px">{{ $statusLabel }}</span>
            @if ($campaign->isScheduled())
                <span class="fs-xs text-muted">⏰ {{ $campaign->scheduled_at->format('Y-m-d H:i') }}</span>
            @endif
        </h1>
        <div class="campaign-actions">
            @include('partials.campaign-actions', ['campaign' => $campaign])
            <a href="{{ route('campaigns.index') }}" class="btn">← {{ __('campaign.back_to_log') }}</a>
        </div>
    </div>

    @if (in_array($campaign->status, ['queued', 'paused'], true) && !$campaign->isScheduled())
        {{-- Why it is waiting, in words — "queued" alone explained nothing. --}}
        <div class="page-card campaign-waiting-note">
            @if ($campaign->status === 'paused')
                ⏸️ {{ __('campaign.why_paused') }}
            @elseif (\App\Services\HaltService::isHalted())
                ⛔ {{ __('campaign.why_waiting_halted') }}
            @else
                ⏳ {{ __('campaign.why_waiting') }}
            @endif
        </div>
    @endif

    <div class="kpi-grid">
        <div class="kpi info">
            <div class="label">👥 {{ __('send.recipients') }}</div>
            <div class="value">{{ $campaign->total_recipients }}</div>
        </div>
        <div class="kpi success">
            <div class="label">✓ {{ __('common.sent') }}</div>
            <div class="value">{{ $campaign->sent_count }}</div>
        </div>
        <div class="kpi danger">
            <div class="label">✗ {{ __('common.failed') }}</div>
            <div class="value">{{ $campaign->failed_count }}</div>
        </div>
        <div class="kpi warning">
            <div class="label">💵 {{ __('common.cost') }}</div>
            <div class="value">{{ number_format($campaign->actual_cost, 2) }}€</div>
        </div>
    </div>

    <div class="page-card">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;font-size:12px">
            <div><span class="text-muted">{{ __('common.period') }}:</span> <strong>{{ $campaign->period_year }}/{{ str_pad($campaign->period_month, 2, '0', STR_PAD_LEFT) }}</strong></div>
            <div><span class="text-muted">{{ __('campaign.tag') }}:</span> <code>{{ $campaign->tag ?: '—' }}</code></div>
            <div><span class="text-muted">{{ __('campaign.started') }}:</span> {{ $campaign->started_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span class="text-muted">{{ __('campaign.finished') }}:</span> {{ $campaign->finished_at?->format('Y-m-d H:i') ?? '—' }}</div>
        </div>

        <h3 class="mt-4">{{ __('campaign.original_message') }}</h3>
        <div dir="auto" style="background:var(--color-warning-soft);padding:12px;border-radius:var(--radius);white-space:pre-wrap;font-size:13px;line-height:1.6;text-align:start">{{ $campaign->body_template }}</div>
    </div>

    <div class="page-card" style="padding:0">
        <h3 style="margin:0;padding:16px 20px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center">
            <span>{{ __('campaign.recipients_section') }}</span>
            <span class="pill pill-muted">{{ $campaign->recipients->count() }}</span>
        </h3>
        <table class="students-grid" style="font-size:12px">
            <thead>
                <tr>
                    <th style="padding:8px">#</th>
                    <th style="text-align:start">{{ __('campaign.recipient') }}</th>
                    <th>{{ __('columns.phone') }}</th>
                    <th>📲 SMS</th>
                    <th>{{ __('columns.status') }}</th>
                    <th>{{ __('common.cost') }}</th>
                    <th style="text-align:start">{{ __('campaign.last_error') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($campaign->recipients as $r)
                    <tr>
                        <td style="color:var(--color-text-muted)">{{ $r->id }}</td>
                        <td style="text-align:start;font-weight:600">{{ $r->student?->name ?? '—' }}</td>
                        <td style="font-family:ui-monospace,monospace;font-size:11px">
                            @if ($r->phone_e164)
                                <button type="button" class="copyable" title="{{ __('copy.hint') }}" aria-label="{{ __('copy.hint') }}: {{ $r->phone_e164 }}">{{ $r->phone_e164 }}</button>
                            @else
                                <span class="text-soft">—</span>
                            @endif
                        </td>
                        <td>{{ $r->segments }}</td>
                        <td>
                            @php
                                $rKey = 'campaign.recipient_status.' . $r->status;
                                $rLabel = __($rKey);
                                if ($rLabel === $rKey) { $rLabel = $r->status; }
                                $rCls = $recipientStatusPills[$r->status] ?? 'pill-muted';
                            @endphp
                            <span class="pill {{ $rCls }}">{{ $rLabel }}</span>
                        </td>
                        <td>{{ number_format($r->cost, 4) }}€</td>
                        <td style="text-align:start;font-size:11px;color:var(--color-danger);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $r->last_error }}">{{ \Illuminate\Support\Str::limit($r->last_error, 50) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:48px 24px;text-align:center;color:var(--color-text-soft)">
                        <div style="font-size:42px;line-height:1">📭</div>
                        <div class="mt-2">{{ __('campaign.empty_recipients') }}</div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
