<div
    x-data="{
        closing: false,
        close() {
            if (this.closing) return;
            this.closing = true;
            setTimeout(() => $wire.closeSelf(), 180);
        }
    }"
    @keydown.window.escape="close()"
>
    <div class="side-panel-backdrop" :class="{ 'panel-closing': closing }" @click="close()"></div>
    <div class="side-panel" :class="{ 'panel-closing': closing }">
        <div class="side-panel-header">
            <h2>{{ $student->name }}</h2>
            <button type="button" class="btn btn-sm btn-ghost" @click="close()" title="{{ __('common.close_esc') }}" aria-label="{{ __('common.close') }}">✕</button>
        </div>

        {{-- Siblings strip --}}
        @if ($siblings->isNotEmpty())
            <div style="padding:14px 20px 0">
                <div class="siblings-strip">
                    <strong class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:0.06em">👨‍👩‍👧‍👦 {{ __('panel.siblings') }}:</strong>
                    @foreach ($siblings as $sib)
                        <span class="sibling-chip" wire:click="switchStudent({{ $sib->id }})" title="{{ __('actions.view_details') }}">
                            {{ $sib->name }}
                        </span>
                    @endforeach
                    <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openFamily" style="margin-inline-start:auto">
                        👨‍👩‍👧‍👦 {{ __('actions.show_family') }}
                    </button>
                </div>
            </div>
        @endif

        <div class="side-panel-body">
            {{-- Summary --}}
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="label">📞 {{ __('columns.phone') }}</div>
                    <div class="value" style="font-family:ui-monospace,monospace;font-size:13px">{{ $student->phone_primary_e164 ?: '—' }}</div>
                </div>
                <div class="summary-item">
                    <div class="label">📞₂ Secondary</div>
                    <div class="value" style="font-family:ui-monospace,monospace;font-size:13px">{{ $student->phone_secondary_e164 ?: '—' }}</div>
                </div>
                <div class="summary-item">
                    <div class="label">💶 {{ __('panel.balance') }}</div>
                    <div class="value" style="color:{{ $totalBalance > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                        {{ number_format($totalBalance, 2) }} €
                    </div>
                </div>
                <div class="summary-item">
                    <div class="label">🆔 ID</div>
                    <div class="value">{{ $student->external_id ?? $student->id }}</div>
                </div>
            </div>

            @if ($student->skipReason())
                <div class="pill pill-warning" style="display:block;padding:8px 12px;margin-bottom:14px">⚠️ {{ $student->skipReason() }}</div>
            @endif

            {{-- Quick actions --}}
            <div style="display:flex;gap:6px;margin-bottom:18px">
                <button class="btn btn-primary btn-sm" style="flex:1" wire:click="openSendMessage">📲 {{ __('actions.send_message') }}</button>
                <button class="btn btn-soft-success btn-sm" style="flex:1" wire:click="openPayment({{ (int) date('n') }})">💶 {{ __('actions.add_payment') }}</button>
            </div>

            {{-- Tabs --}}
            <div class="tabs">
                <button class="{{ $tab === 'payments' ? 'active' : '' }}" wire:click="$set('tab', 'payments')">{{ __('panel.tabs.payments') }}</button>
                <button class="{{ $tab === 'fees' ? 'active' : '' }}" wire:click="$set('tab', 'fees')">{{ __('panel.tabs.fees') }}</button>
                <button class="{{ $tab === 'settings' ? 'active' : '' }}" wire:click="$set('tab', 'settings')">{{ __('panel.tabs.settings') }}</button>
                <button class="{{ $tab === 'notes' ? 'active' : '' }}" wire:click="$set('tab', 'notes')">{{ __('panel.tabs.profile') }}</button>
                <button class="{{ $tab === 'messages' ? 'active' : '' }}" wire:click="$set('tab', 'messages')">{{ __('panel.tabs.messages') }}</button>
            </div>

            {{-- Tab: Payments --}}
            @if ($tab === 'payments')
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="background:var(--color-surface-alt)">
                            <th style="padding:8px;text-align:start;font-size:11px;text-transform:uppercase;color:var(--color-text-muted)">{{ __('filters.month') }}</th>
                            <th style="padding:8px;font-size:11px;text-transform:uppercase;color:var(--color-text-muted)">{{ __('payment.due') }}</th>
                            <th style="padding:8px;font-size:11px;text-transform:uppercase;color:var(--color-text-muted)">{{ __('Paid') }}</th>
                            <th style="padding:8px;font-size:11px;text-transform:uppercase;color:var(--color-text-muted)">{{ __('payment.remaining') }}</th>
                            <th style="padding:8px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $num => $name)
                            @php
                                $d = $monthsData[$num];
                                $bal = $d['due'] - $d['paid'];
                            @endphp
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:6px;font-weight:600">{{ $name }}</td>
                                <td style="padding:6px;text-align:center">{{ number_format($d['due'], 0) }}</td>
                                <td style="padding:6px;text-align:center">
                                    @if ($d['paid'] > 0)
                                        {{ number_format($d['paid'], 0) }}
                                        @foreach ($d['payments'] as $p)
                                            {{ $p->methodIcon() }}
                                        @endforeach
                                    @else
                                        <span class="text-soft">—</span>
                                    @endif
                                </td>
                                <td style="padding:6px;text-align:center;color:{{ $bal > 0 ? 'var(--color-danger)' : 'var(--color-success)' }};font-weight:700">
                                    {{ number_format($bal, 0) }}
                                </td>
                                <td style="padding:6px;text-align:center">
                                    <button class="btn btn-sm btn-soft-success" wire:click="openPayment({{ $num }})">💶</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- Tab: Fees --}}
            @if ($tab === 'fees')
                <h4 style="margin-top:0">💶 {{ __('Monthly fee overrides') }}</h4>
                @if ($student->feeOverrides->count() > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">
                        @foreach ($student->feeOverrides as $ov)
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:6px">{{ $months[$ov->period_month] }} {{ $ov->period_year }}</td>
                                <td style="padding:6px;text-align:center;font-weight:700">{{ number_format($ov->amount, 2) }} €</td>
                                <td style="padding:6px;font-size:11px;color:var(--color-text-muted)">{{ $ov->reason }}</td>
                                <td><button class="btn btn-sm btn-soft-danger" wire:click="removeOverride({{ $ov->id }})" wire:confirm="{{ __('confirm.delete_fee_override', ['month' => $months[$ov->period_month], 'year' => $ov->period_year, 'amount' => number_format($ov->amount, 2)]) }}" title="{{ __('common.delete') }}">🗑️</button></td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                <div class="form-row cols-2">
                    <select class="form-select" wire:model="override_month">
                        @foreach ($months as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" class="form-input" wire:model="override_amount" placeholder="{{ __('panel.amount_placeholder') }}">
                </div>
                <input type="text" class="form-input mt-2" wire:model="override_reason" placeholder="{{ __('panel.reason_optional') }}">
                <button class="btn btn-primary mt-2" wire:click="addOverride">+ {{ __('Add override') }}</button>

                <hr style="margin:22px 0;border:none;border-top:1px solid var(--color-border)">

                <h4>➕ {{ __('Surcharges (extra fees)') }}</h4>
                @if ($student->surcharges->count() > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">
                        @foreach ($student->surcharges as $sur)
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:6px">{{ $months[$sur->period_month] }}</td>
                                <td style="padding:6px;text-align:center;font-weight:700">{{ number_format($sur->amount, 2) }} €</td>
                                <td style="padding:6px;font-size:11px">{{ $sur->reason }}</td>
                                <td><button class="btn btn-sm btn-soft-danger" wire:click="removeSurcharge({{ $sur->id }})" wire:confirm="{{ __('confirm.delete_surcharge', ['month' => $months[$sur->period_month], 'amount' => number_format($sur->amount, 2)]) }}" title="{{ __('common.delete') }}">🗑️</button></td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                <div class="form-row cols-2">
                    <select class="form-select" wire:model="surcharge_month">
                        @foreach ($months as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" class="form-input" wire:model="surcharge_amount" placeholder="{{ __('panel.amount_placeholder') }}">
                </div>
                <input type="text" class="form-input mt-2" wire:model="surcharge_reason" placeholder="{{ __('panel.reason_required') }}">
                <button class="btn btn-primary mt-2" wire:click="addSurcharge">+ {{ __('Add surcharge') }}</button>
            @endif

            {{-- Tab: Settings --}}
            @if ($tab === 'settings')
                <h4 style="margin-top:0">{{ __('Control flags') }}</h4>
                <div class="form-row cols-2">
                    <button class="btn {{ $student->is_hidden ? 'btn-warning' : '' }}" wire:click="toggleFlag('is_hidden')">
                        {{ $student->is_hidden ? '👁️ ' . __('actions.toggle_hidden') : '🙈 ' . __('actions.toggle_hidden') }}
                    </button>
                    <button class="btn {{ $student->is_blocked_messages ? 'btn-danger' : '' }}" wire:click="toggleFlag('is_blocked_messages')">
                        {{ $student->is_blocked_messages ? '✅ ' . __('actions.toggle_blocked') : '🚫 ' . __('actions.toggle_blocked') }}
                    </button>
                    <button class="btn {{ $student->is_in_person ? 'btn-warning' : '' }}" wire:click="toggleFlag('is_in_person')">
                        {{ $student->is_in_person ? '🚪 ' . __('actions.toggle_in_person') : '🏠 ' . __('actions.toggle_in_person') }}
                    </button>
                    <button class="btn {{ $student->excluded_from_send_all ? 'btn-warning' : '' }}" wire:click="toggleFlag('excluded_from_send_all')">
                        {{ $student->excluded_from_send_all ? '✓ ' . __('actions.toggle_exclude_send_all') : '🚷 ' . __('actions.toggle_exclude_send_all') }}
                    </button>
                </div>

                <hr style="margin:22px 0;border:none;border-top:1px solid var(--color-border)">

                <h4>⏸️ {{ __('Temporary suspension') }}</h4>
                @php $active = $student->activeSuspension(); @endphp
                @if ($active)
                    @php
                        $suspendStarts = $active->starts_at->format('Y-m-d');
                        $suspendEnds = $active->ends_at ? $active->ends_at->format('Y-m-d') : __('panel.open_ended');
                    @endphp
                    <div class="pill pill-warning" style="display:block;padding:10px 12px;margin-bottom:10px">
                        {{ __('filters.suspended') }} · {{ __('panel.from') }} <strong>{{ $suspendStarts }}</strong> {{ __('to') }}
                        <strong>{{ $suspendEnds }}</strong>
                        @if ($active->reason) — {{ $active->reason }} @endif
                        <button class="btn btn-sm btn-danger"
                                wire:click="removeSuspension({{ $active->id }})"
                                wire:confirm="{{ __('confirm.cancel_suspension', ['starts' => $suspendStarts, 'ends' => $suspendEnds]) }}"
                                style="margin-inline-start:auto">{{ __('panel.end_suspension') }}</button>
                    </div>
                @endif
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>{{ __('panel.from') }}</label>
                        <input type="date" class="form-input" wire:model="suspend_starts_at">
                    </div>
                    <div class="form-group">
                        <label>{{ __('to') }}</label>
                        <input type="date" class="form-input" wire:model="suspend_ends_at">
                    </div>
                </div>
                <div class="form-group">
                    <label>{{ __('panel.reason_optional') }}</label>
                    <input type="text" class="form-input" wire:model="suspend_reason">
                </div>
                <button class="btn btn-primary" wire:click="addSuspension">+ {{ __('Temporary suspension') }}</button>
            @endif

            {{-- Tab: Profile --}}
            @if ($tab === 'notes')
                <div class="form-group">
                    <label>{{ __('columns.name') }}</label>
                    <input type="text" class="form-input" wire:model="name">
                </div>
                <div class="form-group">
                    <label>{{ __('panel.primary_phone') }}</label>
                    <input type="text" class="form-input" wire:model="phone_primary_raw" placeholder="06xxxxxxxx">
                </div>
                <div class="form-group">
                    <label>{{ __('panel.secondary_phone') }}</label>
                    <input type="text" class="form-input" wire:model="phone_secondary_raw">
                </div>
                <div class="form-group">
                    <label>{{ __('Default monthly fee') }}</label>
                    <input type="number" step="0.01" class="form-input" wire:model="default_fee_amount" placeholder="e.g. 25.00">
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>📅 {{ __('panel.enrolled_at') }}</label>
                        <input type="date" class="form-input" wire:model="enrolled_at">
                        <div class="field-help">{{ __('panel.enrolled_help') }}</div>
                        @error('enrolled_at') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label>🚪 {{ __('panel.withdrawn_at') }}</label>
                        <input type="date" class="form-input" wire:model="withdrawn_at">
                        <div class="field-help">{{ __('panel.withdrawn_help') }}</div>
                        @error('withdrawn_at') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                </div>

                <div class="form-group">
                    <label>{{ __('panel.notes') }}</label>
                    <textarea class="form-textarea" wire:model="notes"></textarea>
                </div>
                <button class="btn btn-primary" wire:click="saveBasic">💾 {{ __('common.save') }}</button>
            @endif

            {{-- Tab: Messages --}}
            @if ($tab === 'messages')
                <button class="btn btn-primary mb-3" wire:click="openSendMessage">
                    📲 {{ __('actions.send_message') }}
                </button>
                @php
                    $logs = \App\Models\MessageLog::where('student_id', $student->id)->orderByDesc('created_at')->limit(20)->get();
                @endphp
                @forelse ($logs as $log)
                    <div style="border:1px solid var(--color-border);border-radius:var(--radius);padding:10px;margin-bottom:8px;font-size:12px">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                            <strong>{{ $log->type }}</strong>
                            <span class="text-muted fs-xs">{{ $log->created_at->format('Y-m-d H:i') }}</span>
                        </div>
                        <div style="background:var(--color-surface-alt);padding:8px;border-radius:6px;margin-bottom:6px">{{ $log->body }}</div>
                        <div style="display:flex;justify-content:space-between;color:var(--color-text-muted)">
                            <span>{{ $log->segments }} SMS • {{ number_format($log->cost, 4) }}€</span>
                            @php $isErr = str_starts_with($log->status, 'ERROR'); @endphp
                            <span class="pill {{ $isErr ? 'pill-danger' : 'pill-success' }}">{{ $log->status }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-soft" style="text-align:center;padding:30px">No messages yet.</p>
                @endforelse
            @endif
        </div>
    </div>
</div>
