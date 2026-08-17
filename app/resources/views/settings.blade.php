@extends('layouts.app')

@section('content')
<div style="max-width:1200px;margin:0 auto" x-data="{ tab: '{{ $tab }}' }" x-init="
    const url = new URL(window.location);
    if (url.searchParams.get('tab') !== tab) { url.searchParams.set('tab', tab); window.history.replaceState({}, '', url); }
">
    <div class="page-header">
        <h1>⚙️ {{ __('nav.settings') }}</h1>
        @php
            $bgConfigured = !empty(\App\Models\Setting::get('bulkgate_app_id')) && !empty(\App\Models\Setting::get('bulkgate_app_token'));
            $waConfigured = \App\Models\Setting::get('whatsapp_enabled') === '1' && !empty(\App\Models\Setting::get('whatsapp_access_token'));
        @endphp
        <div style="display:flex;gap:10px;font-size:12px">
            <span class="pill {{ $bgConfigured ? 'pill-success' : 'pill-muted' }}">
                <span class="status-dot {{ $bgConfigured ? 'ok' : 'off' }}"></span>
                BulkGate {{ $bgConfigured ? __('Connected') : __('Not configured') }}
            </span>
            <span class="pill {{ $waConfigured ? 'pill-success' : 'pill-muted' }}">
                <span class="status-dot {{ $waConfigured ? 'ok' : 'off' }}"></span>
                WhatsApp {{ $waConfigured ? __('Connected') : __('Not configured') }}
            </span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:240px 1fr;gap:14px;align-items:start">
        {{-- ===== Sidebar Tabs ===== --}}
        <div class="settings-tabs">
            <button :class="{ 'active': tab === 'general' }" @click="tab='general'; updateUrl()">
                <span class="icon">⚙️</span> {{ __('General') }}
            </button>
            <button :class="{ 'active': tab === 'bulkgate' }" @click="tab='bulkgate'; updateUrl()">
                <span class="icon">📲</span> BulkGate <span dir="ltr">(SMS)</span>
            </button>
            <button :class="{ 'active': tab === 'whatsapp' }" @click="tab='whatsapp'; updateUrl()">
                <span class="icon">💬</span> WhatsApp
            </button>
            <button :class="{ 'active': tab === 'reminders' }" @click="tab='reminders'; updateUrl()">
                <span class="icon">⏰</span> {{ __('Reminders') }}
            </button>
            <button :class="{ 'active': tab === 'advanced' }" @click="tab='advanced'; updateUrl()">
                <span class="icon">🔧</span> {{ __('Advanced') }}
            </button>

        </div>

        {{-- ===== Tab Panels ===== --}}
        <div>
            {{-- ===== Tab: General ===== --}}
            <form method="POST" action="{{ route('settings.update') }}" x-show="tab === 'general'" x-cloak>
                @csrf
                <input type="hidden" name="tab" value="general">

                <div class="page-card">
                    <h3 style="margin-top:0">💶 {{ __('Fees & Currency') }}</h3>

                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>{{ __('Default monthly fee') }}</label>
                            <input type="number" step="0.01" name="default_monthly_fee" class="form-input" value="{{ $settings['default_monthly_fee'] }}" required>
                            <div class="field-help">{{ __('Applies to every student unless overridden.') }}</div>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Currency') }}</label>
                            <input type="text" name="currency" class="form-input" value="{{ $settings['currency'] }}" maxlength="5">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>{{ __('School year starts') }}</label>
                        <select name="school_year_start_month" class="form-select">
                            @foreach (range(1,12) as $m)
                                <option value="{{ $m }}" {{ $settings['school_year_start_month'] == $m ? 'selected' : '' }}>{{ date('F', mktime(0,0,0,$m,1)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary btn-lg">💾 {{ __('common.save') }}</button>
                </div>
            </form>

            {{-- ===== Tab: BulkGate ===== --}}
            <div x-show="tab === 'bulkgate'" x-cloak>
                <form method="POST" action="{{ route('settings.update') }}">
                    @csrf
                    <input type="hidden" name="tab" value="bulkgate">

                    <div class="page-card">
                        <h3 style="margin-top:0;display:flex;align-items:center;gap:8px">
                            📲 BulkGate API
                            <span class="pill {{ $bgConfigured ? 'pill-success' : 'pill-muted' }}">
                                {{ $bgConfigured ? __('Connected') : __('Not configured') }}
                            </span>
                        </h3>
                        <p class="field-help mb-3">
                            {{ __('Get your credentials from') }}
                            <a href="https://portal.bulkgate.com" target="_blank" style="color:var(--color-primary)">portal.bulkgate.com</a>.
                            {{ __('Token is stored encrypted.') }}
                        </p>

                        <div class="form-group">
                            <label>{{ __('settings.field.application_id') }}</label>
                            <input type="text" name="bulkgate_app_id" class="form-input" value="{{ $settings['bulkgate_app_id'] }}" placeholder="e.g. 12345">
                        </div>

                        <div class="form-group">
                            <label>
                                {{ __('settings.field.application_token') }}
                                <span class="field-script-default">{{ __('settings.encrypted_hint') }}</span>
                            </label>
                            <input type="password" name="bulkgate_app_token" class="form-input" value="{{ $settings['bulkgate_app_token_masked'] }}" placeholder="{{ $settings['bulkgate_app_token_masked'] ? __('settings.token_already_set') : __('settings.paste_your_token') }}">
                            <div class="field-help">{{ __('Leave blank to keep current value.') }}</div>
                        </div>
                    </div>

                    <div class="page-card">
                        <h3 style="margin-top:0">✉️ {{ __('Sender Identity') }}</h3>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label>
                                    {{ __('settings.field.sender_id_type') }}
                                    <span class="field-script-default">SCRIPT: text</span>
                                </label>
                                <input type="text" name="bulkgate_sender_id" class="form-input" value="{{ $settings['bulkgate_sender_id'] }}">
                                <div class="field-help">{{ __('Usually "text" — matches the original Apps Script setting.') }}</div>
                            </div>
                            <div class="form-group">
                                <label>
                                    {{ __('settings.field.sender_id_value') }}
                                    <span class="field-script-default">SCRIPT: Al Boukhari</span>
                                </label>
                                <input type="text" name="bulkgate_sender_id_value" class="form-input" value="{{ $settings['bulkgate_sender_id_value'] }}">
                                <div class="field-help">{{ __('The name parents see as sender.') }}</div>
                            </div>
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label>
                                    {{ __('Default country') }}
                                    <span class="field-script-default">SCRIPT: NL</span>
                                </label>
                                <input type="text" name="bulkgate_default_country" class="form-input" value="{{ $settings['bulkgate_default_country'] }}" maxlength="3">
                                <div class="field-help">{{ __('Only used when phone is not in E.164 format.') }}</div>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Price per SMS') }} (€)</label>
                                <input type="number" step="0.0001" name="bulkgate_price_per_sms" class="form-input" value="{{ $settings['bulkgate_price_per_sms'] }}">
                                <div class="field-help">{{ __('Used only to estimate cost.') }}</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500">
                                <input type="checkbox" name="force_ascii" {{ $settings['force_ascii'] === '1' ? 'checked' : '' }}>
                                {{ __('Force ASCII (reduces SMS length)') }}
                                <span class="field-script-default">SCRIPT: on</span>
                            </label>
                            <div class="field-help">{{ __('Converts é → e, € → EUR, etc. Keeps SMS to 160 chars instead of 70.') }}</div>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                        <button type="submit" class="btn btn-primary btn-lg">💾 {{ __('common.save') }}</button>
                    </div>
                </form>

                {{-- Test SMS — separate form --}}
                <div class="page-card">
                    <h3 style="margin-top:0">🧪 {{ __('Test connection') }}</h3>
                    <p class="field-help mb-3">{{ __('Send a test SMS to verify your settings work. Saves you from sending bulk before confirming.') }}</p>
                    <form method="POST" action="{{ route('settings.test_bulkgate') }}" style="display:flex;gap:8px">
                        @csrf
                        <input type="text" name="test_phone" class="form-input" placeholder="{{ __('settings.test_phone_placeholder') }}" style="flex:1">
                        <button type="submit" class="btn btn-warning">📨 {{ __('Send test SMS') }}</button>
                    </form>
                </div>
            </div>

            {{-- ===== Tab: WhatsApp ===== --}}
            <form method="POST" action="{{ route('settings.update') }}" x-show="tab === 'whatsapp'" x-cloak>
                @csrf
                <input type="hidden" name="tab" value="whatsapp">

                <div class="page-card">
                    <h3 style="margin-top:0;display:flex;align-items:center;gap:8px">
                        💬 WhatsApp Business Cloud API
                        <span class="pill {{ $waConfigured ? 'pill-success' : 'pill-muted' }}">
                            {{ $waConfigured ? __('Connected') : __('Not configured') }}
                        </span>
                    </h3>
                    <div class="script-banner" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border-color:#bfdbfe;color:#1e3a8a">
                        ℹ️ {{ __('WhatsApp Cloud API is the official Meta integration. Get your credentials at') }}
                        <a href="https://developers.facebook.com/apps" target="_blank" style="color:#1e3a8a;font-weight:700">developers.facebook.com</a>.
                    </div>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
                            <input type="checkbox" name="whatsapp_enabled" {{ $settings['whatsapp_enabled'] === '1' ? 'checked' : '' }}>
                            {{ __('Enable WhatsApp channel') }}
                        </label>
                        <div class="field-help">{{ __('When enabled, WhatsApp will be available as a sending channel in campaigns and individual messages.') }}</div>
                    </div>
                </div>

                <div class="page-card">
                    <h3 style="margin-top:0">🔑 {{ __('API Credentials') }}</h3>

                    <div class="form-group">
                        <label>{{ __('settings.field.phone_number_id') }}</label>
                        <input type="text" name="whatsapp_phone_number_id" class="form-input" value="{{ $settings['whatsapp_phone_number_id'] }}" placeholder="e.g. 105467932...">
                        <div class="field-help">{{ __('Found in: Meta Developer Console → WhatsApp → API Setup') }}</div>
                    </div>

                    <div class="form-group">
                        <label>{{ __('settings.field.business_account_id') }}</label>
                        <input type="text" name="whatsapp_business_account_id" class="form-input" value="{{ $settings['whatsapp_business_account_id'] }}" placeholder="e.g. 102345876...">
                    </div>

                    <div class="form-group">
                        <label>
                            {{ __('settings.field.access_token') }}
                            <span class="field-script-default">{{ __('settings.encrypted_hint') }}</span>
                        </label>
                        <input type="password" name="whatsapp_access_token" class="form-input" value="{{ $settings['whatsapp_access_token_masked'] }}" placeholder="{{ $settings['whatsapp_access_token_masked'] ? __('settings.token_already_set') : 'EAAxxxxxxxxxxxxxxx...' }}">
                        <div class="field-help">{{ __('Generate a permanent token (not the temporary 24h one).') }}</div>
                    </div>

                    <div class="form-group">
                        <label>
                            {{ __('settings.field.app_secret') }}
                            <span class="field-script-default">{{ __('settings.encrypted_hint') }}</span>
                        </label>
                        <input type="password" name="whatsapp_app_secret" class="form-input" value="{{ $settings['whatsapp_app_secret_masked'] }}" placeholder="{{ $settings['whatsapp_app_secret_masked'] ? __('settings.token_already_set') : '' }}">
                    </div>

                    <div class="form-group">
                        <label>{{ __('settings.field.webhook_verify_token') }}</label>
                        <input type="text" name="whatsapp_webhook_verify_token" class="form-input" value="{{ $settings['whatsapp_webhook_verify_token'] }}" placeholder="{{ __('settings.webhook_verify_placeholder') }}">
                        <div class="field-help">{{ __('Configure the same token in Meta webhook settings.') }}</div>
                    </div>

                    {{-- NOTE: incoming-webhook receiver is not implemented yet —
                         advertising url('/webhooks/whatsapp') here pointed admins
                         at a 404. Re-add once the route exists. --}}

                    @if ($waConfigured)
                        <form method="POST" action="{{ route('settings.test_whatsapp') }}" style="margin-top:14px">
                            @csrf
                            <label>🧪 {{ __('send.test_phone') }}</label>
                            <div style="display:flex;gap:6px">
                                <input type="text" name="test_phone" class="form-input" placeholder="+316xxxxxxxx" style="flex:1">
                                <button type="submit" class="btn btn-warning">{{ __('send.test_send') }}</button>
                            </div>
                        </form>
                    @endif
                </div>

                <div class="page-card">
                    <h3 style="margin-top:0">⚡ {{ __('Routing & Fallback') }}</h3>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500">
                            <input type="checkbox" name="whatsapp_fallback_to_sms" {{ $settings['whatsapp_fallback_to_sms'] === '1' ? 'checked' : '' }}>
                            {{ __('Auto-fallback to SMS if WhatsApp fails') }}
                        </label>
                    </div>

                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>{{ __('Fallback delay (minutes)') }}</label>
                            <input type="number" name="whatsapp_fallback_minutes" class="form-input" value="{{ $settings['whatsapp_fallback_minutes'] }}" min="1" max="60">
                            <div class="field-help">{{ __('Wait this many minutes before triggering SMS fallback.') }}</div>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Price per conversation') }} (€)</label>
                            <input type="number" step="0.0001" name="whatsapp_price_per_conversation" class="form-input" value="{{ $settings['whatsapp_price_per_conversation'] }}">
                            <div class="field-help">{{ __('Meta charges per conversation, not per message.') }}</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>{{ __('Default language') }}</label>
                        <select name="whatsapp_default_language" class="form-select">
                            <option value="nl" {{ $settings['whatsapp_default_language'] === 'nl' ? 'selected' : '' }}>Nederlands</option>
                            <option value="en" {{ $settings['whatsapp_default_language'] === 'en' ? 'selected' : '' }}>English</option>
                            <option value="ar" {{ $settings['whatsapp_default_language'] === 'ar' ? 'selected' : '' }}>العربية</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary btn-lg">💾 {{ __('common.save') }}</button>
                </div>
            </form>

            {{-- ===== Tab: Reminders ===== --}}
            <form method="POST" action="{{ route('settings.update') }}" x-show="tab === 'reminders'" x-cloak>
                @csrf
                <input type="hidden" name="tab" value="reminders">

                <div class="page-card">
                    <h3 style="margin-top:0">⏰ {{ __('Automatic Reminders') }}</h3>
                    <p class="field-help mb-3">{{ __('These reminders run automatically every day at the time you set.') }}</p>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;padding:10px 14px;background:var(--color-success-soft);border-radius:var(--radius)">
                            <input type="checkbox" name="trigger_first_friday_enabled" {{ $settings['trigger_first_friday_enabled'] === '1' ? 'checked' : '' }}>
                            🟢 {{ __('Enable: First Friday reminder') }}
                            <span class="field-help" style="margin-inline-start:auto">{{ __('Sent to anyone unpaid on the first Friday of each month.') }}</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;padding:10px 14px;background:var(--color-danger-soft);border-radius:var(--radius)">
                            <input type="checkbox" name="trigger_mid_month_enabled" {{ $settings['trigger_mid_month_enabled'] === '1' ? 'checked' : '' }}>
                            🔴 {{ __('Enable: Mid-month late notice') }}
                            <span class="field-help" style="margin-inline-start:auto">{{ __('Sent to anyone late on day 15 of each month.') }}</span>
                        </label>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--color-border);margin:18px 0">

                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label>
                                {{ __('Trigger hour') }}
                                <span class="field-script-default">SCRIPT: 9</span>
                            </label>
                            <input type="number" name="trigger_hour" class="form-input" value="{{ $settings['trigger_hour'] }}" min="0" max="23">
                        </div>
                        <div class="form-group">
                            <label>
                                {{ __('Trigger minute') }}
                                <span class="field-script-default">SCRIPT: 5</span>
                            </label>
                            <input type="number" name="trigger_minute" class="form-input" value="{{ $settings['trigger_minute'] }}" min="0" max="59">
                        </div>
                        <div class="form-group">
                            <label>{{ __('Mid-month day') }}</label>
                            <input type="number" name="mid_month_day" class="form-input" value="{{ $settings['mid_month_day'] }}" min="1" max="28">
                        </div>
                    </div>
                </div>

                <div class="page-card">
                    <h3 style="margin-top:0">📝 {{ __('Reminder Templates (NL)') }}</h3>
                    <div class="script-banner">
                        ✅ {{ __('These are the exact templates from the original Apps Script — you can edit them here.') }}
                    </div>

                    <div class="form-group">
                        <label>🟢 {{ __('First Friday template (NL)') }}</label>
                        <textarea name="template_first_friday_nl" class="form-textarea" rows="3">{{ $settings['template_first_friday_nl'] }}</textarea>
                    </div>

                    <div class="form-group">
                        <label>🔴 {{ __('Mid-month template (NL)') }}</label>
                        <textarea name="template_mid_month_nl" class="form-textarea" rows="3">{{ $settings['template_mid_month_nl'] }}</textarea>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary btn-lg">💾 {{ __('common.save') }}</button>
                </div>
            </form>

            {{-- ===== Tab: Advanced ===== --}}
            <form method="POST" action="{{ route('settings.update') }}" x-show="tab === 'advanced'" x-cloak>
                @csrf
                <input type="hidden" name="tab" value="advanced">

                <div class="page-card">
                    <h3 style="margin-top:0">🔧 {{ __('Rate Limiting & Batching') }}</h3>
                    <div class="script-banner">
                        ✅ {{ __('All these values are imported from the original Apps Script — keep defaults unless you know what you\'re doing.') }}
                    </div>

                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>
                                {{ __('Max messages per hour') }}
                                <span class="field-script-default">BG_MAX_PER_HOUR: 2500</span>
                            </label>
                            <input type="number" name="bulkgate_max_per_hour" class="form-input" value="{{ $settings['bulkgate_max_per_hour'] }}" min="1">
                            <div class="field-help">{{ __('Hard cap to prevent provider quota errors.') }}</div>
                        </div>
                        <div class="form-group">
                            <label>
                                {{ __('Batch size') }}
                                <span class="field-script-default">BATCH_SIZE: 150</span>
                            </label>
                            <input type="number" name="batch_size" class="form-input" value="{{ $settings['batch_size'] }}" min="1">
                            <div class="field-help">{{ __('Number of messages per batch.') }}</div>
                        </div>
                    </div>

                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>
                                {{ __('Sleep between batches (ms)') }}
                                <span class="field-script-default">SLEEP_BETWEEN_BATCH_MS: 5000</span>
                            </label>
                            <input type="number" name="sleep_between_batch_ms" class="form-input" value="{{ $settings['sleep_between_batch_ms'] }}" min="0">
                        </div>
                        <div class="form-group">
                            <label>
                                {{ __('Checkpoint every N') }}
                                <span class="field-script-default">CHECKPOINT_EVERY: 25</span>
                            </label>
                            <input type="number" name="checkpoint_every" class="form-input" value="{{ $settings['checkpoint_every'] }}" min="1">
                            <div class="field-help">{{ __('Save progress after every N messages.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="page-card">
                    <h3 style="margin-top:0">🔄 {{ __('Resume & Retry') }}</h3>

                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label>
                                {{ __('Resume delay (min)') }}
                                <span class="field-script-default">RESUME_DELAY: 65</span>
                            </label>
                            <input type="number" name="resume_delay_minutes" class="form-input" value="{{ $settings['resume_delay_minutes'] }}" min="1">
                            <div class="field-help">{{ __('When hourly quota is exhausted, wait this long.') }}</div>
                        </div>
                        <div class="form-group">
                            <label>
                                {{ __('Retry short (min)') }}
                                <span class="field-script-default">RETRY_SHORT: 3</span>
                            </label>
                            <input type="number" name="retry_short_minutes" class="form-input" value="{{ $settings['retry_short_minutes'] }}" min="1">
                            <div class="field-help">{{ __('Short retry for transient errors.') }}</div>
                        </div>
                        <div class="form-group">
                            <label>
                                {{ __('Max per tick') }}
                                <span class="field-script-default">MAX_PER_TICK: 0</span>
                            </label>
                            <input type="number" name="max_per_tick" class="form-input" value="{{ $settings['max_per_tick'] }}" min="0">
                            <div class="field-help">{{ __('0 = unlimited per worker run.') }}</div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary btn-lg">💾 {{ __('common.save') }}</button>
                </div>
            </form>

            {{-- Manual Triggers --}}
            <div x-show="tab === 'reminders'" x-cloak class="page-card mt-4">
                <h3 style="margin-top:0">🧪 {{ __('Run reminder manually now') }}</h3>
                <p class="field-help mb-3">{{ __('Force-run a reminder right now without waiting for the schedule.') }}</p>
                <form method="POST" action="{{ route('reminders.trigger') }}" style="display:flex;gap:8px">
                    @csrf
                    <button type="submit" name="type" value="first_friday" class="btn btn-warning" onclick="return confirm(@js(__('confirm.run_first_friday')))">
                        🟢 {{ __('Run: First Friday') }}
                    </button>
                    <button type="submit" name="type" value="mid_month_auto" class="btn btn-danger" onclick="return confirm(@js(__('confirm.run_mid_month')))">
                        🔴 {{ __('Run: Mid-month') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function updateUrl() {
    // Update URL with current tab on click (handled inline by Alpine)
}
</script>
@endsection
