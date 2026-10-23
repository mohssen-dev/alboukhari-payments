{{--
    Cash / bank choice for a payment, bound to the Livewire `method` property.
    With no default method (Settings → Defaults) nothing is picked: the toggle
    is outlined and says to pick one; when saving was refused for it, the same
    line turns red with the reason (pass :error="$errors->first('method')").
--}}
@props(['method' => '', 'error' => null])
<div {{ $attributes->merge(['class' => 'method-pick' . ($error ? ' has-error' : '')]) }} x-data :class="{ 'is-unpicked': !$wire.method }">
    <div class="method-toggle" role="radiogroup" aria-label="{{ __('payment.method') }}">
        <button type="button" role="radio" class="cash {{ $method === 'cash' ? 'active' : '' }}" :class="{ active: $wire.method === 'cash' }" :aria-checked="$wire.method === 'cash'" @click="$wire.method = 'cash'">💵 {{ __('payment.method_cash') }}</button>
        <button type="button" role="radio" class="bank {{ $method === 'bank' ? 'active' : '' }}" :class="{ active: $wire.method === 'bank' }" :aria-checked="$wire.method === 'bank'" @click="$wire.method = 'bank'">🏦 {{ __('payment.method_bank') }}</button>
    </div>
    <small class="method-pick__hint" x-show="!$wire.method" @if ($method !== '') x-cloak @endif role="{{ $error ? 'alert' : 'note' }}">
        {{ $error ? '⚠️ ' . $error : '👆 ' . __('payment.method_pick_hint') }}
    </small>
</div>
