@extends('layouts.app')

@section('content')
<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </span>
                {{ __('activity.title') }}
            </h1>
            <p class="page-sub">{{ __('activity.subtitle') }}</p>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('activity.when') }}</th>
                    <th>{{ __('activity.who') }}</th>
                    <th>{{ __('activity.action') }}</th>
                    <th>{{ __('activity.subject') }}</th>
                    <th>{{ __('activity.details') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activities as $a)
                    @php
                        $event = $a->event ?? 'updated';
                        $tagClass = in_array($event, ['created', 'updated', 'deleted']) ? $event : 'updated';
                    @endphp
                    <tr>
                        <td class="activity-cell">
                            <span class="when">{{ $a->created_at?->format('Y-m-d H:i') }}</span>
                            <small>{{ $a->created_at?->diffForHumans() }}</small>
                        </td>
                        <td>
                            @if($a->causer)
                                <span class="activity-actor">
                                    <span class="avatar-mini">{{ mb_strtoupper(mb_substr($a->causer->name, 0, 1)) }}</span>
                                    <span>{{ $a->causer->name }}</span>
                                </span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="activity-tag {{ $tagClass }}">{{ $event }}</span>
                            @if($a->description && $a->description !== $event)
                                <span class="muted" style="font-size:11px;display:block;margin-top:2px">{{ $a->description }}</span>
                            @endif
                        </td>
                        <td>
                            @if($a->subject_type)
                                <strong>{{ class_basename($a->subject_type) }}</strong>
                                @if($a->subject_id)
                                    <span class="muted">#{{ $a->subject_id }}</span>
                                @endif
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($a->properties && $a->properties->count())
                                <details>
                                    <summary style="cursor:pointer;color:var(--color-primary);font-size:12px">{{ __('activity.show_details') }}</summary>
                                    <pre class="json">{{ json_encode($a->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <div class="empty">
                            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            {{ __('common.no_results') }}
                        </div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2">{{ $activities->links() }}</div>
</div>
@endsection
