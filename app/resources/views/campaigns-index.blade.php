@extends('layouts.app')

@php
    $statusPills = [
        'draft'     => 'pill-muted',
        'queued'    => 'pill-info',
        'running'   => 'pill-info',
        'paused'    => 'pill-warning',
        'completed' => 'pill-success',
        'failed'    => 'pill-danger',
        'canceled'  => 'pill-muted',
    ];
@endphp

@section('content')
<div style="max-width:1200px;margin:0 auto">
    <div class="page-header">
        <h1>📋 {{ __('nav.campaigns') }}</h1>
        <a href="{{ route('send.form') }}" class="btn btn-primary">+ {{ __('campaign.new') }}</a>
    </div>

    <div class="page-card" style="padding:0">
        <table class="students-grid" style="font-size:13px">
            <thead>
                <tr>
                    <th style="text-align:start;padding:12px 16px">#</th>
                    <th style="text-align:start">{{ __('common.type') }}</th>
                    <th>{{ __('common.period') }}</th>
                    <th>{{ __('send.recipients') }}</th>
                    <th>✓ {{ __('common.sent') }}</th>
                    <th>✗ {{ __('common.failed') }}</th>
                    <th>💵 {{ __('common.cost') }}</th>
                    <th>{{ __('columns.status') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($campaigns as $c)
                    @php
                        $cls = match($c->status) {
                            'completed' => 'pill-success',
                            'running' => 'pill-info',
                            'paused' => 'pill-warning',
                            'failed' => 'pill-danger',
                            default => 'pill-muted',
                        };
                        $statusKey = "campaigns.status.{$c->status}";
                        $statusLabel = __($statusKey);
                        if ($statusLabel === $statusKey) {
                            $statusLabel = $c->status;
                        }
                    @endphp
                    <tr>
                        <td style="text-align:start;padding:10px 16px;color:var(--color-text-muted);font-size:11px">#{{ $c->id }}</td>
                        <td style="text-align:start;font-weight:600">{{ $c->typeLabel() }}</td>
                        <td style="font-family:ui-monospace,monospace;font-size:11px">
                            {{ $c->period_year }}/{{ str_pad($c->period_month, 2, '0', STR_PAD_LEFT) }}
                        </td>
                        <td>{{ $c->total_recipients }}</td>
                        <td><span class="pill pill-success">{{ $c->sent_count }}</span></td>
                        <td>@if($c->failed_count > 0)<span class="pill pill-danger">{{ $c->failed_count }}</span>@else<span class="text-soft">0</span>@endif</td>
                        <td class="fw-700">{{ number_format($c->actual_cost, 2) }}€</td>
                        <td>
                            @php
                                $statusKey = 'campaigns.status.' . $c->status;
                                $statusLabel = __($statusKey);
                                if ($statusLabel === $statusKey) { $statusLabel = $c->status; }
                                $statusCls = $statusPills[$c->status] ?? 'pill-muted';
                            @endphp
                            <span class="pill {{ $statusCls }}">{{ $statusLabel }}</span>
                        </td>
                        <td class="text-muted fs-xs">{{ $c->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            <a href="{{ route('campaigns.show', $c) }}" class="btn btn-sm btn-soft-primary">👁️ {{ __('common.open') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" style="padding:60px 24px;text-align:center;color:var(--color-text-soft)">
                        <div style="font-size:48px;line-height:1">📭</div>
                        <div class="mt-2" style="font-weight:600;color:var(--color-text);font-size:15px">{{ __('campaign.empty_title') }}</div>
                        <div class="mt-2" style="max-width:380px;margin:8px auto 0">{{ __('campaign.empty_subtitle') }}</div>
                        <a href="{{ route('send.form') }}" class="btn btn-primary mt-4">+ {{ __('campaign.empty_cta') }}</a>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $campaigns->links() }}</div>
</div>
@endsection
