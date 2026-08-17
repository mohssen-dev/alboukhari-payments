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
                saveOnce(next) {
                    // Repeated Ctrl+Enter queued a second save() that landed on
                    // the NEXT student's freshly-opened modal — guard in-flight.
                    if (this.saving) return;
                    this.saving = true;
                    $wire.save(next).finally(() => this.saving = false);
                }
            }"
            x-init="$nextTick(() => document.getElementById('payment-amount-input')?.focus())"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
            @keydown.window.ctrl.enter.prevent="saveOnce(true)"
        >
            <div class="modal-box" @click.stop>
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
                                    <tr>
                                        <td style="padding:4px">{{ $p['paid_at'] }}</td>
                                        <td style="padding:4px">{{ $p['method_icon'] }} {{ $p['method_label'] }}</td>
                                        <td style="padding:4px;text-align:end;font-weight:700">{{ number_format($p['amount'], 2) }} €</td>
                                        <td style="padding:4px">
                                            <a class="btn btn-sm" href="{{ route('exports.receipt', $p['id']) }}" target="_blank" rel="noopener" title="{{ __('exports.receipt') }}">🧾</a>
                                            <button type="button" class="btn btn-sm" wire:click="editExisting({{ $p['id'] }})" wire:loading.attr="disabled" wire:target="editExisting({{ $p['id'] }})">
                                                <span wire:loading.remove wire:target="editExisting({{ $p['id'] }})">✏️</span>
                                                <span wire:loading wire:target="editExisting({{ $p['id'] }})" class="spinner-sm"></span>
                                            </button>
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
                            x-on:keydown.enter.prevent.stop="$wire.save(false)"
                        >
                        @error('amount') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label>{{ __('payment.method') }} <small class="text-muted">{{ __('payment.shortcuts') }}</small></label>
                        <div
                            class="method-toggle"
                            @keydown.window.n.prevent="if (!['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) $wire.setMethod('cash')"
                            @keydown.window.b.prevent="if (!['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) $wire.setMethod('bank')"
                        >
                            <button type="button" class="cash {{ $method === 'cash' ? 'active' : '' }}" wire:click="setMethod('cash')">
                                💵 {{ __('payment.method_cash') }}
                            </button>
                            <button type="button" class="bank {{ $method === 'bank' ? 'active' : '' }}" wire:click="setMethod('bank')">
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
                    <button
                        type="button"
                        class="btn btn-soft-success"
                        wire:click="save(false)"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">💾 {{ __('payment.save') }}</span>
                        <span wire:loading wire:target="save"><span class="spinner-sm"></span> {{ __('payment.save') }}…</span>
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        wire:click="save(true)"
                        title="{{ __('payment.save_shortcut_hint') }}"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">↩ {{ __('payment.save_and_next') }}</span>
                        <span wire:loading wire:target="save"><span class="spinner-sm"></span> …</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
