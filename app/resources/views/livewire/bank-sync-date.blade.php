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
                <button type="submit" class="btn btn-sm btn-primary bank-sync__save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">💾 {{ __('common.save') }}</span>
                    <span wire:loading wire:target="save"><span class="spinner-sm"></span> {{ __('common.save') }}…</span>
                </button>
            </form>
        @endif
    </div>
</div>
