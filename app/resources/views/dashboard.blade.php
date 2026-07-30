@extends('layouts.app')

@section('content')
<div class="page">
    {{-- Hero --}}
    <div class="dash-hero">
        <div>
            <h2>{{ __('dashboard.welcome', ['name' => auth()->user()->name]) }}</h2>
            <p>{{ __('dashboard.title') }} · {{ $months[$month] ?? '' }} {{ $year }}</p>
        </div>
        <div class="hero-actions">
            <a href="{{ route('reports') }}" class="btn">{{ __('nav.reports') }}</a>
            @if(auth()->user()->canWrite())
                <a href="{{ route('quick-entry') }}" class="btn btn-primary">{{ __('dashboard.go_quick_entry') }}</a>
                <a href="{{ route('send.form') }}" class="btn">{{ __('dashboard.go_send') }}</a>
            @endif
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="dash-stats">
        <div class="stat-card green">
            <span class="stat-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </span>
            <div class="stat-label">{{ __('dashboard.students_active') }}</div>
            <div class="stat-value">{{ number_format($activeStudents) }}</div>
            <div class="stat-sub">{{ __('dashboard.students_subtitle') }}</div>
        </div>

        <div class="stat-card gold">
            <span class="stat-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
            <div class="stat-label">{{ __('dashboard.collected_this_month') }}</div>
            <div class="stat-value">{{ number_format($collectedThisMonth, 2) }} <small>€</small></div>
            <div class="stat-sub">{{ __('dashboard.collected_subtitle') }}</div>
        </div>

        <div class="stat-card danger">
            <span class="stat-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            <div class="stat-label">{{ __('dashboard.overdue_total') }}</div>
            <div class="stat-value">{{ number_format($overdueTotal, 2) }} <small>€</small></div>
            <div class="stat-sub">{{ __('dashboard.overdue_subtitle') }}</div>
        </div>

        <div class="stat-card sky">
            <span class="stat-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
            </span>
            <div class="stat-label">{{ __('dashboard.messages_today') }}</div>
            <div class="stat-value">{{ number_format($messagesToday) }}</div>
            <div class="stat-sub">{{ __('dashboard.messages_subtitle') }}</div>
        </div>
    </div>

    {{-- Main grid --}}
    <div class="dash-grid">
        <div class="dash-panel" style="padding:0">
            <div class="dash-panel-head">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <h3>{{ __('dashboard.view_grid') }}</h3>
            </div>
            <div style="padding:0">
                @livewire('students-grid')
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <div class="dash-panel">
                <div class="dash-panel-head">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <h3>{{ __('dashboard.monthly_breakdown') }}</h3>
                </div>
                <div class="dash-panel-body">
                    @foreach($monthlyTotals as $m => $total)
                        <div class="dash-bar-row">
                            <span class="muted">{{ $months[$m] ?? $m }}</span>
                            <span class="dash-bar {{ $total > 0 ? 'success' : '' }}">
                                <span style="width: {{ $maxMonthly > 0 ? round(($total / $maxMonthly) * 100, 1) : 0 }}%"></span>
                            </span>
                            <span class="dash-bar-amt">{{ number_format($total, 0) }} €</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="dash-panel">
                <div class="dash-panel-head">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <h3>{{ __('dashboard.recent_payments') }}</h3>
                </div>
                @if($recentPayments->isEmpty())
                    <div class="empty">
                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        {{ __('dashboard.no_recent') }}
                    </div>
                @else
                    <table class="data-table" style="font-size:12px">
                        <tbody>
                            @foreach($recentPayments as $p)
                                <tr>
                                    <td style="width:50%">
                                        <strong>{{ $p->student?->name ?? '—' }}</strong>
                                    </td>
                                    <td><span class="badge ok">{{ number_format((float) $p->amount, 2) }} €</span></td>
                                    <td class="muted" style="font-size:11px;text-align:end">{{ $p->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
