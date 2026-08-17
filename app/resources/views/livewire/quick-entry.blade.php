<div style="max-width:760px;margin:0 auto">
    <div class="page-header">
        <h1>⚡ {{ __('actions.quick_entry') }}</h1>
        <a href="{{ route('home') }}" class="btn">← {{ __('quickentry.back_to_grid') }}</a>
    </div>

    <div class="kpi-grid">
        <div class="kpi info">
            <div class="label">✅ {{ __('quickentry.saved_this_session') }}</div>
            <div class="value">{{ $sessionCount }}</div>
        </div>
        <div class="kpi success">
            <div class="label">💵 {{ __('quickentry.session_total') }}</div>
            <div class="value">{{ number_format($sessionTotal, 2) }} €</div>
        </div>
        <div class="kpi warning">
            <div class="label">📅 {{ __('quickentry.period') }}</div>
            <div class="value" style="font-size:20px">{{ $months[$month] }} {{ $year }}</div>
        </div>
    </div>

    <div class="page-card">
        <div class="form-row cols-2">
            <div class="form-group">
                <label>{{ __('filters.year') }}</label>
                <select class="form-select" wire:model.live="year">
                    @for ($y = date('Y') + 1; $y >= 2020; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div class="form-group">
                <label>{{ __('filters.month') }}</label>
                <select class="form-select" wire:model.live="month">
                    @foreach ($months as $num => $name)
                        <option value="{{ $num }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (!$selectedStudent)
            <div
                x-data="{
                    active: 0,
                    results() { return this.$root.querySelectorAll('.qe-result'); },
                    move(delta) {
                        const total = this.results().length;
                        if (total === 0) return;
                        this.active = (this.active + delta + total) % total;
                        const el = this.results()[this.active];
                        if (el) el.scrollIntoView({ block: 'nearest' });
                    },
                    pick() {
                        const el = this.results()[this.active];
                        if (el) el.click();
                    },
                }"
                x-init="$nextTick(() => $refs.search.focus())"
                @focus-search.window="$nextTick(() => $refs.search.focus()); active = 0"
            >
                <label for="qe-search">🔍 {{ __('quickentry.search_label') }}</label>
                <div class="qe-search-wrap">
                    <input
                        id="qe-search"
                        x-ref="search"
                        type="text"
                        class="form-input"
                        wire:model.live.debounce.150ms="search"
                        placeholder="{{ __('quickentry.search_placeholder') }}"
                        style="font-size:18px;padding:14px"
                        autocomplete="off"
                        aria-label="{{ __('quickentry.search_label') }}"
                        aria-describedby="qe-search-hint"
                        @keydown.arrow-down.prevent="move(1)"
                        @keydown.arrow-up.prevent="move(-1)"
                        @keydown.enter.prevent="pick()"
                        @input="active = 0"
                        autofocus
                    >
                    <span class="qe-search-spinner" wire:loading.delay wire:target="search" aria-hidden="true"></span>
                </div>

                @if (count($candidates) > 0)
                    <div class="qe-results" role="listbox" aria-label="{{ __('quickentry.search_label') }}">
                        @foreach ($candidates as $i => $c)
                            @php
                                $bal = \App\Services\FeeResolver::balance($c, $year, $month);
                                $status = \App\Services\MonthStatusResolver::resolve($c, $year, $month);
                            @endphp
                            <div
                                class="qe-result"
                                data-idx="{{ $i }}"
                                role="option"
                                tabindex="-1"
                                x-bind:class="active === {{ $i }} ? 'is-active' : ''"
                                x-bind:aria-selected="active === {{ $i }} ? 'true' : 'false'"
                                @mouseenter="active = {{ $i }}"
                                wire:click="selectStudent({{ $c->id }})"
                            >
                                <div>
                                    <strong>{{ $c->name }}</strong>
                                    <div class="fs-xs text-muted">{{ $c->phone_primary_e164 }} • ID: {{ $c->external_id ?? $c->id }}</div>
                                </div>
                                <div style="text-align:end">
                                    <span class="pill pill-muted">{{ \App\Services\MonthStatusResolver::label($status) }}</span>
                                    @if ($bal > 0)
                                        <div class="fw-700 text-danger mt-2">{{ number_format($bal, 0) }} €</div>
                                    @else
                                        <div class="text-success mt-2">✓ {{ __('quickentry.paid_pill') }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="fs-xs text-soft mt-2" style="text-align:center" id="qe-search-hint">{{ __('quickentry.nav_hint') }}</p>
                @elseif (strlen($search) >= 2)
                    <p class="text-soft mt-3" style="text-align:center;padding:20px" wire:loading.remove wire:target="search">{{ __('common.no_results') }}</p>
                @else
                    <div class="qe-hint" id="qe-search-hint">{{ __('quickentry.search_hint') }}</div>
                @endif
            </div>
        @else
            <div
                x-data
                x-init="$nextTick(() => document.getElementById('quick-amount').focus())"
                @keydown.window.escape="$wire.set('selectedStudentId', null)"
                {{-- n/b hotkeys must not fire while typing in an input/textarea
                     (they were swallowing those letters in the Note field). --}}
                @keydown.window="
                    if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
                    if ($event.key === 'n') { $event.preventDefault(); $wire.setMethod('cash'); }
                    if ($event.key === 'b') { $event.preventDefault(); $wire.setMethod('bank'); }
                "
                @keydown.window.ctrl.enter.prevent="if (!window.__qeSaving) { window.__qeSaving = true; $wire.save().finally(() => window.__qeSaving = false); }"
            >
                <div style="background:var(--color-warning-soft);padding:14px;border-radius:var(--radius);margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <strong style="font-size:18px">{{ $selectedStudent->name }}</strong>
                        <div class="fs-xs text-muted">{{ $selectedStudent->phone_primary_e164 }}</div>
                    </div>
                    <button class="btn btn-sm" wire:click="$set('selectedStudentId', null)">↶ {{ __('quickentry.change_student') }}</button>
                </div>

                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="label">{{ __('payment.due') }}</div>
                        <div class="value">{{ number_format($dueAmount, 2) }} €</div>
                    </div>
                    <div class="summary-item">
                        <div class="label">{{ __('quickentry.paid') }}</div>
                        <div class="value">{{ number_format($paidSoFar, 2) }} €</div>
                    </div>
                </div>

                <div class="form-group">
                    <label>{{ __('payment.amount') }}</label>
                    <input
                        id="quick-amount"
                        type="number"
                        step="0.01"
                        class="form-input"
                        wire:model="amount"
                        style="font-size:26px;font-weight:700;text-align:center;padding:14px"
                    >
                </div>

                <div class="form-group">
                    <label>{{ __('payment.method') }} <small class="text-muted">({{ __('quickentry.method_hint') }})</small></label>
                    <div class="method-toggle">
                        <button type="button" class="cash {{ $method === 'cash' ? 'active' : '' }}" wire:click="setMethod('cash')">💵 {{ __('payment.method_cash') }}</button>
                        <button type="button" class="bank {{ $method === 'bank' ? 'active' : '' }}" wire:click="setMethod('bank')">🏦 {{ __('payment.method_bank') }}</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>{{ __('payment.note') }}</label>
                    <input type="text" class="form-input" wire:model="note">
                </div>

                <button class="btn btn-primary btn-lg" wire:click="save" style="width:100%">
                    💾 {{ __('payment.save_and_next') }} <span class="text-soft" style="margin-inline-start:10px;font-size:11px">Ctrl+Enter</span>
                </button>
            </div>
        @endif
    </div>

    @if (count($sessionLog) > 0)
        <div class="page-card" style="padding:0">
            <h3 style="margin:0;padding:14px 18px;border-bottom:1px solid var(--color-border)">📋 {{ __('quickentry.session_log_title') }}</h3>
            <table class="students-grid" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="text-align:start;padding:8px 16px">⏰</th>
                        <th style="text-align:start">{{ __('quickentry.student') }}</th>
                        <th>{{ __('payment.method') }}</th>
                        <th style="text-align:end">{{ __('payment.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessionLog as $entry)
                        <tr>
                            <td style="padding:6px 16px;color:var(--color-text-muted);font-family:ui-monospace,monospace;font-size:11px">{{ $entry['time'] }}</td>
                            <td style="text-align:start;padding:6px 16px;font-weight:600">{{ $entry['student'] }}</td>
                            <td>{{ $entry['icon'] }}</td>
                            <td style="text-align:end;padding:6px 16px;font-weight:700">{{ number_format($entry['amount'], 2) }} €</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
