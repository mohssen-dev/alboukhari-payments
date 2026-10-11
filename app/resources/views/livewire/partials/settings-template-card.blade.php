{{-- One template, read-only: name, what it is used for, the text that is sent, its translation, its SMS count. --}}
@php $kind = $t->reminderKind(); @endphp
<div class="settpl-card {{ $kind ? 'is-auto' : '' }}" wire:key="settpl-{{ $t->id }}">
    <div class="settpl-card__head">
        <div class="settpl-card__title">
            <strong>{{ $t->name }}</strong>
            @if ($kind)
                <span class="pill pill-warning">⏰ {{ __('settpl.auto_' . $kind) }}</span>
            @endif
            @if ($t->isManual())
                <span class="pill pill-muted">{{ __('templates.badge_manual') }}</span>
            @endif
        </div>
        @if ($canWrite)
            <div class="settpl-card__actions">
                <button type="button" class="btn btn-sm btn-soft-primary" wire:click="edit({{ $t->id }})">✏️ {{ __('common.edit') }}</button>
                @if ($kind)
                    <span class="settpl-lock" title="{{ __('settpl.cannot_delete_reminder') }}">🔒</span>
                @else
                    <button type="button" class="btn btn-sm btn-soft-danger" wire:click="delete({{ $t->id }})"
                            wire:confirm="{{ __('settpl.delete_confirm', ['name' => $t->name]) }}"
                            title="{{ __('templates.action_delete') }}" aria-label="{{ __('templates.action_delete') }} — {{ $t->name }}">🗑️</button>
                @endif
            </div>
        @endif
    </div>

    <div class="settpl-label">🇳🇱 {{ __('settpl.sent_text') }}</div>
    <div class="settpl-body" dir="ltr">{{ $t->body }}</div>

    @if ($t->body_ar)
        <div class="settpl-label">🌐 {{ __('templates.translation_only') }}</div>
        <div class="settpl-body is-ar" dir="rtl">{{ $t->body_ar }}</div>
    @endif

    @if ($counter)
        <div class="settpl-meta">
            📲 {{ trans_choice('settpl.sms_count', $counter['segments'], ['count' => $counter['segments']]) }}
            · {{ __('settpl.chars', ['count' => $counter['length']]) }}
            @if ($counter['encoding'] === 'unicode') · <span class="text-warning">Unicode</span> @endif
        </div>
    @endif
</div>
