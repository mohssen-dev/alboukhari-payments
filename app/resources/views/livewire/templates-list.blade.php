@php
    $defaultForLabel = function (string $key): string {
        return match ($key) {
            'first_friday' => __('templates.default_first_friday'),
            'mid_month'    => __('templates.default_mid_month'),
            default        => $key,
        };
    };
@endphp
<div class="templates-page" style="max-width:1280px;margin:0 auto;display:grid;grid-template-columns:{{ $editing ? 'minmax(0,1fr) minmax(0,1.1fr)' : '1fr' }};gap:14px">
    <div class="page-card" style="padding:0">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--color-border)">
            <h2 style="margin:0">📝 {{ __('nav.templates') }}</h2>
            <button class="btn btn-primary" wire:click="newTemplate">+ {{ __('templates.new') }}</button>
        </div>

        <table class="students-grid tpl-table" style="font-size:13px">
            <thead>
                <tr>
                    <th style="text-align:start;padding:8px 16px">{{ __('templates.col_name') }}</th>
                    <th>{{ __('templates.col_language') }}</th>
                    <th>{{ __('templates.col_default_for') }}</th>
                    <th aria-label="{{ __('actions.view_details') }}"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($libraryTemplates as $t)
                    @include('livewire.partials.template-row', ['t' => $t, 'defaultForLabel' => $defaultForLabel])
                @empty
                    <tr>
                        <td colspan="4" style="padding:56px 24px;text-align:center;color:var(--color-text-soft)">
                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.5;margin-bottom:10px" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <path d="M14 2v6h6"/>
                                <path d="M9 13h6M9 17h4"/>
                            </svg>
                            <div style="font-size:15px;font-weight:600;color:var(--color-text)">{{ __('templates.empty_title') }}</div>
                            <div class="mt-2" style="max-width:380px;margin-inline:auto;line-height:1.6">{{ __('templates.empty_body') }}</div>
                            <button class="btn btn-primary mt-3" wire:click="newTemplate">+ {{ __('templates.new') }}</button>
                        </td>
                    </tr>
                @endforelse

                @if ($manualTemplates->isNotEmpty())
                    <tr class="tpl-section">
                        <td colspan="4">{{ __('templates.manual_section') }} ({{ $manualTemplates->count() }})</td>
                    </tr>
                    @foreach ($manualTemplates as $t)
                        @include('livewire.partials.template-row', ['t' => $t, 'defaultForLabel' => $defaultForLabel])
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>

    @if ($editing)
        <div
            class="page-card"
            x-data
            @keydown.window.escape="$wire.set('editing', false)"
        >
            <h3 style="margin-top:0">{{ $editId ? __('common.edit') : __('templates.new') }}</h3>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label>{{ __('templates.code') }} <span class="text-muted">({{ __('templates.code_hint') }})</span></label>
                    <input type="text" class="form-input" wire:model="code" placeholder="{{ __('templates.code_placeholder') }}" dir="ltr">
                    @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group">
                    <label>{{ __('templates.display_name') }}</label>
                    <input type="text" class="form-input" wire:model="name">
                    @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label>{{ __('templates.language') }}</label>
                    <select class="form-select" wire:model="language">
                        <option value="nl">Nederlands</option>
                        <option value="ar">العربية</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>{{ __('templates.default_for') }}</label>
                    <select class="form-select" wire:model="default_for">
                        <option value="none">{{ __('templates.default_none') }}</option>
                        <option value="first_friday">{{ __('templates.default_first_friday') }}</option>
                        <option value="mid_month">{{ __('templates.default_mid_month') }}</option>
                    </select>
                </div>
            </div>

            <x-template-vars />

            <div class="form-group">
                <label>🇳🇱 {{ __('templates.body_nl') }}</label>
                <textarea class="form-textarea" data-tpl-target dir="ltr" wire:model.live.debounce.400ms="body" rows="5"></textarea>
                @error('body') <small class="text-danger">{{ $message }}</small> @enderror
            </div>
            <div class="form-group">
                <label>🌐 {{ __('templates.body_ar') }}</label>
                <textarea class="form-textarea" data-tpl-target dir="rtl" wire:model.live.debounce.400ms="body_ar" rows="4"></textarea>
                <div class="field-help">ℹ️ {{ __('templates.body_ar_hint') }}</div>
                @error('body_ar') <small class="text-danger">{{ $message }}</small> @enderror
            </div>

            @if ($preview)
                <div class="tpl-preview">
                    <div class="tpl-preview__head">
                        <strong>📨 {{ __('templates.preview_title') }}</strong>
                        @if ($preview['who'])
                            <span class="text-muted fs-xs">{{ __('templates.preview_for', ['name' => $preview['who'], 'month' => $preview['month']]) }}</span>
                        @endif
                    </div>
                    <div class="tpl-message">
                        <p dir="auto">{{ $preview['counter']['sanitized'] }}</p>
                    </div>
                    <x-sms-meter :counter="$preview['counter']" />
                    <div class="field-help">💶 {{ __('templates.cost_per_message', ['cost' => number_format($preview['cost'], 2)]) }}</div>
                    @if ($preview['translation'])
                        <div class="tpl-translation">
                            <span class="tpl-translation__label">🌐 {{ __('templates.translation_only') }}</span>
                            <p dir="rtl">{{ $preview['translation'] }}</p>
                        </div>
                    @endif
                    @if ($preview['unknown'])
                        <div class="pill pill-danger send-unknown">⚠️ {{ __('tplvar.unknown', ['vars' => \App\Support\TemplateVariables::display($preview['unknown'])]) }}</div>
                    @endif
                </div>
            @endif

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" wire:click="$set('editing', false)">{{ __('common.cancel') }}</button>
                <button class="btn btn-primary" wire:click="save">💾 {{ __('common.save') }}</button>
            </div>
        </div>
    @endif
</div>
