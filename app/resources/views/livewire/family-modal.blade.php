<div>
    @if ($isOpen)
        <div
            x-data="{
                closing: false,
                close() {
                    if (this.closing) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                }
            }"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
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
                        <div class="family-summary">
                            <strong>{{ __('family.members_count', ['count' => count($members)]) }}</strong>
                            @php
                                $totalFamilyBalance = collect($members)->sum('balance');
                            @endphp
                            <span class="family-balance" style="color:{{ $totalFamilyBalance > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                                💶 {{ __('family.total_balance') }}: <strong>{{ number_format($totalFamilyBalance, 2) }} €</strong>
                            </span>
                        </div>

                        <div class="family-members">
                            @foreach ($members as $m)
                                <div
                                    class="family-member family-member-clickable {{ $m['is_self'] ? 'is-self' : '' }}"
                                    @click="close(); abOpenPayment({{ $m['id'] }}, {{ (int) date('Y') }}, {{ (int) date('n') }}, @js($m['name']))"
                                    role="button"
                                    tabindex="0"
                                    @keydown.enter.prevent="close(); abOpenPayment({{ $m['id'] }}, {{ (int) date('Y') }}, {{ (int) date('n') }}, @js($m['name']))"
                                    title="{{ __('actions.add_payment') }}: {{ $m['name'] }}"
                                >
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
                                            <span class="meta-progress">
                                                ✅ {{ $m['months_paid'] }}/{{ $m['months_total'] }} {{ __('family.months') }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="member-balance" style="color:{{ $m['balance'] > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                                        {{ number_format($m['balance'], 0) }}€
                                    </div>
                                    <div class="member-actions" @click.stop>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-soft-success"
                                            @click.stop="close(); abOpenPayment({{ $m['id'] }}, {{ (int) date('Y') }}, {{ (int) date('n') }}, @js($m['name']))"
                                            title="{{ __('actions.add_payment') }}"
                                        >💶</button>
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            @click.stop="close(); Livewire.dispatch('open-student-panel', { studentId: {{ $m['id'] }} })"
                                            title="{{ __('actions.view_details') }}"
                                        >👁️</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn" @click="close()">{{ __('common.close_esc') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
