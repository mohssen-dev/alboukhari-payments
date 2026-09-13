{{--
    One students-grid row. Rendered by the full grid AND, on its own, for the
    in-place patch after a change (App\Support\GridRow) — keep it the only
    place row markup lives.

    No wire:click / wire:loading here on purpose: at 100 rows those came to
    ~2,200 wire:click and ~1,400 wire:loading directives that Livewire walked
    on every request. Clicks are delegated from <tbody> via data-act instead.
--}}
@php
    $row = $built['row'];
    $nowMonth = (int) date('n');
    // A deleted student is on screen only for its payment record: nothing opens.
    $live = !$row['isDeleted'];
@endphp
<tr wire:key="row-{{ $student->id }}" data-sid="{{ $student->id }}" class="{{ $live ? '' : 'row-deleted' }}">
    <td class="sticky-col col-checkbox"><input type="checkbox" class="row-check" aria-label="{{ $student->name }}" {{ $live ? '' : 'disabled' }}></td>
    <td class="sticky-col col-id">{{ $student->external_id ?? $student->id }}</td>
    <td class="sticky-col col-name">
        <a href="#" class="row-link" data-act="{{ $live ? 'pay' : 'noop' }}" data-m="{{ $nowMonth }}" title="{{ $live ? __('actions.add_payment') . ' (' . __('filters.month') . ' ' . $nowMonth . ')' : $row['skipReason'] }}">{{ $student->name }}</a>
    </td>
    <td class="col-phone">{{ $student->phone_primary_e164 ?: '—' }}</td>
    <td>
        @if ($row['siblings'] > 0)
            <span class="sibling-badge" data-act="{{ $live ? 'family' : 'noop' }}" title="{{ __('actions.show_family') }}">👨‍👧 {{ $row['siblings'] + 1 }}</span>
        @else
            <span class="sibling-badge-solo" data-act="{{ $live ? 'family' : 'noop' }}" title="{{ __('actions.show_family') }}">👤</span>
        @endif
    </td>
    @foreach ($built['cells'] as $m => $c)
        <td class="cell-month {{ $c['class'] }}" data-act="{{ $live ? 'pay' : 'noop' }}" data-m="{{ $m }}" role="{{ $live ? 'button' : 'cell' }}" tabindex="{{ $live ? '0' : '-1' }}" title="{{ $months[$m] }} — {{ $c['label'] }}{{ $live ? ' · ' . __('actions.add_payment') : '' }}"><div class="cell-content"><span class="amount">{{ $c['display'] }}</span><span class="method-icon">{{ $c['methodIcon'] }}</span><span class="cell-hint" aria-hidden="true">+</span></div></td>
    @endforeach
    <td class="col-balance {{ $row['balance'] > 0 ? 'is-owed' : 'is-clear' }}">{{ number_format($row['balance'], 0) }}€</td>
    <td>
        @if ($row['badge'])
            <span class="status-badge" title="{{ $row['skipReason'] ?? '' }}">{{ $row['badge'] }}</span>
        @else
            <span class="pill pill-success">✓</span>
        @endif
    </td>
    <td><button type="button" class="icon-btn" data-act="menu" aria-label="{{ __('grid.row_actions') }}">⋮</button></td>
</tr>
