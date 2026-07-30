<div
    x-data="studentsGrid()"
    x-init="init()"
>
    {{-- البيانات المُمرَّرة لـ Alpine — تتجدّد تلقائياً مع كل re-render من Livewire --}}
    <script type="application/json" data-grid-rows>@json(array_values($rowsJson))</script>

    {{-- Top progress bar shown during any Livewire round-trip. Gives instant feedback while server processes. --}}
    <div class="livewire-progress" wire:loading.delay.shortest>
        <div class="livewire-progress-bar"></div>
    </div>

    @if ($focus)
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:12px;color:var(--color-text-muted)">
            <span>🎯 <strong>{{ __('grid.title_focus') }}</strong></span>
            <a href="{{ route('home') }}" wire:navigate class="btn btn-sm btn-ghost">← {{ __('grid.exit_focus') }}</a>
        </div>
    @endif

    @unless ($focus)
    {{-- ====== KPI Strip ====== --}}
    <div class="kpi-grid">
        <div class="kpi info">
            <div class="label">👥 {{ __('Students') }}</div>
            <div class="value">{{ $totalStudents }}</div>
            <div class="meta" x-text="`Showing ${visibleCount} of ${rows.length}`"></div>
        </div>
        <div class="kpi success">
            <div class="label">📅 {{ __('Year') }} / {{ __('Period') }}</div>
            <div class="value">{{ $year }}</div>
            <div class="meta">{{ \Carbon\Carbon::now()->format('F j') }}</div>
        </div>
        <div class="kpi warning">
            <div class="label">⏰ {{ __('Auto Reminders') }}</div>
            <div class="value" style="font-size:18px">
                @php
                    $firstFriday = \App\Models\Setting::get('trigger_first_friday_enabled', '1') === '1';
                    $midMonth = \App\Models\Setting::get('trigger_mid_month_enabled', '1') === '1';
                @endphp
                @if ($firstFriday && $midMonth) ✓ {{ __('Both ON') }}
                @elseif (!$firstFriday && !$midMonth) ✗ {{ __('All OFF') }}
                @else ⚠ {{ __('Partial') }}
                @endif
            </div>
        </div>
        <div class="kpi danger" x-show="selectedIds.length > 0" x-cloak>
            <div class="label">{{ __('Selected') }}</div>
            <div class="value" x-text="selectedIds.length"></div>
            <div class="meta">
                <button class="btn btn-sm btn-ghost" @click="clearSelection()">{{ __('Clear') }}</button>
            </div>
        </div>
    </div>

    {{-- ====== Actions Bar ====== --}}
    <div class="actions-bar">
        <div class="actions-group">
            <strong style="font-size:12px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">📨 {{ __('Send') }}:</strong>
            <a href="{{ route('send.form') }}?type=send_all" class="btn btn-primary btn-sm">{{ __('actions.send_bulk') }}</a>
            <a href="{{ route('send.form') }}?type=unpaid_by_month" class="btn btn-sm">{{ __('actions.send_unpaid') }}</a>
            <a href="{{ route('send.form') }}?type=late_mid_month" class="btn btn-sm">{{ __('actions.send_late') }}</a>
            <a href="{{ route('send.form') }}?type=paid_less_than" class="btn btn-sm">{{ __('actions.send_less_than') }}</a>
            <a href="{{ route('send.form') }}?type=balance_above" class="btn btn-sm">{{ __('actions.send_balance') }}</a>
        </div>

        <div class="actions-group">
            <a href="{{ route('quick-entry') }}" wire:navigate class="btn btn-warning btn-sm">⚡ {{ __('actions.quick_entry') }}</a>
            <a href="{{ route('import.form') }}" wire:navigate class="btn btn-success btn-sm">📥 {{ __('actions.import_excel') }}</a>
            <a href="{{ route('grid.focus') }}" wire:navigate class="btn btn-sm">🎯 {{ __('grid.open_focus') }}</a>
        </div>
    </div>
    @endunless

    {{-- ====== Bulk Action Bar (appears when items selected) ====== --}}
    <div class="bulk-bar" x-show="selectedIds.length > 0" x-cloak x-transition>
        <strong x-text="`${selectedIds.length} ${selectedIds.length === 1 ? '{{ __('Student') }}' : '{{ __('Students') }}'}`"></strong>
        <span style="opacity:0.7">{{ __('selected') }}</span>
        <span style="flex:1"></span>
        <button class="btn btn-sm" @click="bulk('is_hidden', true, @js(__('confirm.bulk_hide', ['count' => '__COUNT__'])))">🙈 {{ __('actions.bulk_hide') }}</button>
        <button class="btn btn-sm" @click="bulk('is_blocked_messages', true, @js(__('confirm.bulk_block', ['count' => '__COUNT__'])))">🚫 {{ __('actions.bulk_block') }}</button>
        <button class="btn btn-sm" @click="bulk('is_in_person', true, @js(__('confirm.bulk_in_person', ['count' => '__COUNT__'])))">🏠 {{ __('In-person') }}</button>
        <button class="btn btn-sm btn-danger" @click="clearSelection()">✕ {{ __('actions.bulk_clear') }}</button>
    </div>

    {{-- ====== Filters / Search (Client-side) ====== --}}
    <div class="filters-bar">
        <input
            type="text"
            class="search"
            x-model.debounce.150ms="search"
            placeholder="{{ __('topbar.search_placeholder') }} ({{ __('Press') }} /)"
        />

        <div class="filter-divider"></div>

        <select x-model="clientFilter">
            <option value="all">📋 {{ __('filters.all') }}</option>
            <option value="overdue">⚠️ {{ __('filters.has_overdue') }}</option>
            <option value="paid_full">✅ {{ __('Fully paid') }}</option>
            <option value="with_siblings">👨‍👧 {{ __('filters.with_siblings') }}</option>
        </select>

        <select wire:model.live="filterStatus">
            <option value="all">{{ __('All states') }}</option>
            <option value="visible">{{ __('filters.visible') }}</option>
            <option value="hidden">{{ __('filters.hidden') }}</option>
            <option value="blocked">{{ __('filters.blocked') }}</option>
            <option value="in_person">{{ __('filters.in_person') }}</option>
            <option value="suspended">{{ __('filters.suspended') }}</option>
        </select>

        <select wire:model.live="year">
            @for ($y = date('Y') + 1; $y >= 2020; $y--)
                <option value="{{ $y }}">{{ $y }}</option>
            @endfor
        </select>

        <select wire:model.live="perPage">
            <option value="50">50 {{ __('filters.rows_per_page') }}</option>
            <option value="100">100 {{ __('filters.rows_per_page') }}</option>
            <option value="200">200 {{ __('filters.rows_per_page') }}</option>
            <option value="500">500 {{ __('filters.rows_per_page') }}</option>
        </select>

        <span style="flex:1"></span>

        <button class="btn btn-sm" @click="exportCSV()" title="{{ __('actions.export_view') }}">
            ⬇ CSV
        </button>
    </div>

    {{-- ====== The Grid ====== --}}
    <div class="grid-wrap">
        <table class="students-grid">
            <thead>
                <tr>
                    <th class="sticky-col col-checkbox">
                        <input type="checkbox" @change="toggleAll($event)" :checked="allSelected">
                    </th>
                    <th class="sticky-col col-id" @click="sortBy('id')">
                        {{ __('columns.id') }}
                        <span class="sort-icon" :class="{ sorted: sortKey === 'id' }" x-text="sortKey === 'id' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅'"></span>
                    </th>
                    <th class="sticky-col col-name" @click="sortBy('name')">
                        {{ __('columns.name') }}
                        <span class="sort-icon" :class="{ sorted: sortKey === 'name' }" x-text="sortKey === 'name' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅'"></span>
                    </th>
                    <th>{{ __('columns.phone') }}</th>
                    <th title="{{ __('panel.siblings') }}" @click="sortBy('siblings')">
                        👨‍👧
                        <span class="sort-icon" :class="{ sorted: sortKey === 'siblings' }" x-text="sortKey === 'siblings' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span>
                    </th>
                    @foreach ($months as $num => $name)
                        <th title="{{ $name }}">{{ mb_substr($name, 0, 3) }}</th>
                    @endforeach
                    <th @click="sortBy('balance')">
                        {{ __('columns.balance') }}
                        <span class="sort-icon" :class="{ sorted: sortKey === 'balance' }" x-text="sortKey === 'balance' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅'"></span>
                    </th>
                    <th>{{ __('columns.status') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    @php
                        $siblingsCount = $student->family_id ? max(0, $student->family->students->count() - 1) : 0;
                    @endphp
                    <tr
                        wire:key="row-{{ $student->id }}"
                        :class="{ 'selected': selectedIds.includes({{ $student->id }}), 'hidden': !isVisible({{ $student->id }}) }"
                        x-show="isVisible({{ $student->id }})"
                    >
                        <td class="sticky-col col-checkbox">
                            <input
                                type="checkbox"
                                :value="{{ $student->id }}"
                                :checked="selectedIds.includes({{ $student->id }})"
                                @change="toggleOne({{ $student->id }})"
                            >
                        </td>
                        <td class="sticky-col col-id">{{ $student->external_id ?? $student->id }}</td>
                        <td class="sticky-col col-name">
                            <a href="#"
                               class="row-link"
                               wire:click.prevent="openPayment({{ $student->id }}, {{ (int) date('n') }})"
                               wire:loading.class="row-link-busy"
                               wire:target="openPayment({{ $student->id }}, {{ (int) date('n') }})"
                               title="{{ __('actions.add_payment') }} ({{ __('filters.month') }} {{ (int) date('n') }})">
                                {{ $student->name }}
                            </a>
                        </td>
                        <td style="font-family:ui-monospace,monospace;font-size:11px;color:var(--color-text-muted)">
                            {{ $student->phone_primary_e164 ?: '—' }}
                        </td>
                        <td>
                            @if ($siblingsCount > 0)
                                <span class="sibling-badge" wire:click="openFamily({{ $student->id }})" wire:loading.class="badge-busy" wire:target="openFamily({{ $student->id }})" title="{{ __('actions.show_family') }}">
                                    👨‍👧 {{ $siblingsCount + 1 }}
                                </span>
                            @else
                                <span class="sibling-badge-solo" wire:click="openFamily({{ $student->id }})" wire:loading.class="badge-busy" wire:target="openFamily({{ $student->id }})" title="{{ __('actions.show_family') }}">
                                    👤
                                </span>
                            @endif
                        </td>
                        @foreach (range(1, 12) as $m)
                            @php
                                $d = $monthData[$student->id][$m] ?? ['status' => 'not_due', 'paid' => 0, 'methodIcon' => ''];
                                $class = match ($d['status']) {
                                    'paid' => 'cell-paid',
                                    'paid_advance' => 'cell-paid-advance',
                                    'partial' => 'cell-partial',
                                    'unpaid' => 'cell-unpaid',
                                    'late' => 'cell-late',
                                    'legacy_zero' => 'cell-legacy-zero',
                                    'not_enrolled' => 'cell-not-enrolled',
                                    default => 'cell-notdue',
                                };
                                $display = match ($d['status']) {
                                    'paid', 'paid_advance', 'partial' => number_format($d['paid'], 0),
                                    'legacy_zero' => '0',
                                    'late' => 'X',
                                    'unpaid' => '·',
                                    'not_enrolled' => '−',
                                    default => '',
                                };
                            @endphp
                            <td
                                class="cell-month {{ $class }}"
                                wire:click="openPayment({{ $student->id }}, {{ $m }})"
                                wire:loading.class.delay.shortest="cell-busy"
                                wire:target="openPayment({{ $student->id }}, {{ $m }})"
                                role="button"
                                tabindex="0"
                                title="{{ $months[$m] }} — {{ \App\Services\MonthStatusResolver::label($d['status']) }} · {{ __('actions.add_payment') }}"
                            >
                                <div class="cell-content">
                                    <span class="amount">{{ $display }}</span>
                                    @if ($d['methodIcon'])
                                        <span class="method-icon">{{ $d['methodIcon'] }}</span>
                                    @endif
                                    <span class="cell-hint" aria-hidden="true">+</span>
                                </div>
                            </td>
                        @endforeach
                        @php
                            $totalBalance = $rowsJson[$student->id]['balance'];
                        @endphp
                        <td style="font-weight:700;color:{{ $totalBalance > 0 ? 'var(--color-danger)' : 'var(--color-success)' }}">
                            {{ number_format($totalBalance, 0) }}€
                        </td>
                        <td>
                            @if ($student->statusBadge())
                                <span class="status-badge" title="{{ $student->skipReason() ?? '' }}">{{ $student->statusBadge() }}</span>
                            @else
                                <span class="pill pill-success">✓</span>
                            @endif
                        </td>
                        <td>
                            <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                                <button class="icon-btn" @click="open = !open">⋮</button>
                                <div class="dropdown-menu" x-show="open" x-transition x-cloak>
                                    <a href="#" wire:click.prevent="openStudent({{ $student->id }})">👁️ {{ __('actions.view_details') }}</a>
                                    <button wire:click="openPayment({{ $student->id }}, {{ (int) date('n') }})">💶 {{ __('actions.add_payment') }}</button>
                                    <button wire:click="openFamily({{ $student->id }})">👨‍👩‍👧‍👦 {{ __('actions.show_family') }}</button>
                                    <button wire:click="openSendMessage({{ $student->id }})">📲 {{ __('actions.send_message') }}</button>
                                    <div class="divider"></div>
                                    <button wire:click="toggleFlag({{ $student->id }}, 'is_hidden')">
                                        {{ $student->is_hidden ? '👁️ Unhide' : '🙈 Hide' }}
                                    </button>
                                    <button wire:click="toggleFlag({{ $student->id }}, 'is_blocked_messages')">
                                        {{ $student->is_blocked_messages ? '✅ Unblock' : '🚫 Block messages' }}
                                    </button>
                                    <button wire:click="toggleFlag({{ $student->id }}, 'is_in_person')">
                                        {{ $student->is_in_person ? '🚪 Remove in-person' : '🏠 In-person' }}
                                    </button>
                                    <button wire:click="toggleFlag({{ $student->id }}, 'excluded_from_send_all')">
                                        {{ $student->excluded_from_send_all ? '✓ Include in bulk' : '🚷 Exclude bulk' }}
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="18" style="text-align:center;padding:60px;color:var(--color-text-soft)">
                            <div style="font-size:48px;margin-bottom:8px">📭</div>
                            <div style="margin-bottom:12px">{{ __('common.no_results') }}</div>
                            <a href="{{ route('import.form') }}" class="btn btn-primary">📥 {{ __('actions.import_excel') }}</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $students->links() }}
    </div>

    {{-- Modals + student panel live in layouts/app.blade.php, not here.
         They subscribe to events (open-payment-modal, open-family-modal, etc.)
         so opening one does NOT trigger a grid re-render. --}}
</div>

<script>
    function studentsGrid() {
        return {
            rows: [],
            search: '',
            clientFilter: 'all',
            sortKey: 'id',
            sortDir: 'asc',
            selectedIds: [],

            init() {
                // أوّل تحميل: اقرأ من العنصر <script data-grid-rows>
                this.syncRowsFromDom();
                this.$watch('search', () => { /* triggers reactivity */ });

                // تجديد البيانات مع كل تحديث من Livewire — يحلّ مشكلة perPage>100
                if (window.Livewire) {
                    const refresh = () => this.$nextTick(() => this.syncRowsFromDom());
                    Livewire.hook('morph.updated', ({ el }) => {
                        if (this.$el.contains(el)) refresh();
                    });
                    Livewire.hook('morph.added', ({ el }) => {
                        if (this.$el.contains(el)) refresh();
                    });
                    Livewire.hook('commit', ({ succeed }) => { succeed(refresh); });
                }
            },

            syncRowsFromDom() {
                const dataEl = this.$el.querySelector('script[data-grid-rows]');
                if (!dataEl) return;
                try {
                    const newRows = JSON.parse(dataEl.textContent || '[]');
                    // فقط استبدِل إذا تغيّرت الأطوال أو محتوى أوّل عنصر — تجنّب re-renders زائدة
                    if (
                        newRows.length !== this.rows.length ||
                        (newRows.length > 0 && this.rows.length > 0 && newRows[0].id !== this.rows[0].id)
                    ) {
                        this.rows = newRows;
                        // تنظيف selectedIds من الـIDs التي لم تعد ظاهرة في الجدول
                        const visibleIds = new Set(newRows.map(r => r.id));
                        this.selectedIds = this.selectedIds.filter(id => visibleIds.has(id));
                    } else {
                        // البيانات لكنها قد تكون محدّثة (مبالغ، حالات…) — استبدلها على أي حال
                        this.rows = newRows;
                    }
                } catch (e) {
                    console.error('[studentsGrid] parse error:', e);
                }
            },

            isVisible(id) {
                const row = this.rows.find(r => r.id === id);
                if (!row) return false;

                // Search
                if (this.search.trim()) {
                    if (!row.haystack.includes(this.search.toLowerCase().trim())) return false;
                }

                // Client filter
                if (this.clientFilter === 'overdue' && row.balance <= 0) return false;
                if (this.clientFilter === 'paid_full' && row.balance > 0) return false;
                if (this.clientFilter === 'with_siblings' && row.siblings === 0) return false;

                return true;
            },

            get visibleRows() {
                return this.rows.filter(r => this.isVisible(r.id));
            },

            get visibleCount() {
                return this.visibleRows.length;
            },

            get allSelected() {
                const v = this.visibleRows;
                return v.length > 0 && v.every(r => this.selectedIds.includes(r.id));
            },

            toggleAll(e) {
                const v = this.visibleRows;
                if (e.target.checked) {
                    const all = new Set([...this.selectedIds, ...v.map(r => r.id)]);
                    this.selectedIds = [...all];
                } else {
                    const visibleIds = new Set(v.map(r => r.id));
                    this.selectedIds = this.selectedIds.filter(id => !visibleIds.has(id));
                }
            },

            toggleOne(id) {
                const i = this.selectedIds.indexOf(id);
                if (i >= 0) this.selectedIds.splice(i, 1);
                else this.selectedIds.push(id);
            },

            clearSelection() {
                this.selectedIds = [];
            },

            sortBy(key) {
                if (this.sortKey === key) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortKey = key;
                    this.sortDir = 'asc';
                }
                // Sort the DOM rows by re-arranging via data
                // Since Livewire owns the DOM, we just reload sorted via JS DOM manipulation:
                this.$nextTick(() => this.applySort());
            },

            applySort() {
                const tbody = this.$el.querySelector('tbody');
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr[wire\\:key^="row-"]'));
                const key = this.sortKey;
                const dir = this.sortDir === 'asc' ? 1 : -1;
                rows.sort((a, b) => {
                    const aId = parseInt(a.getAttribute('wire:key').replace('row-', ''));
                    const bId = parseInt(b.getAttribute('wire:key').replace('row-', ''));
                    const ar = this.rows.find(r => r.id === aId);
                    const br = this.rows.find(r => r.id === bId);
                    if (!ar || !br) return 0;
                    let va = ar[key], vb = br[key];
                    if (typeof va === 'string') return va.localeCompare(vb) * dir;
                    return ((va || 0) - (vb || 0)) * dir;
                });
                rows.forEach(r => tbody.appendChild(r));
            },

            bulk(flag, value, promptTemplate) {
                if (this.selectedIds.length === 0) return;
                const msg = (promptTemplate || '').replace('__COUNT__', this.selectedIds.length);
                if (msg && !confirm(msg)) return;
                @this.bulkAction(this.selectedIds, flag, value);
                this.clearSelection();
            },

            exportCSV() {
                const v = this.visibleRows;
                if (v.length === 0) return;
                const headers = ['ID', 'Name', 'Phone', 'Siblings', 'Balance', 'Hidden', 'Blocked', 'In-person'];
                const lines = [headers.join(',')];
                v.forEach(r => {
                    lines.push([
                        r.extId || r.id,
                        '"' + (r.name || '').replace(/"/g, '""') + '"',
                        r.phone,
                        r.siblings,
                        r.balance,
                        r.isHidden ? '1' : '0',
                        r.isBlocked ? '1' : '0',
                        r.isInPerson ? '1' : '0',
                    ].join(','));
                });
                const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `students-${new Date().toISOString().slice(0,10)}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            },
        };
    }
</script>
