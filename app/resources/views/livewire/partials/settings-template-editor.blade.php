{{-- The template being edited, in place of its card: name, Dutch text (sent), Arabic translation (not sent), live preview. --}}
<div class="settpl-card is-editing" wire:key="settpl-edit-{{ $editingId }}" x-data x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))" @keydown.escape.window="$wire.cancel()">
    <div class="settpl-card__head">
        <div class="settpl-card__title">
            <strong>✏️ {{ $title }}</strong>
            @if (isset($template) && ($kind = $template->reminderKind()))
                <span class="pill pill-warning">⏰ {{ __('settpl.auto_' . $kind) }}</span>
            @endif
        </div>
    </div>

    <div class="form-group">
        <label for="settpl-name">{{ __('templates.display_name') }}</label>
        <input id="settpl-name" type="text" class="form-input" wire:model="name" maxlength="120">
        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <x-template-vars />

    <div class="form-group">
        <label for="settpl-body">🇳🇱 {{ __('templates.body_nl') }}</label>
        <textarea id="settpl-body" class="form-textarea" data-tpl-target dir="ltr" rows="5" wire:model.live.debounce.400ms="body"></textarea>
        @error('body') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <div class="form-group">
        <label for="settpl-body-ar">🌐 {{ __('templates.body_ar') }}</label>
        <textarea id="settpl-body-ar" class="form-textarea" data-tpl-target dir="rtl" rows="3" wire:model.live.debounce.400ms="body_ar"></textarea>
        <div class="field-help">ℹ️ {{ __('templates.body_ar_hint') }}</div>
        @error('body_ar') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    @if ($preview)
        @include('livewire.partials.template-preview', ['preview' => $preview])
    @endif

    <div class="settpl-card__foot">
        <button type="button" class="btn" wire:click="cancel">{{ __('common.cancel') }}</button>
        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">💾 {{ __('common.save') }}</button>
    </div>
</div>
