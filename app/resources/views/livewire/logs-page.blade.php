{{--
    The logs page (App\Livewire\LogsPage): every change and every message,
    searchable by student, filterable, and a click away from the student.
--}}
@php
    $kindLabels = [
        'payment' => '💶 ' . __('logs.kind.payment'),
        'student' => '👤 ' . __('logs.kind.student'),
        'deletion' => '🗑️ ' . __('logs.kind.deletion'),
        'bank-sync' => '🏦 ' . __('logs.kind.bank_sync'),
        'campaign' => '📨 ' . __('logs.kind.campaign'),
        'user' => '🔐 ' . __('logs.kind.user'),
        'settings' => '⚙️ ' . __('logs.kind.settings'),
        'sender_device' => '📱 ' . __('logs.kind.sender_device'),
    ];
    $typeLabel = function (?string $type) {
        return match ($type) {
            'test' => __('logs.msg_type.test'),
            'manual_single' => __('logs.msg_type.single'),
            null, '' => '—',
            default => (($l = __('campaigns.type.' . $type)) === 'campaigns.type.' . $type ? $type : $l),
        };
    };
    $dayLabel = function (\Carbon\CarbonInterface $d) {
        if ($d->isToday()) return __('logs.today');
        if ($d->isYesterday()) return __('logs.yesterday');
        return $d->day . ' ' . (\App\Services\MonthNames::full()[$d->month] ?? '') . ' ' . $d->year;
    };
    // The student block on a row: open the panel / family window, or narrow the log to them.
    $studentBlock = function (?int $id) use ($students) {
        return $id ? $students->get($id) : null;
    };
@endphp
<div class="logs-page">
    <div class="page-header">
        <div>
            <h1 style="margin:0">🕘 {{ __('logs.title') }}</h1>
            <p class="text-muted" style="margin:4px 0 0;font-size:13px">{{ __('logs.subtitle') }}</p>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="logs-tabs" role="tablist">
        <button type="button" role="tab" class="{{ $tab === 'activity' ? 'is-on' : '' }}" aria-selected="{{ $tab === 'activity' ? 'true' : 'false' }}" wire:click="$set('tab', 'activity')">📋 {{ __('logs.tab_activity') }}</button>
        <button type="button" role="tab" class="{{ $tab === 'messages' ? 'is-on' : '' }}" aria-selected="{{ $tab === 'messages' ? 'true' : 'false' }}" wire:click="$set('tab', 'messages')">📨 {{ __('logs.tab_messages') }}</button>
    </div>

    {{-- Filters --}}
    <div class="page-card logs-filters">
        <div class="logs-filters__row">
            <label class="logs-field logs-field--search">
                <span>{{ __('logs.search') }}</span>
                <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="{{ __('logs.search_placeholder') }}" @disabled($studentId)>
            </label>

            @if ($tab === 'activity')
                <label class="logs-field">
                    <span>{{ __('logs.kind_label') }}</span>
                    <select class="form-select" wire:model.live="kind">
                        <option value="">{{ __('logs.all') }}</option>
                        @foreach ($kinds as $k)
                            <option value="{{ $k }}">{{ $kindLabels[$k] ?? $k }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="logs-field">
                    <span>{{ __('logs.action_label') }}</span>
                    <select class="form-select" wire:model.live="event">
                        <option value="">{{ __('logs.all') }}</option>
                        <option value="created">➕ {{ __('logs.action.created') }}</option>
                        <option value="updated">✏️ {{ __('logs.action.updated') }}</option>
                        <option value="deleted">🗑️ {{ __('logs.action.deleted') }}</option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>{{ __('logs.user_label') }}</span>
                    <select class="form-select" wire:model.live="causer">
                        <option value="">{{ __('logs.all') }}</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <label class="logs-field">
                    <span>{{ __('logs.status_label') }}</span>
                    <select class="form-select" wire:model.live="status">
                        <option value="">{{ __('logs.all') }}</option>
                        <option value="ok">✓ {{ __('logs.status.ok') }}</option>
                        <option value="failed">✗ {{ __('logs.status.failed') }}</option>
                    </select>
                </label>
            @endif

            <label class="logs-field logs-field--date">
                <span>{{ __('logs.from') }}</span>
                <input type="date" class="form-input" wire:model.live="from" max="{{ now()->format('Y-m-d') }}">
            </label>
            <label class="logs-field logs-field--date">
                <span>{{ __('logs.to') }}</span>
                <input type="date" class="form-input" wire:model.live="to" max="{{ now()->format('Y-m-d') }}">
            </label>
        </div>

        <div class="logs-filters__foot">
            <span class="text-muted fs-xs" wire:loading.remove>{{ trans_choice('logs.count', $page->total(), ['count' => number_format($page->total())]) }}</span>
            <span class="text-muted fs-xs" wire:loading><span class="spinner-sm"></span> {{ __('logs.loading') }}</span>
            @if ($filterStudent)
                <span class="logs-student-chip">
                    🔎 {{ __('logs.history_of', ['name' => $filterStudent->name]) }}
                    @unless ($filterStudent->trashed())
                        <button type="button" class="btn btn-sm btn-ghost" @click="Livewire.dispatch('open-student-panel', { studentId: {{ $filterStudent->id }} })">{{ __('logs.open_student') }}</button>
                    @endunless
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('studentId', null)" aria-label="{{ __('logs.clear') }}">✕</button>
                </span>
            @endif
            @if ($hasFilters)
                <button type="button" class="btn btn-sm" wire:click="clearFilters">{{ __('logs.clear_filters') }}</button>
            @endif
        </div>
    </div>

    <div class="logs-results" wire:loading.class="is-loading">
        @if ($tab === 'activity')
            @php $lastDay = null; @endphp
            @forelse ($rows as $row)
                @php
                    $a = $row['activity'];
                    $day = $a->created_at?->copy()->timezone(config('app.timezone'));
                    $s = $studentBlock($row['student_id']);
                @endphp
                @if ($day && $day->toDateString() !== $lastDay)
                    @php $lastDay = $day->toDateString(); @endphp
                    <div class="logs-day">{{ $dayLabel($day) }}</div>
                @endif
                <div class="log-row is-{{ $row['tone'] }}" wire:key="log-{{ $a->id }}">
                    <div class="log-row__icon" aria-hidden="true">{{ $row['icon'] }}</div>
                    <div class="log-row__main">
                        <div class="log-row__title">{{ $row['title'] }}</div>
                        @foreach ($row['lines'] as $line)
                            <div class="log-row__line">{{ $line }}</div>
                        @endforeach
                        <div class="log-row__meta">
                            <span title="{{ $day?->format('Y-m-d H:i:s') }}">🕘 {{ $day?->format('H:i') }}</span>
                            <span>👤 {{ $a->causer?->name ?? __('logs.system') }}</span>
                        </div>
                    </div>
                    @if ($row['student_id'])
                        @include('livewire.partials.log-student', ['s' => $s, 'id' => $row['student_id'], 'filtered' => (bool) $studentId])
                    @endif
                </div>
            @empty
                <div class="page-card logs-empty">📭 {{ __('logs.empty') }}</div>
            @endforelse
        @else
            @if ($page->total() === 0)
                <div class="page-card logs-empty">📭 {{ __('logs.empty') }}</div>
            @else
                <div class="page-card" style="padding:0;overflow-x:auto">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>{{ __('logs.col_when') }}</th>
                                <th>{{ __('logs.col_student') }}</th>
                                <th>{{ __('logs.col_message') }}</th>
                                <th>{{ __('logs.col_status') }}</th>
                                <th>{{ __('logs.col_cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($page as $m)
                                @php
                                    $failed = str_starts_with((string) $m->status, 'ERROR');
                                    $s = $studentBlock($m->student_id);
                                    $when = $m->created_at?->copy()->timezone(config('app.timezone'));
                                @endphp
                                <tr wire:key="msg-{{ $m->id }}">
                                    <td class="logs-table__when">
                                        <strong>{{ $when?->format('d-m-Y') }}</strong>
                                        <small>{{ $when?->format('H:i') }}</small>
                                    </td>
                                    <td>
                                        @if ($m->student_id)
                                            @include('livewire.partials.log-student', ['s' => $s, 'id' => $m->student_id, 'filtered' => (bool) $studentId, 'compact' => true])
                                        @endif
                                        <div class="fs-xs text-muted" dir="ltr" style="text-align:start">{{ $m->phone }}</div>
                                    </td>
                                    <td class="logs-table__msg">
                                        <div class="fs-xs text-muted">
                                            {{ $typeLabel($m->type) }}
                                            @if ($m->campaign_id)
                                                · <a href="{{ route('campaigns.show', $m->campaign_id) }}" wire:navigate>{{ __('logs.campaign_n', ['id' => $m->campaign_id]) }}</a>
                                            @endif
                                        </div>
                                        <details>
                                            <summary dir="auto">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $m->body), 90) }}</summary>
                                            <p dir="auto">{{ $m->body }}</p>
                                        </details>
                                    </td>
                                    <td>
                                        @if ($failed)
                                            <span class="pill pill-danger" title="{{ $m->status }}">✗ {{ __('logs.status.failed') }}</span>
                                            <div class="fs-xs text-danger" style="max-width:220px">{{ \Illuminate\Support\Str::limit(substr((string) $m->status, 7), 80) }}</div>
                                        @else
                                            <span class="pill pill-success" title="{{ $m->status }}">✓ {{ __('logs.status.ok') }}</span>
                                        @endif
                                    </td>
                                    <td class="fs-xs" dir="ltr">{{ $m->cost !== null ? number_format((float) $m->cost, 2) . ' €' : '—' }}<br><span class="text-muted">{{ $m->segments }} SMS</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>

    {{-- Pages --}}
    @if ($page->lastPage() > 1)
        <div class="logs-pager">
            <button type="button" class="btn btn-sm" wire:click="previousPage" @disabled($page->onFirstPage())>→ {{ __('logs.newer') }}</button>
            <span class="text-muted fs-xs">{{ __('logs.page_of', ['page' => $page->currentPage(), 'pages' => $page->lastPage()]) }}</span>
            <button type="button" class="btn btn-sm" wire:click="nextPage" @disabled(!$page->hasMorePages())>{{ __('logs.older') }} ←</button>
        </div>
    @endif
</div>
