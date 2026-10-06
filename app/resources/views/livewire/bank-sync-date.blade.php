{{-- Navbar: the date bank payments were last synced from the statement (App\Livewire\BankSyncDate). --}}
<div class="bank-sync" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="bank-sync__chip {{ $saved ? '' : 'is-unset' }}" @click="open = !open" :aria-expanded="open"
            title="{{ $saved ? __('banksync.chip_title', ['date' => $savedLabel]) : __('banksync.not_set') }}">
        <span aria-hidden="true">🏦</span>
        <span class="bank-sync__label">{{ __('banksync.chip') }}</span>
        <span class="bank-sync__date" dir="ltr">{{ $short ?? '—' }}</span>
    </button>

    <div class="bank-sync__pop" x-show="open" x-cloak x-transition.opacity role="dialog" aria-labelledby="bank-sync-title">
        <h4 id="bank-sync-title">🏦 {{ __('banksync.title') }}</h4>

        @if ($saved)
            <p class="bank-sync__current">
                <strong>{{ $savedLabel }}</strong>
                <span class="bank-sync__ago">{{ $days === 0 ? __('banksync.today') : trans_choice('banksync.days_ago', $days, ['count' => $days]) }}</span>
            </p>
            <p class="bank-sync__hint">{{ __('banksync.hint', ['date' => $savedLabel]) }}</p>
            @if ($by)
                <p class="bank-sync__meta">{{ __('banksync.set_by', ['name' => $by, 'at' => $at]) }}</p>
            @endif
        @else
            <p class="bank-sync__hint">{{ __('banksync.not_set_hint') }}</p>
        @endif

        @if ($canWrite)
            <form class="bank-sync__form" wire:submit="save">
                <label for="bank-sync-input">{{ __('banksync.date') }}</label>
                <div class="bank-sync__row">
                    <input id="bank-sync-input" type="date" class="form-input" wire:model="date" max="{{ now()->format('Y-m-d') }}" min="2020-01-01" required>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('date', '{{ now()->format('Y-m-d') }}')">{{ __('banksync.today_button') }}</button>
                </div>
                @error('date') <small class="text-danger">{{ $message }}</small> @enderror
                <label for="bank-sync-note">{{ __('banksync.note') }}</label>
                <input id="bank-sync-note" type="text" class="form-input" wire:model="note" maxlength="200" placeholder="{{ __('banksync.note_placeholder') }}">
                @error('note') <small class="text-danger">{{ $message }}</small> @enderror
                <button type="submit" class="btn btn-sm btn-primary bank-sync__save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">💾 {{ __('common.save') }}</span>
                    <span wire:loading wire:target="save"><span class="spinner-sm"></span> {{ __('common.save') }}…</span>
                </button>
            </form>
        @endif

        <button type="button" class="btn btn-sm btn-ghost bank-sync__history-btn" @click="open = false" wire:click="openHistory">📜 {{ __('banksync.history') }}</button>
    </div>

    {{-- History window: every saved round, and the bank payments entered in it. --}}
    @teleport('body')
        <div>
            @if ($showHistory)
                <div class="modal-backdrop" wire:click.self="closeHistory" @keydown.window.escape="$wire.closeHistory()">
                    <div class="modal-box bank-history" role="dialog" aria-labelledby="bank-history-title">
                        <div class="modal-header">
                            <h3 id="bank-history-title">📜 {{ __('banksync.history_title') }}</h3>
                            <button type="button" class="btn btn-sm btn-ghost" wire:click="closeHistory" aria-label="{{ __('common.close') }}">✕</button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted fs-xs bank-history__intro">{{ __('banksync.history_hint') }}</p>
                            @if (!$rounds)
                                <p class="text-soft bank-history__empty">{{ __('banksync.history_empty') }}</p>
                            @else
                                <div class="bank-history__list">
                                    @foreach ($rounds as $r)
                                        <div class="bank-history__round {{ $expanded === $r['id'] ? 'is-open' : '' }}" wire:key="bsr-{{ $r['id'] }}">
                                            <button type="button" class="bank-history__head" wire:click="toggleRound({{ $r['id'] }})" @disabled($r['count'] === 0)>
                                                <span class="bank-history__date">
                                                    <strong>{{ $r['date_label'] }}</strong>
                                                    @if ($r['from_label'])
                                                        <small class="text-muted">{{ __('banksync.after', ['date' => $r['from_label']]) }}</small>
                                                    @endif
                                                </span>
                                                <span class="bank-history__who text-muted">{{ $r['by'] }} · <span dir="ltr">{{ $r['at'] }}</span></span>
                                                <span class="bank-history__sum {{ $r['count'] ? '' : 'text-muted' }}">
                                                    @if ($r['has_start'])
                                                        {{ trans_choice('banksync.payments_count', $r['count'], ['count' => $r['count']]) }}@if ($r['count']) · <span dir="ltr">{{ number_format($r['total'], 2) }} €</span>@endif
                                                    @else
                                                        {{ __('banksync.up_to_here', ['count' => $r['count']]) }}
                                                    @endif
                                                    @if ($r['count'])<span class="bank-history__caret" aria-hidden="true">▾</span>@endif
                                                </span>
                                            </button>
                                            @if ($r['note'])
                                                <div class="bank-history__note">📝 {{ $r['note'] }}</div>
                                            @endif
                                            @if ($expanded === $r['id'])
                                                <div class="bank-history__payments">
                                                    <table class="bank-history__table">
                                                        <thead>
                                                            <tr>
                                                                <th>{{ __('banksync.col_paid_at') }}</th>
                                                                <th>{{ __('family.col_student') }}</th>
                                                                <th>{{ __('banksync.col_month') }}</th>
                                                                <th>{{ __('payment.amount') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($roundPayments as $p)
                                                                <tr wire:key="bsp-{{ $p['id'] }}">
                                                                    <td dir="ltr">{{ $p['paid_at'] }}</td>
                                                                    <td>
                                                                        <button type="button" class="fam-name-link" wire:click="closeHistory" @click="Livewire.dispatch('open-student-panel', { studentId: {{ $p['student_id'] }} })">{{ $p['student'] }}</button>
                                                                        @if ($p['note'])<small class="text-muted"> — {{ $p['note'] }}</small>@endif
                                                                    </td>
                                                                    <td>{{ $p['period'] }}</td>
                                                                    <td class="bank-history__amount">{{ number_format($p['amount'], 2) }} €</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn" wire:click="closeHistory">{{ __('common.close_esc') }}</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
