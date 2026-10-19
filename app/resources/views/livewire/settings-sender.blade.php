{{--
    Settings → Sender phone (App\Livewire\SettingsSender): pairing (code + QR),
    the paired phones as their last heartbeat reported them, and the pace
    the phone follows.
--}}
@php
    $attentionLabels = [
        'SCREEN_LOCKED' => __('sender.att.SCREEN_LOCKED'),
        'ACCESSIBILITY_DISABLED' => __('sender.att.ACCESSIBILITY_DISABLED'),
        'SMS_PERMISSION' => __('sender.att.SMS_PERMISSION'),
        'WHATSAPP_APP_NOT_SELECTED' => __('sender.att.WHATSAPP_APP_NOT_SELECTED'),
    ];
    $waApps = ['com.whatsapp' => 'WhatsApp', 'com.whatsapp.w4b' => 'WhatsApp Business'];
@endphp
<div class="sender-tab">
    {{-- ===== Pairing ===== --}}
    <div class="page-card">
        <h3 style="margin-top:0">📱 {{ __('sender.pair_title') }}</h3>
        <p class="field-help">{{ __('sender.pair_hint') }}</p>

        @if ($code && $code->used_at)
            <div class="sender-paired" role="status">
                ✓ {{ __('sender.paired_ok', ['name' => $code->device?->name ?? '—']) }}
                <button type="button" class="btn btn-sm" wire:click="closeCode">{{ __('common.close') }}</button>
            </div>
        @elseif ($code && $code->isUsable())
            <div class="sender-code" wire:poll.4s.visible
                 x-data="{
                     left: {{ (int) max(0, now()->diffInSeconds($code->expires_at, false)) }},
                     get mmss() { const m = Math.floor(this.left / 60), s = this.left % 60; return m + ':' + String(s).padStart(2, '0'); },
                 }"
                 x-init="
                     const t = setInterval(() => { if (left > 0) left--; else clearInterval(t); }, 1000);
                     const draw = () => {
                         if (!window.qrcode) return setTimeout(draw, 100);
                         const qr = qrcode(0, 'M');
                         qr.addData({{ \Illuminate\Support\Js::from($code->qrPayload()) }});
                         qr.make();
                         $refs.qr.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
                     };
                     draw();
                 ">
                <div class="sender-code__qr" x-ref="qr" wire:ignore aria-label="QR"></div>
                <div class="sender-code__info">
                    <div class="fs-xs text-muted">{{ __('sender.code_label') }}</div>
                    <div class="sender-code__value" dir="ltr">{{ substr($code->code, 0, 4) }} {{ substr($code->code, 4) }}</div>
                    <div class="fs-xs text-muted">{{ __('sender.server_label') }}: <span dir="ltr">{{ rtrim(config('app.url'), '/') }}</span></div>
                    <div class="sender-code__timer" :class="{ 'is-expired': left === 0 }">
                        <span x-show="left > 0">⏳ {{ __('sender.code_expires_in') }} <strong x-text="mmss" dir="ltr"></strong></span>
                        <span x-show="left === 0" x-cloak>{{ __('sender.code_expired') }}</span>
                    </div>
                    <ol class="sender-steps">
                        <li>{{ __('sender.step_open') }}</li>
                        <li>{{ __('sender.step_scan') }}</li>
                        <li>{{ __('sender.step_ready') }}</li>
                    </ol>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" class="btn btn-sm" wire:click="generateCode">↻ {{ __('sender.new_code') }}</button>
                        <button type="button" class="btn btn-sm btn-ghost" wire:click="closeCode">{{ __('common.close') }}</button>
                    </div>
                </div>
            </div>
        @else
            @if ($code)
                <p class="text-muted fs-xs">{{ __('sender.code_expired') }}</p>
            @endif
            <button type="button" class="btn btn-primary" wire:click="generateCode" wire:loading.attr="disabled">🔗 {{ __('sender.generate_code') }}</button>
        @endif
    </div>

    {{-- ===== Paired phones ===== --}}
    <div class="page-card" wire:poll.30s.visible>
        <h3 style="margin-top:0">📲 {{ __('sender.devices') }} <span class="pill pill-muted">{{ $devices->count() }}</span></h3>

        @forelse ($devices as $row)
            @php
                $d = $row['device'];
                $hb = $row['hb'];
                $online = $d->isOnline();
                $attention = $hb['attention'] ?? null;
            @endphp
            <div class="sender-device {{ $d->is_active ? '' : 'is-disabled' }}" wire:key="sd-{{ $d->id }}">
                <div class="sender-device__head">
                    <div class="sender-device__name">
                        <span class="status-dot {{ $online ? 'ok' : 'off' }}" aria-hidden="true"></span>
                        @if ($renamingId === $d->id)
                            <input type="text" class="form-input sender-rename" wire:model="renameTo" wire:keydown.enter="saveName" wire:keydown.escape="$set('renamingId', null)" maxlength="60" autofocus>
                            <button type="button" class="btn btn-sm btn-primary" wire:click="saveName">💾</button>
                            @error('renameTo') <small class="text-danger">{{ $message }}</small> @enderror
                        @else
                            <strong>{{ $d->name }}</strong>
                            <button type="button" class="btn btn-sm btn-ghost" wire:click="startRename({{ $d->id }})" title="{{ __('sender.rename') }}">✏️</button>
                        @endif
                        <span class="pill {{ !$d->is_active ? 'pill-muted' : ($online ? 'pill-success' : 'pill-warning') }}">
                            {{ !$d->is_active ? __('sender.disabled') : ($online ? __('sender.online') : __('sender.offline')) }}
                        </span>
                    </div>
                    <div class="sender-device__actions">
                        <button type="button" class="btn btn-sm {{ $d->is_active ? 'btn-soft-danger' : 'btn-soft-primary' }}" wire:click="toggle({{ $d->id }})"
                                @if ($d->is_active) wire:confirm="{{ __('sender.disable_confirm', ['name' => $d->name]) }}" @endif>
                            {{ $d->is_active ? '⏸ ' . __('sender.disable') : '▶ ' . __('sender.enable') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="delete({{ $d->id }})" wire:confirm="{{ __('sender.delete_confirm', ['name' => $d->name]) }}" title="{{ __('sender.delete') }}">🗑️</button>
                    </div>
                </div>

                <div class="sender-device__meta">
                    <span>{{ trim(($d->manufacturer ?? '') . ' ' . ($d->model ?? '')) ?: '—' }}</span>
                    @if ($d->android_version)<span>Android {{ $d->android_version }}</span>@endif
                    @if ($d->app_version)<span>{{ __('sender.app') }} {{ $d->app_version }}</span>@endif
                    <span>{{ __('sender.last_seen') }}: {{ $d->last_seen_at ? $d->last_seen_at->diffForHumans() : '—' }}</span>
                </div>

                @if ($attention && $d->is_active)
                    <div class="sender-attention">⚠️ {{ __('sender.waiting_for', ['reason' => $attentionLabels[$attention] ?? $attention]) }}</div>
                @endif

                <div class="sender-device__grid">
                    <div><span class="text-muted">{{ __('sender.whatsapp_app') }}</span>
                        <strong>{{ $waApps[$hb['whatsapp_package'] ?? ''] ?? '—' }}</strong>
                        @if (!empty($hb['whatsapp_mode']))<small class="text-muted">({{ __('sender.mode.' . $hb['whatsapp_mode']) }})</small>@endif
                    </div>
                    <div><span class="text-muted">{{ __('sender.accessibility') }}</span>
                        <strong>{{ array_key_exists('accessibility_enabled', $hb) ? ($hb['accessibility_enabled'] ? '✓' : '✗') : '—' }}</strong>
                    </div>
                    <div><span class="text-muted">{{ __('sender.sms_permission') }}</span>
                        <strong>{{ array_key_exists('sms_permission', $hb) ? ($hb['sms_permission'] ? '✓' : '✗') : '—' }}</strong>
                    </div>
                    <div><span class="text-muted">🔋 {{ __('sender.battery') }}</span>
                        <strong dir="ltr">{{ isset($hb['battery_level']) ? $hb['battery_level'] . '%' : '—' }}</strong>
                        @if (!empty($hb['battery_charging']))<small>⚡</small>@endif
                    </div>
                    <div><span class="text-muted">{{ __('sender.screen') }}</span>
                        <strong>{{ !array_key_exists('screen_on', $hb) ? '—' : (!$hb['screen_on'] ? __('sender.screen_off') : (!empty($hb['screen_locked']) ? __('sender.screen_locked') : __('sender.screen_ready'))) }}</strong>
                    </div>
                    <div><span class="text-muted">{{ __('sender.queue') }}</span>
                        <strong>{{ __('sender.queue_value', ['server' => $row['pending'], 'phone' => ($hb['pending_local'] ?? 0)]) }}</strong>
                    </div>
                    <div><span class="text-muted">{{ __('sender.sent_today') }}</span>
                        <strong>SMS {{ $hb['sent_today_sms'] ?? 0 }} · WhatsApp {{ $hb['sent_today_whatsapp'] ?? 0 }}</strong>
                    </div>
                    @if (!empty($hb['paused']))
                        <div><span class="pill pill-warning">⏸ {{ __('sender.paused_on_phone') }}</span></div>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-soft" style="text-align:center;padding:16px">{{ __('sender.no_devices') }}</p>
        @endforelse
    </div>

    {{-- ===== Sending pace ===== --}}
    <form class="page-card" wire:submit="saveConfig">
        <h3 style="margin-top:0">⏱️ {{ __('sender.config_title') }}</h3>
        <p class="field-help">⚠️ {{ __('sender.config_hint_personal_phone') }}</p>

        <div class="sender-config">
            @foreach ([
                'sender_min_delay_sec', 'sender_max_delay_sec', 'sender_batch_size', 'sender_batch_pause_sec',
                'sender_daily_cap_whatsapp', 'sender_daily_cap_sms', 'sender_poll_interval_sec', 'sender_max_attempts',
            ] as $key)
                <div class="form-group">
                    <label for="cfg-{{ $key }}">{{ __("sender.cfg.$key") }}</label>
                    <input id="cfg-{{ $key }}" type="number" class="form-input" wire:model="config.{{ $key }}" min="0" dir="ltr">
                    <div class="field-help">{{ __("sender.cfg_hint.$key") }} · {{ __('sender.default', ['value' => $defaults[$key]]) }}</div>
                    @error("config.$key") <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            @endforeach
            @foreach (['sender_window_start', 'sender_window_end'] as $key)
                <div class="form-group">
                    <label for="cfg-{{ $key }}">{{ __("sender.cfg.$key") }}</label>
                    <input id="cfg-{{ $key }}" type="time" class="form-input" wire:model="config.{{ $key }}" dir="ltr">
                    <div class="field-help">{{ __('sender.cfg_hint.sender_window') }} · {{ __('sender.default', ['value' => $defaults[$key]]) }}</div>
                    @error("config.$key") <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            @endforeach
            <div class="form-group">
                <label for="cfg-mode">{{ __('sender.cfg.sender_whatsapp_mode') }}</label>
                <select id="cfg-mode" class="form-select" wire:model="config.sender_whatsapp_mode">
                    <option value="auto">{{ __('sender.mode.auto') }}</option>
                    <option value="assisted">{{ __('sender.mode.assisted') }}</option>
                </select>
                <div class="field-help">{{ __('sender.cfg_hint.sender_whatsapp_mode') }}</div>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
            <button type="button" class="btn" wire:click="resetConfig">{{ __('sender.reset_defaults') }}</button>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveConfig">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</div>
