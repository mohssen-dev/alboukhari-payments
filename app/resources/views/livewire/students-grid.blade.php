<div
    x-data="studentsGrid({
        nowMonth: {{ (int) date('n') }},
        clientFilter: @js($clientFilter),
        statementUrl: @js(route('exports.statement', '__ID__')),
        t: { showing: @js(__('grid.showing')) },
    })"
    @grid-row-updated.window="patchRow($event.detail)"
    @grid-show-row.window="showRow($event.detail.id)"
>
    {{-- Row data for client-side search / filters / sort / CSV — re-read after every grid render. --}}
    <script type="application/json" data-grid-rows>@json(array_values(array_column($built, 'row')))</script>

    {{-- Top progress bar shown during any Livewire round-trip. Gives instant feedback while server processes. --}}
    <div class="livewire-progress" wire:loading.delay.shortest>
        <div class="livewire-progress-bar"></div>
    </div>

    @if ($focus)
        <div class="grid-focus-bar">
            <span class="grid-focus-title">🎯 <strong>{{ __('grid.title_focus') }}</strong></span>
            <div class="grid-focus-actions">
                @if (auth()->user()?->canWrite())
                    <button type="button" class="btn btn-primary btn-sm" @click="Livewire.dispatch('open-student-form', { studentId: null })">{{ __('student.add') }}</button>
                @endif
                <a href="{{ route('home') }}" wire:navigate class="btn btn-sm btn-ghost">← {{ __('grid.exit_focus') }}</a>
            </div>
        </div>
    @endif

    @unless ($focus)
    {{-- ====== KPI Strip ====== --}}
    <div class="kpi-grid">
        <div class="kpi info">
            <div class="label">👥 {{ __('Students') }}</div>
            <div class="value">{{ $totalStudents }}</div>
            <div class="meta" x-text="showingText()"></div>
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
            @if (auth()->user()?->canWrite())
                <button type="button" class="btn btn-primary btn-sm" @click="Livewire.dispatch('open-student-form', { studentId: null })">{{ __('student.add') }}</button>
            @endif
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
        @if (auth()->user()?->isAdmin())
            <button class="btn btn-sm btn-soft-danger" @click="Livewire.dispatch('open-delete-students', { studentIds: [...selectedIds] })">{{ __('delete.selected_button') }}</button>
        @endif
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
            <option value="deleted">{{ __('filters.deleted') }}</option>
        </select>

        <select wire:model.live="year">
            @foreach (\App\Livewire\StudentsGrid::yearRange() as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>

        <select wire:model.live="perPage">
            @foreach (\App\Livewire\StudentsGrid::PER_PAGE as $n)
                <option value="{{ $n }}">{{ $n }} {{ __('filters.rows_per_page') }}</option>
            @endforeach
        </select>

        <span style="flex:1"></span>

        <button class="btn btn-sm" @click="exportCSV()" title="{{ __('actions.export_view') }}">
            ⬇ CSV
        </button>
    </div>

    {{-- ====== The Grid ====== --}}
    <div class="grid-wrap" @scroll.passive="menu.open = false">
        <table class="students-grid">
            <thead>
                <tr>
                    <th class="sticky-col col-checkbox">
                        <input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allSelected">
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
            {{-- One delegated listener per event for the whole table (see partials/grid-row). --}}
            <tbody x-ref="tbody" @click="onClick($event)" @change="onChange($event)" @keydown="onKeydown($event)">
                @forelse ($students as $student)
                    @include('livewire.partials.grid-row', ['student' => $student, 'built' => $built[$student->id]])
                @empty
                    <tr>
                        <td colspan="20" style="text-align:center;padding:60px;color:var(--color-text-soft)">
                            <div style="font-size:48px;margin-bottom:8px">📭</div>
                            <div style="margin-bottom:12px">{{ __('common.no_results') }}</div>
                            <a href="{{ route('import.form') }}" class="btn btn-primary">📥 {{ __('actions.import_excel') }}</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Rows exist but the search / client filter hides them all (e.g. a remembered filter). --}}
    <div class="grid-filtered-empty" x-show="totalCount > 0 && visibleCount === 0" x-cloak>
        <span>🔎 {{ __('grid.filtered_empty') }}</span>
        <button type="button" class="btn btn-sm" @click="search = ''; clientFilter = 'all'">{{ __('grid.clear_filter') }}</button>
    </div>

    <div class="mt-3">
        {{ $students->links() }}
    </div>

    {{-- ====== Row actions: ONE shared menu (was one Alpine dropdown per row) ====== --}}
    <div
        class="dropdown-menu row-menu"
        x-show="menu.open"
        x-cloak
        :style="{ top: menu.top, bottom: menu.bottom, left: menu.left, right: menu.right }"
        @click.window="if (menu.open && !$el.contains($event.target) && !$event.target.closest('[data-act=menu]')) menu.open = false"
        @keydown.window.escape="menu.open = false"
        @scroll.window.passive="menu.open = false"
    >
        <template x-if="!menu.row?.isDeleted">
            <div>
                <button type="button" @click="menuDo('details')">👁️ {{ __('actions.view_details') }}</button>
                <button type="button" @click="menuDo('pay')">💶 {{ __('actions.add_payment') }}</button>
                <button type="button" @click="menuDo('family')">👨‍👩‍👧‍👦 {{ __('actions.show_family') }}</button>
                <button type="button" @click="menuDo('message')">📲 {{ __('actions.send_message') }}</button>
                @if (auth()->user()?->canWrite())
                    <button type="button" @click="menuDo('edit')">{{ __('student.edit') }}</button>
                    <div class="divider"></div>
                    <button type="button" @click="menuFlag('is_hidden')" x-text="menu.row?.isHidden ? @js(__('grid.row_unhide')) : @js(__('grid.row_hide'))"></button>
                    <button type="button" @click="menuFlag('is_blocked_messages')" x-text="menu.row?.isBlocked ? @js(__('grid.row_unblock')) : @js(__('grid.row_block'))"></button>
                    <button type="button" @click="menuFlag('is_in_person')" x-text="menu.row?.isInPerson ? @js(__('grid.row_not_in_person')) : @js(__('grid.row_in_person'))"></button>
                    <button type="button" @click="menuFlag('excluded_from_send_all')" x-text="menu.row?.excludedSendAll ? @js(__('grid.row_include_bulk')) : @js(__('grid.row_exclude_bulk'))"></button>
                @endif
                @if (auth()->user()?->isAdmin())
                    <div class="divider"></div>
                    <button type="button" class="danger" @click="menuDo('delete')">{{ __('delete.student_button') }}</button>
                @endif
            </div>
        </template>
        {{-- A deleted student: its payment record and, for admins, the way back. --}}
        <template x-if="menu.row?.isDeleted">
            <div>
                <button type="button" @click="menuDo('statement')">{{ __('grid.row_statement') }}</button>
                @if (auth()->user()?->isAdmin())
                    <button type="button" @click="menuDo('restore')">{{ __('grid.row_restore') }}</button>
                @endif
            </div>
        </template>
    </div>

    {{-- Modals + student panel live in layouts/app.blade.php, not here.
         They subscribe to events (open-payment-modal, open-family-modal, etc.)
         so opening one does NOT trigger a grid re-render. --}}
</div>

<script>
    function studentsGrid(cfg) {
        // Plain, non-reactive state kept out of Alpine's proxies: per-row
        // lookups stay O(1) and touching them never re-triggers any binding.
        // (Each row used to run `rows.find()` inside its own x-show/:class,
        // i.e. O(n²) work on every keystroke and every refresh.)
        const index = new Map();
        let offMorphed = null;
        // A just-added student stays visible whatever the search/filter,
        // until the user changes one of them.
        let pinnedId = null;

        return {
            search: '',
            clientFilter: cfg.clientFilter || 'all',
            sortKey: 'id',
            sortDir: 'asc',
            selectedIds: [],
            visibleCount: 0,
            totalCount: 0,
            allSelected: false,
            menu: { open: false, id: null, row: null, top: 'auto', bottom: 'auto', left: 'auto', right: 'auto' },

            init() {
                this.readRows();
                this.applyView();
                this.$watch('search', () => { pinnedId = null; this.applyView(); });
                this.$watch('clientFilter', (value) => {
                    pinnedId = null;
                    this.applyView();
                    this.$wire.rememberClientFilter(value); // survives a reload (cookie)
                });

                // Once per grid render (filters, paging, bulk actions) — not once
                // per morphed element: the old 'morph.updated' hook re-parsed the
                // row data hundreds of times per render, and was registered again
                // on every visit without ever being removed.
                const myId = this.$wire.$id;
                offMorphed = Livewire.hook('morphed', ({ component }) => {
                    if (component.id !== myId) return;
                    this.readRows();
                    if (this.sortKey !== 'id' || this.sortDir !== 'asc') this.applySort();
                    this.applyView();
                });
            },

            destroy() {
                if (offMorphed) offMorphed();
            },

            readRows() {
                const el = this.$el.querySelector('script[data-grid-rows]');
                index.clear();
                try {
                    JSON.parse(el?.textContent || '[]').forEach(r => index.set(r.id, r));
                } catch (e) {
                    console.error('[studentsGrid] bad row data', e);
                }
                this.totalCount = index.size;
                this.selectedIds = this.selectedIds.filter(id => index.has(id));
                this.menu.open = false;
            },

            rowEls() {
                return this.$refs.tbody ? this.$refs.tbody.querySelectorAll('tr[data-sid]') : [];
            },

            matches(row, q, f) {
                if (row.id === pinnedId) return true;
                if (q && !row.haystack.includes(q)) return false;
                if (f === 'overdue' && row.balance <= 0) return false;
                if (f === 'paid_full' && row.balance > 0) return false;
                if (f === 'with_siblings' && row.siblings === 0) return false;
                return true;
            },

            // One DOM pass applies search, filter and selection to every row.
            applyView() {
                const q = this.search.trim().toLowerCase();
                const f = this.clientFilter;
                const picked = new Set(this.selectedIds);
                let shown = 0, shownPicked = 0;
                this.rowEls().forEach(tr => {
                    const id = +tr.dataset.sid;
                    const row = index.get(id);
                    const show = !!row && this.matches(row, q, f);
                    const isPicked = picked.has(id);
                    tr.classList.toggle('row-filtered', !show);
                    tr.classList.toggle('selected', isPicked);
                    const cb = tr.querySelector('.row-check');
                    if (cb) cb.checked = isPicked;
                    if (show) { shown++; if (isPicked) shownPicked++; }
                });
                this.visibleCount = shown;
                this.allSelected = shown > 0 && shownPicked === shown;
            },

            visibleRows() {
                return Array.from(this.rowEls())
                    .filter(tr => !tr.classList.contains('row-filtered'))
                    .map(tr => index.get(+tr.dataset.sid))
                    .filter(Boolean);
            },

            showingText() {
                return cfg.t.showing.replace(':shown', this.visibleCount).replace(':total', this.totalCount);
            },

            // ---- Delegated row events ----
            onClick(e) {
                const act = e.target.closest('[data-act]');
                const tr = act && act.closest('tr[data-sid]');
                if (!tr) return;
                e.preventDefault();
                const id = +tr.dataset.sid;
                if (act.dataset.act === 'pay') this.openPay(id, +act.dataset.m);
                else if (act.dataset.act === 'family') Livewire.dispatch('open-family-modal', { studentId: id });
                else if (act.dataset.act === 'menu') this.openMenu(id, act);
            },

            onChange(e) {
                if (!e.target.classList.contains('row-check')) return;
                this.toggleOne(+e.target.closest('tr[data-sid]').dataset.sid, e.target.checked);
            },

            onKeydown(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const cell = e.target.closest('.cell-month[data-act="pay"]');
                if (!cell) return;
                e.preventDefault();
                this.openPay(+cell.closest('tr[data-sid]').dataset.sid, +cell.dataset.m);
            },

            openPay(id, month) {
                this.menu.open = false;
                const row = index.get(id);
                abOpenPayment(id, Number(this.$wire.year), month, row ? row.name : '');
            },

            // ---- In-place row patch after a change (App\Support\GridRow) ----
            patchRow(d) {
                if (!d || !d.html) return;
                const old = this.$refs.tbody?.querySelector(`tr[data-sid="${d.id}"]`);
                if (!old) return; // not on this page — nothing on screen changed
                // Computed for another year than the one shown: a payment there
                // changes nothing on screen, but a student-level change (name,
                // phone, flags) does — then fall back to a normal render.
                if (Number(d.year) !== Number(this.$wire.year)) {
                    if (d.scope !== 'year') this.$wire.$refresh();
                    return;
                }
                const tpl = document.createElement('template');
                tpl.innerHTML = d.html.trim();
                const fresh = tpl.content.querySelector('tr');
                if (!fresh) return;
                old.replaceWith(fresh);
                index.set(d.id, d.row);
                if (this.menu.id === d.id) this.menu.row = d.row;
                this.applyView();
                fresh.classList.add('row-flash');
                setTimeout(() => fresh.classList.remove('row-flash'), 900);
            },

            // ---- A just-added student: the server already moved to its page ----
            showRow(id, tries = 0) {
                const tr = this.$refs.tbody?.querySelector(`tr[data-sid="${id}"]`);
                if (!tr) {
                    // The event can arrive before the page's rows are morphed in.
                    if (tries < 20) setTimeout(() => this.showRow(id, tries + 1), 50);
                    return;
                }
                pinnedId = id;
                this.applyView();
                tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
                tr.classList.add('row-new');
                setTimeout(() => tr.classList.remove('row-new'), 2500);
            },

            // ---- Row actions menu ----
            openMenu(id, btn) {
                if (this.menu.open && this.menu.id === id) { this.menu.open = false; return; }
                const r = btn.getBoundingClientRect();
                const rtl = document.documentElement.dir === 'rtl';
                const below = window.innerHeight - r.bottom > 320;
                Object.assign(this.menu, {
                    id,
                    row: index.get(id) || null,
                    open: true,
                    top: below ? (r.bottom + 4) + 'px' : 'auto',
                    bottom: below ? 'auto' : (window.innerHeight - r.top + 4) + 'px',
                    left: rtl ? r.left + 'px' : 'auto',
                    right: rtl ? 'auto' : (document.documentElement.clientWidth - r.right) + 'px',
                });
            },

            menuDo(action) {
                const id = this.menu.id;
                this.menu.open = false;
                if (!id) return;
                if (action === 'pay') this.openPay(id, cfg.nowMonth);
                else if (action === 'details') Livewire.dispatch('open-student-panel', { studentId: id });
                else if (action === 'family') Livewire.dispatch('open-family-modal', { studentId: id });
                else if (action === 'message') Livewire.dispatch('open-send-message', { studentId: id });
                else if (action === 'edit') Livewire.dispatch('open-student-form', { studentId: id });
                else if (action === 'delete') Livewire.dispatch('open-delete-student', { studentId: id });
                else if (action === 'restore') this.$wire.restoreStudent(id);
                else if (action === 'statement') window.open(cfg.statementUrl.replace('__ID__', id) + '?year=' + Number(this.$wire.year), '_blank', 'noopener');
            },

            menuFlag(flag) {
                const id = this.menu.id;
                this.menu.open = false;
                if (id) this.$wire.toggleFlag(id, flag);
            },

            // ---- Selection ----
            toggleOne(id, on) {
                const has = this.selectedIds.includes(id);
                if (on && !has) this.selectedIds.push(id);
                if (!on && has) this.selectedIds = this.selectedIds.filter(x => x !== id);
                this.applyView();
            },

            toggleAll(on) {
                // Deleted rows (the "Deleted" filter) are a read-only record — never selectable.
                const ids = this.visibleRows().filter(r => !r.isDeleted).map(r => r.id);
                if (on) {
                    this.selectedIds = [...new Set([...this.selectedIds, ...ids])];
                } else {
                    const drop = new Set(ids);
                    this.selectedIds = this.selectedIds.filter(id => !drop.has(id));
                }
                this.applyView();
            },

            clearSelection() {
                this.selectedIds = [];
                this.applyView();
            },

            // ---- Sorting (client-side reorder of the rendered rows) ----
            sortBy(key) {
                if (this.sortKey === key) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortKey = key;
                    this.sortDir = 'asc';
                }
                this.applySort();
            },

            applySort() {
                const tbody = this.$refs.tbody;
                if (!tbody) return;
                const key = this.sortKey;
                const dir = this.sortDir === 'asc' ? 1 : -1;
                const trs = Array.from(this.rowEls());
                trs.sort((a, b) => {
                    const ra = index.get(+a.dataset.sid);
                    const rb = index.get(+b.dataset.sid);
                    if (!ra || !rb) return 0;
                    const va = ra[key], vb = rb[key];
                    if (typeof va === 'string') return va.localeCompare(vb) * dir;
                    return ((va || 0) - (vb || 0)) * dir;
                });
                trs.forEach(tr => tbody.appendChild(tr));
            },

            bulk(flag, value, promptTemplate) {
                if (this.selectedIds.length === 0) return;
                const msg = (promptTemplate || '').replace('__COUNT__', this.selectedIds.length);
                if (msg && !confirm(msg)) return;
                this.$wire.bulkAction([...this.selectedIds], flag, value);
                this.clearSelection();
            },

            exportCSV() {
                const v = this.visibleRows();
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
