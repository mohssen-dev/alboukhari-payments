{{--
    The student a log row is about: open their panel or family window, or
    narrow the log to them. A deleted student is named but cannot be opened
    (their panel would not load).
--}}
@php $compact = $compact ?? false; @endphp
<div class="log-student {{ $compact ? 'is-compact' : '' }}">
    @if ($s)
        @if ($s->trashed())
            <span class="log-student__name is-deleted" title="{{ __('logs.deleted_student') }}">{{ $s->name }} <small>({{ __('logs.deleted') }})</small></span>
        @else
            <button type="button" class="log-student__name" @click="Livewire.dispatch('open-student-panel', { studentId: {{ $s->id }} })" title="{{ __('logs.open_student') }}">
                {{ $s->name }}
            </button>
        @endif
        <div class="log-student__actions">
            @unless ($s->trashed())
                @if ($s->family_id)
                    <button type="button" class="btn btn-sm btn-ghost" @click="abOpenFamily({{ $s->id }}, @js($s->name))" title="{{ __('logs.open_family') }}">👨‍👩‍👧</button>
                @endif
                <button type="button" class="btn btn-sm btn-ghost" @click="abOpenPayment({{ $s->id }}, {{ (int) date('Y') }}, {{ (int) date('n') }}, @js($s->name))" title="{{ __('logs.add_payment') }}">💶</button>
            @endunless
            @unless ($filtered)
                <button type="button" class="btn btn-sm btn-ghost" wire:click="forStudent({{ $s->id }})" title="{{ __('logs.only_this_student') }}">🔎</button>
            @endunless
        </div>
    @else
        <span class="log-student__name is-deleted">#{{ $id }}</span>
    @endif
</div>
