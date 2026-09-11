{{--
    Family window = pay for the whole family at once. Each child has an amount
    field prefilled with what they still owe for the chosen month, and the
    cursor is already in the first one when the window opens: check, Enter,
    done. (It used to need a click on 💶 per child, each of which closed this
    window and opened the single-payment modal.)
--}}
<div>
    @if ($isOpen)
        <div
            x-data="{
                closing: false,
                saving: false,
                close() {
                    if (this.closing) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                },
                save() {
                    if (this.saving || this.closing) return;
                    this.saving = true;
                    $wire.saveAll().finally(() => this.saving = false);
                },
                total() {
                    return Object.values($wire.amounts || {}).reduce((s, v) => s + (parseFloat(v) || 0), 0);
                },
                focusFirst() {
                    const inputs = [...this.$root.querySelectorAll('[data-fm-amount]')];
                    const el = inputs.find((i) => parseFloat(i.value) > 0) || inputs[0];
                    if (el) { el.focus(); el.select(); }
                },
                pickMonth(m) {
                    $wire.set('month', m).then(() => this.$nextTick(() => this.focusFirst()));
                },
            }"
            x-init="$nextTick(() => focusFirst())"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
            @keydown.window.ctrl.enter.prevent="save()"
        >
            <div class="modal-box family-modal-box" @click.stop>
                <div class="modal-header">
                    <h3>👨‍👩‍👧‍👦 {{ __('family.title') }} — {{ $familyTitle }}
                        @if ($guardianPhone)
                            <small class="text-muted fw-600" style="font-family:ui-monospace,monospace">/ {{ $guardianPhone }}</small>
                        @endif
                    </h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>

                <div class="modal-body">
                    @if (count($members) === 0)
                        <p class="text-soft" style="text-align:center;padding:30px">—</p>
                    @else
                        @php $totalFamilyBalance = collect($members)->sum('balance'); @endphp
                        <div class="family-summary">
                            <strong>{{ __('family.members_count', ['count' => count($members)]) }}</strong>
                            <span class="family-balance" style="color:{{ $totalFamilyBalance > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                                💶 {{ __('family.total_balance') }}: <strong>{{ number_format($totalFamilyBalance, 2) }} €</strong>
                            </span>
                        </div>

                        @if ($canWrite)
                            <div class="family-paybar">
                                <label class="fp-field">
                                    <span class="fp-label">{{ __('family.pay_for') }}</span>
                                    <select class="form-select fp-month" x-on:change="pickMonth(+$event.target.value)">
                                        @foreach ($monthNames as $num => $name)
                                            <option value="{{ $num }}" @selected($num === $month)>{{ $name }} {{ $year }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <div class="method-toggle fp-method">
                                    <button type="button" class="cash {{ $method === 'cash' ? 'active' : '' }}" :class="{ active: $wire.method === 'cash' }" @click="$wire.method = 'cash'">💵 {{ __('payment.method_cash') }}</button>
                                    <button type="button" class="bank {{ $method === 'bank' ? 'active' : '' }}" :class="{ active: $wire.method === 'bank' }" @click="$wire.method = 'bank'">🏦 {{ __('payment.method_bank') }}</button>
                                </div>
                                <label class="fp-field">
                                    <span class="fp-label">{{ __('payment.date') }}</span>
                                    <input type="date" class="form-input fp-date" wire:model="paid_at">
                                </label>
                            </div>
                            @error('paid_at') <small class="text-danger">{{ $message }}</small> @enderror
                        @endif

                        <div class="family-members">
                            @foreach ($members as $m)
                                @php
                                    $pill = match ($m['month_status']) {
                                        'paid', 'paid_advance', 'legacy_zero' => 'pill-success',
                                        'partial' => 'pill-warning',
                                        'unpaid', 'late' => 'pill-danger',
                                        default => 'pill-muted',
                                    };
                                @endphp
                                <div class="family-member {{ $m['is_self'] ? 'is-self' : '' }}" wire:key="fm-{{ $m['id'] }}">
                                    <div class="member-main">
                                        <div class="member-name">
                                            @if ($m['is_self']) <span class="pill pill-info" title="{{ __('family.current') }}">{{ __('family.current') }}</span> @endif
                                            <strong class="member-name-text">{{ $m['name'] }}</strong>
                                            @if ($m['badge']) <span class="status-badge" title="{{ $m['skip_reason'] ?? '' }}">{{ $m['badge'] }}</span> @endif
                                        </div>
                                        <div class="member-meta">
                                            <span class="meta-id">🆔 {{ $m['external_id'] ?? $m['id'] }}</span>
                                            @if ($m['phone'])
                                                <span class="meta-phone">📞 {{ $m['phone'] }}</span>
                                            @endif
                                            <span class="meta-progress">✅ {{ $m['months_paid'] }}/{{ $m['months_total'] }} {{ __('family.months') }}</span>
                                            <span class="meta-balance {{ $m['balance'] > 0 ? 'is-owed' : 'is-clear' }}" title="{{ __('family.balance_to_date') }}">💶 {{ number_format($m['balance'], 0) }}€</span>
                                        </div>
                                    </div>

                                    <div class="member-month">
                                        <span class="pill {{ $pill }}">{{ $m['month_label'] }}</span>
                                        @unless ($m['outside'])
                                            <div class="member-month-sub">
                                                {{ __('family.month_due', ['amount' => number_format($m['month_due'], 0)]) }}@if ($m['month_paid'] > 0) · {{ __('family.month_paid', ['amount' => number_format($m['month_paid'], 0)]) }}@endif
                                            </div>
                                        @endunless
                                    </div>

                                    @if ($canWrite)
                                        <div class="member-pay">
                                            @if ($m['outside'])
                                                <span class="text-muted fs-xs">{{ __('status.not_enrolled') }}</span>
                                            @else
                                                <input
                                                    type="number"
                                                    inputmode="decimal"
                                                    step="0.01"
                                                    min="0"
                                                    class="form-input member-amount"
                                                    data-fm-amount
                                                    wire:model="amounts.{{ $m['id'] }}"
                                                    placeholder="0"
                                                    aria-label="{{ __('payment.amount') }} — {{ $m['name'] }}"
                                                    @keydown.enter.prevent="save()"
                                                >
                                                <span class="member-amount-cur">€</span>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="member-actions">
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            @click="close(); abOpenPayment({{ $m['id'] }}, {{ $year }}, {{ $month }}, @js($m['name']))"
                                            title="{{ __('family.details') }}"
                                        >✏️</button>
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            @click="close(); Livewire.dispatch('open-student-panel', { studentId: {{ $m['id'] }} })"
                                            title="{{ __('actions.view_details') }}"
                                        >👁️</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('amounts.*') <small class="text-danger">{{ $message }}</small> @enderror
                    @endif
                </div>

                <div class="modal-footer">
                    @if ($canWrite && count($members) > 0)
                        <span class="text-muted fs-xs fp-hint">{{ __('family.keys_hint') }}</span>
                    @endif
                    <button type="button" class="btn" @click="close()">{{ __('common.close_esc') }}</button>
                    @if ($canWrite && count($members) > 0)
                        <button type="button" class="btn btn-primary fp-save" @click="save()" :disabled="saving">
                            <span x-show="!saving">💾 {{ __('family.save_all') }} — <span class="fp-total" x-text="total().toFixed(2)"></span> €</span>
                            <span x-show="saving" x-cloak><span class="spinner-sm"></span> {{ __('family.save_all') }}…</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
