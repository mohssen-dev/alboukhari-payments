{{-- The message exactly as a real parent receives it, its SMS count and cost, and the translation (App\Services\TemplatePreview::sample). --}}
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
    @if ($preview['quote']['available'])
        @php
            $q = $preview['quote'];
            $range = \App\Support\MoneyFormat::credits($q['min']['credits']) . ($q['min']['credits'] != $q['max']['credits'] ? '–' . \App\Support\MoneyFormat::credits($q['max']['credits']) : '');
        @endphp
        <div class="field-help">💳 {{ __('cost.sample', ['range' => \App\Support\MoneyFormat::iso($range), 'eur' => \App\Support\MoneyFormat::iso(\App\Support\MoneyFormat::eur($q['max']['eur']))]) }}</div>
    @endif
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
