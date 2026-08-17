<div style="max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 1.2fr;gap:14px">
    <div class="page-card">
        <h2 style="margin-top:0">📨 {{ __('send.title') }}</h2>

        <div class="form-group">
            <label>{{ __('send.type') }}</label>
            <select class="form-select" wire:model.live="type">
                @foreach ($types as $val => $labelKey)
                    <option value="{{ $val }}">{{ __($labelKey) }}</option>
                @endforeach
            </select>
        </div>

        @if (in_array($type, ['unpaid_by_month','late_mid_month','paid_less_than','balance_above']))
            <div class="form-row cols-2">
                <div class="form-group">
                    <label>{{ __('filters.year') }}</label>
                    <select class="form-select" wire:model.live="year">
                        @for ($y = date('Y') + 1; $y >= 2020; $y--)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                </div>
                <div class="form-group">
                    <label>{{ __('filters.month') }}</label>
                    <select class="form-select" wire:model.live="month">
                        @foreach ($months as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        @if (in_array($type, ['paid_less_than','balance_above']))
            <div class="form-group">
                <label>{{ __('send.threshold') }}</label>
                <input type="number" step="0.01" class="form-input" wire:model.live="thresholdAmount">
                <small class="text-muted">
                    @if ($type === 'paid_less_than')
                        {{ __('Will send to anyone who paid less than this amount this month') }}
                    @else
                        {{ __('Will send to anyone whose accumulated balance exceeds this') }}
                    @endif
                </small>
            </div>
        @endif

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500">
                <input type="checkbox" wire:model.live="groupByFamily">
                👨‍👩‍👧 {{ __('send.group_by_family') }}
            </label>
        </div>

        <div class="form-group">
            <label>{{ __('send.template') }}</label>
            <select class="form-select" wire:model.live="templateId">
                <option value="">{{ __('send.free_text') }}</option>
                @foreach ($templates as $tpl)
                    <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label>{{ __('send.body') }}</label>
            <textarea class="form-textarea" wire:model.live.debounce.300ms="body" rows="6"></textarea>
            <x-sms-meter :counter="$counter" />
            <small class="text-muted">
                <code>@{{Naam}}</code> <code>@{{month}}</code> <code>@{{المستحق}}</code> <code>@{{المتبقي}}</code>
                @if ($groupByFamily)
                    <br>{{ __('Family:') }} <code>@{{أسماء_الأبناء}}</code> <code>@{{المبلغ_العائلي}}</code>
                @endif
            </small>
        </div>

        <div class="form-group">
            <label>{{ __('send.tag') }}</label>
            <input type="text" class="form-input" wire:model="tag" placeholder="e.g. reminder-may">
        </div>

        <hr style="border:none;border-top:1px solid var(--color-border);margin:18px 0">

        <div class="form-group">
            <label>🧪 {{ __('send.test_phone') }}</label>
            <div style="display:flex;gap:6px">
                <input type="text" class="form-input" wire:model="testPhone" placeholder="+316xxxxxxxx" style="flex:1">
                <button class="btn btn-warning" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('send.test_send') }}</button>
            </div>
        </div>

        {{-- Schedule for later --}}
        <div class="form-group" style="background:var(--color-surface-alt);padding:12px;border-radius:var(--radius)">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;margin-bottom:0">
                <input type="checkbox" wire:model.live="scheduleEnabled">
                ⏰ {{ __('send.schedule_toggle') }}
            </label>
            @if ($scheduleEnabled)
                <div style="margin-top:10px">
                    <label>{{ __('send.schedule_time') }}</label>
                    <input type="datetime-local" class="form-input" wire:model="scheduledAt" min="{{ now()->addMinutes(2)->format('Y-m-d\TH:i') }}" style="max-width:260px">
                    @error('scheduledAt') <div class="field-error">{{ $message }}</div> @enderror
                    <div class="field-help">{{ __('send.schedule_hint') }}</div>
                </div>
            @endif
        </div>

        <div style="display:flex;gap:8px;margin-top:16px">
            <button class="btn btn-soft-primary" wire:click="preview" wire:loading.attr="disabled" wire:target="preview,launch,schedule" style="flex:1">👁️ {{ __('send.preview') }}</button>
            @if ($scheduleEnabled)
                <button class="btn btn-primary" wire:click="schedule" wire:confirm="{{ __('common.confirm') }}" wire:loading.attr="disabled" wire:target="preview,launch,schedule,sendTest" style="flex:1">
                    <span wire:loading.remove wire:target="schedule">⏰ {{ __('send.schedule_btn') }}</span>
                    <span wire:loading wire:target="schedule">⏳ …</span>
                </button>
            @else
                <button class="btn btn-success" wire:click="launch" wire:confirm="{{ __('common.confirm') }}" wire:loading.attr="disabled" wire:target="preview,launch,schedule,sendTest" style="flex:1">
                    <span wire:loading.remove wire:target="launch">🚀 {{ __('send.launch') }}</span>
                    <span wire:loading wire:target="launch">⏳ {{ __('send.launching') }}</span>
                </button>
            @endif
        </div>

        @if ($resultMessage)
            <div class="pill pill-success mt-3" style="display:block;padding:10px 14px">{{ $resultMessage }}</div>
        @endif
    </div>

    <div class="page-card">
        <h3 style="margin-top:0">👁️ {{ __('send.preview') }}</h3>

        @if ($previewStats)
            <div class="kpi-grid" style="grid-template-columns:1fr 1fr">
                <div class="kpi info">
                    <div class="label">👥 {{ __('send.recipients') }}</div>
                    <div class="value">{{ $previewStats['total_recipients'] }}</div>
                </div>
                <div class="kpi warning">
                    <div class="label">📲 {{ __('send.total_segments') }}</div>
                    <div class="value">{{ $previewStats['total_segments'] }}</div>
                </div>
                <div class="kpi danger">
                    <div class="label">🚫 {{ __('send.skipped') }}</div>
                    <div class="value">{{ $previewStats['total_skipped'] }}</div>
                </div>
                <div class="kpi success">
                    <div class="label">💵 {{ __('send.estimated_cost') }}</div>
                    <div class="value" style="font-size:20px">{{ number_format($previewStats['estimated_cost'] ?? 0, 2) }} €</div>
                </div>
            </div>

            <h4 style="margin-top:14px">{{ __('Top 20 recipients') }}</h4>
            <div style="max-height:280px;overflow:auto;border:1px solid var(--color-border);border-radius:var(--radius)">
                <table style="width:100%;border-collapse:collapse;font-size:12px">
                    @foreach ($previewRecipients as $r)
                        <tr>
                            <td style="padding:7px 12px;border-bottom:1px solid var(--color-border)">{{ $r['name'] }}</td>
                            <td style="padding:7px 12px;border-bottom:1px solid var(--color-border);font-family:ui-monospace,monospace;color:var(--color-text-muted)">{{ $r['phone'] }}</td>
                            <td style="padding:7px 12px;border-bottom:1px solid var(--color-border);text-align:end">{{ $r['segments'] }} 📲</td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if (!empty($previewSkipped))
                <h4 style="margin-top:14px">{{ __('Skipped (top 20)') }}</h4>
                <div style="max-height:180px;overflow:auto;border:1px solid var(--color-border);border-radius:var(--radius)">
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        @foreach ($previewSkipped as $s)
                            <tr>
                                <td style="padding:6px 12px;border-bottom:1px solid var(--color-border)">{{ $s['name'] }}</td>
                                <td style="padding:6px 12px;border-bottom:1px solid var(--color-border);color:var(--color-text-muted)">{{ $s['reason'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            <h4 style="margin-top:14px">📝 {{ __('send.sample') }}</h4>
            <div style="background:var(--color-warning-soft);padding:12px;border-radius:var(--radius);font-size:13px;white-space:pre-wrap;line-height:1.6">{{ $previewRecipients[0]['body'] ?? '' }}</div>
        @else
            <div style="text-align:center;padding:60px 0;color:var(--color-text-soft)">
                <div style="font-size:48px;margin-bottom:8px">👁️</div>
                <div>{{ __('Click Preview to see recipients before sending') }}</div>
            </div>
        @endif
    </div>
</div>
