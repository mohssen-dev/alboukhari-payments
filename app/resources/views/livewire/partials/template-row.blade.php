{{-- One row of the templates table: Dutch text with its Arabic translation underneath. --}}
<tr wire:key="tpl-{{ $t->id }}">
    <td style="text-align:start;padding:10px 16px">
        <strong>{{ $t->name }}</strong>
        <div class="fs-xs text-muted" style="font-family:ui-monospace,monospace" dir="ltr">{{ $t->code }}</div>
        <div class="tpl-cell-body" dir="ltr">{{ $t->body }}</div>
        @if ($t->body_ar)
            <div class="tpl-cell-body is-ar" dir="rtl">{{ $t->body_ar }}</div>
        @endif
    </td>
    <td>
        <span class="pill pill-info">{{ strtoupper($t->language) }}</span>
        @if ($t->body_ar)
            <div class="mt-2"><span class="pill pill-success">{{ __('templates.badge_translation') }}</span></div>
        @endif
        @if ($t->isManual())
            <div class="mt-2"><span class="pill pill-muted">{{ __('templates.badge_manual') }}</span></div>
        @endif
    </td>
    <td>
        @if ($t->default_for !== 'none')
            <span class="pill pill-warning">{{ $defaultForLabel($t->default_for) }}</span>
        @else
            <span class="text-soft">—</span>
        @endif
    </td>
    <td style="white-space:nowrap">
        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="edit({{ $t->id }})" title="{{ __('templates.action_edit') }}" aria-label="{{ __('templates.action_edit') }}">✏️</button>
        <button type="button" class="btn btn-sm" wire:click="duplicate({{ $t->id }})" title="{{ __('templates.action_duplicate') }}" aria-label="{{ __('templates.action_duplicate') }}">📋</button>
        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="delete({{ $t->id }})" wire:confirm="{{ __('common.confirm') }}" title="{{ __('templates.action_delete') }}" aria-label="{{ __('templates.action_delete') }}">🗑️</button>
    </td>
</tr>
