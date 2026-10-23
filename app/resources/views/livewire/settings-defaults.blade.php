{{-- Settings → Defaults (App\Livewire\SettingsDefaults): what the payment and send windows start with. --}}
@php use App\Support\Defaults as D; @endphp
<form class="defaults-tab" wire:submit="save">
    <div class="page-card">
        <h3 style="margin-top:0">💶 {{ __('defaults.payments_title') }}</h3>
        <p class="field-help">{{ __('defaults.payments_hint') }}</p>

        {{-- Payment method: the one that matters most — shown as large choices. --}}
        <div class="form-group">
            <label>{{ __('defaults.method') }}</label>
            <div class="defaults-choices">
                @foreach (['' => ['❓', __('defaults.method_ask'), __('defaults.method_ask_hint')], 'cash' => ['💵', __('payment.method_cash'), __('defaults.method_cash_hint')], 'bank' => ['🏦', __('payment.method_bank'), __('defaults.method_bank_hint')]] as $value => [$icon, $label, $hint])
                    <label class="defaults-choice {{ ($values[D::PAYMENT_METHOD] ?? '') === $value ? 'is-on' : '' }}">
                        <input type="radio" wire:model.live="values.{{ D::PAYMENT_METHOD }}" value="{{ $value }}">
                        <span class="defaults-choice__icon" aria-hidden="true">{{ $icon }}</span>
                        <span class="defaults-choice__text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                    </label>
                @endforeach
            </div>
            @error('values.' . D::PAYMENT_METHOD) <small class="text-danger">{{ $message }}</small> @enderror
        </div>

        <div class="form-row cols-2">
            <div class="form-group">
                <label for="def-date">{{ __('defaults.date') }}</label>
                <select id="def-date" class="form-select" wire:model="values.{{ D::PAYMENT_DATE }}">
                    <option value="today">{{ __('defaults.date_today') }}</option>
                    <option value="empty">{{ __('defaults.date_empty') }}</option>
                </select>
                <div class="field-help">{{ __('defaults.date_hint') }}</div>
            </div>
            <div class="form-group">
                <label for="def-amount">{{ __('defaults.amount') }}</label>
                <select id="def-amount" class="form-select" wire:model="values.{{ D::PAYMENT_AMOUNT }}">
                    <option value="remaining">{{ __('defaults.amount_remaining') }}</option>
                    <option value="empty">{{ __('defaults.amount_empty') }}</option>
                </select>
                <div class="field-help">{{ __('defaults.amount_hint') }}</div>
            </div>
        </div>

        <div class="form-group">
            <label for="def-family">{{ __('defaults.family_month') }}</label>
            <select id="def-family" class="form-select" wire:model="values.{{ D::FAMILY_OPENS_ON }}">
                <option value="oldest_unpaid">{{ __('defaults.family_oldest_unpaid') }}</option>
                <option value="current">{{ __('defaults.family_current') }}</option>
            </select>
        </div>
    </div>

    <div class="page-card">
        <h3 style="margin-top:0">📨 {{ __('defaults.send_title') }}</h3>
        <div class="form-row cols-2">
            <div class="form-group">
                <label for="def-template">{{ __('defaults.send_template') }}</label>
                <select id="def-template" class="form-select" wire:model="values.{{ D::SEND_TEMPLATE }}">
                    <option value="">{{ __('defaults.send_template_first') }}</option>
                    @foreach ($templates as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
                @error('values.' . D::SEND_TEMPLATE) <small class="text-danger">{{ $message }}</small> @enderror
            </div>
            <div class="form-group">
                <label for="def-group">{{ __('defaults.send_group') }}</label>
                <select id="def-group" class="form-select" wire:model="values.{{ D::SEND_GROUP_BY_FAMILY }}">
                    <option value="0">{{ __('defaults.send_group_no') }}</option>
                    <option value="1">{{ __('defaults.send_group_yes') }}</option>
                </select>
                <div class="field-help">{{ __('defaults.send_group_hint') }}</div>
            </div>
        </div>
    </div>

    <div class="defaults-actions">
        <button type="button" class="btn" wire:click="resetToFactory">{{ __('defaults.factory') }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">💾 {{ __('common.save') }}</button>
    </div>
</form>
