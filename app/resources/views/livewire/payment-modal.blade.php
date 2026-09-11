{{--
    Opens INSTANTLY: a click fires the browser event 'pay-open' (abOpenPayment
    in the layout); this shell shows at once with a skeleton and fetches its
    own numbers — one round-trip, and nothing waits for it to appear. It used
    to take two chained round-trips (grid → server → event → modal → server)
    before anything showed.

    Visibility is client-side so closing is instant too:
      pending — opened in the browser, server content not back yet
      hiding  — closed in the browser, server reset still in flight
--}}
<div
    x-data="{
        pending: false,
        hiding: false,
        closing: false,
        saving: false,
        label: '',
        seq: 0,
        months: @js(\App\Services\MonthNames::full()),
        get visible() { return this.pending || ($wire.isOpen && !this.hiding); },
        show(d) {
            this.hiding = false;
            this.closing = false;
            this.pending = true;
            this.label = (d.name ? d.name + ' / ' : '') + (this.months[d.month] || '') + ' ' + d.year;
            const t = ++this.seq;
            $wire.open(d.studentId, d.year, d.month).finally(() => {
                if (t !== this.seq) return; // superseded by a later open/close
                this.pending = false;
                this.focusAmount();
            });
        },
        close() {
            if (!this.visible || this.closing) return;
            this.closing = true;
            setTimeout(() => {
                this.seq++;
                this.pending = false;
                this.hiding = true;
                this.closing = false;
                $wire.close();
            }, 140);
        },
        saveOnce(next) {
            // Repeated Ctrl+Enter queued a second save() that landed on the
            // NEXT student's freshly-opened modal — guard in-flight.
            if (this.saving || this.pending || !this.visible) return;
            this.saving = true;
            $wire.save(next).finally(() => {
                this.saving = false;
                if (next) this.focusAmount();
            });
        },
        edit(p) {
            // Everything needed is already on screen — no round-trip.
            $wire.editingPaymentId = p.id;
            $wire.amount = p.amount;
            $wire.method = p.method === 'legacy_zero' ? 'bank' : p.method;
            $wire.note = p.note || '';
            $wire.paid_at = p.paid_at;
            this.focusAmount();
        },
        focusAmount() {
            this.$nextTick(() => {
                const i = document.getElementById('payment-amount-input');
                if (i) { i.focus(); i.select(); }
            });
        },
        typing() {
            return ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        },
    }"
    x-effect="if (!$wire.isOpen) hiding = false"
    @pay-open.window="show($event.detail)"
    @keydown.window.escape="close()"
    @keydown.window.ctrl.enter="if (visible) { $event.preventDefault(); saveOnce(true) }"
    @keydown.window.n="if (visible && !pending && !typing()) { $event.preventDefault(); $wire.method = 'cash' }"
    @keydown.window.b="if (visible && !pending && !typing()) { $event.preventDefault(); $wire.method = 'bank' }"
>
    <div
        class="modal-backdrop"
        x-show="visible"
        x-cloak
        :class="{ 'modal-closing': closing }"
        @click.self="close()"
    >
        <div class="modal-box" @click.stop>
            {{-- Instant shell while the server fetches the numbers. --}}
            <div x-show="pending">
                <div class="modal-header">
                    <h3>💶 {{ __('payment.title') }} — <span x-text="label"></span></h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>
                <div class="modal-body modal-skeleton" aria-busy="true" aria-label="{{ __('payment.loading') }}">
                    <div class="summary-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:14px">
                        <div class="sk" style="height:58px"></div>
                        <div class="sk" style="height:58px"></div>
                        <div class="sk" style="height:58px"></div>
                    </div>
                    <div class="sk" style="height:12px;width:30%;margin-bottom:8px"></div>
                    <div class="sk" style="height:54px;margin-bottom:14px"></div>
                    <div class="sk" style="height:12px;width:25%;margin-bottom:8px"></div>
                    <div class="sk" style="height:42px"></div>
                </div>
                <div class="modal-footer">
                    <span class="text-muted fs-xs"><span class="spinner-sm"></span> {{ __('payment.loading') }}</span>
                </div>
            </div>

            <div x-show="!pending">
                @if ($isOpen)
                    <div class="modal-header">
                        <h3>💶 {{ __('payment.title') }} — {{ $studentName }}
                            <span class="text-muted fw-600">/ {{ $monthName }} {{ $year }}</span>
                        </h3>
                        <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                    </div>

                    <div class="modal-body">
                        <div class="summary-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:14px">
                            <div class="summary-item">
                                <div class="label">📊 {{ __('payment.due') }}</div>
                                <div class="value">{{ number_format($dueAmount, 2) }} €</div>
                            </div>
                            <div class="summary-item">
                                <div class="label">{{ __('payment.paid_so_far') }}</div>
                                <div class="value">{{ number_format($paidSoFar, 2) }} €</div>
                            </div>
                            <div class="summary-item">
                                <div class="label">{{ __('payment.remaining') }}</div>
                                <div class="value" style="color:{{ ($dueAmount - $paidSoFar) > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                                    {{ number_format($dueAmount - $paidSoFar, 2) }} €
                                </div>
                            </div>
                        </div>

                        @if (count($existingPayments) > 0)
                            <div style="margin-bottom:14px;padding:10px;background:var(--color-warning-soft);border-radius:var(--radius)">
                                <strong class="fs-xs" style="text-transform:uppercase;letter-spacing:0.06em">{{ __('Existing payments this month') }}:</strong>
                                <table style="width:100%;margin-top:6px;font-size:12px">
                                    @foreach ($existingPayments as $p)
                                        <tr wire:key="pay-{{ $p['id'] }}" :class="{ 'payment-row-editing': $wire.editingPaymentId === {{ $p['id'] }} }">
                                            <td style="padding:4px">{{ $p['paid_at'] }}</td>
                                            <td style="padding:4px">{{ $p['method_icon'] }} {{ $p['method_label'] }}</td>
                                            <td style="padding:4px;text-align:end;font-weight:700">{{ number_format($p['amount'], 2) }} €</td>
                                            <td style="padding:4px">
                                                <a class="btn btn-sm" href="{{ route('exports.receipt', $p['id']) }}" target="_blank" rel="noopener" title="{{ __('exports.receipt') }}">🧾</a>
                                                <button type="button" class="btn btn-sm" @click="edit(@js($p))">✏️</button>
                                                <button type="button" class="btn btn-sm btn-soft-danger" wire:click="deletePayment({{ $p['id'] }})" wire:confirm="{{ __('common.confirm') }}" wire:loading.attr="disabled" wire:target="deletePayment({{ $p['id'] }})">
                                                    <span wire:loading.remove wire:target="deletePayment({{ $p['id'] }})">🗑️</span>
                                                    <span wire:loading wire:target="deletePayment({{ $p['id'] }})" class="spinner-sm"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </div>
                        @endif

                        <div class="form-group">
                            <label>{{ __('payment.amount') }} (€)</label>
                            <input
                                id="payment-amount-input"
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-input"
                                wire:model="amount"
                                style="font-size:22px;font-weight:700;text-align:center;padding:12px"
                                required
                                x-on:keydown.enter.prevent.stop="saveOnce(false)"
                            >
                            @error('amount') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <div class="form-group">
                            <label>{{ __('payment.method') }} <small class="text-muted">{{ __('payment.shortcuts') }}</small></label>
                            {{-- Pure client-side toggle: the value rides along with save(). --}}
                            <div class="method-toggle">
                                <button type="button" class="cash {{ $method === 'cash' ? 'active' : '' }}" :class="{ active: $wire.method === 'cash' }" @click="$wire.method = 'cash'">
                                    💵 {{ __('payment.method_cash') }}
                                </button>
                                <button type="button" class="bank {{ $method === 'bank' ? 'active' : '' }}" :class="{ active: $wire.method === 'bank' }" @click="$wire.method = 'bank'">
                                    🏦 {{ __('payment.method_bank') }}
                                </button>
                            </div>
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label>{{ __('payment.date') }}</label>
                                <input type="date" class="form-input" wire:model="paid_at" required>
                            </div>
                            <div class="form-group">
                                <label>{{ __('payment.note') }}</label>
                                <input type="text" class="form-input" wire:model="note" placeholder="...">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn" @click="close()">{{ __('payment.cancel') }} (Esc)</button>
                        <button type="button" class="btn btn-soft-success" @click="saveOnce(false)" :disabled="saving">
                            <span x-show="!saving">💾 {{ __('payment.save') }}</span>
                            <span x-show="saving" x-cloak><span class="spinner-sm"></span> {{ __('payment.save') }}…</span>
                        </button>
                        <button type="button" class="btn btn-primary" @click="saveOnce(true)" :disabled="saving" title="{{ __('payment.save_shortcut_hint') }}">
                            <span x-show="!saving">↩ {{ __('payment.save_and_next') }}</span>
                            <span x-show="saving" x-cloak><span class="spinner-sm"></span> …</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
