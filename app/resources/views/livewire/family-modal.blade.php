{{--
    Family window. On top: every child's payments for the chosen year (click
    a month to pick it). Below: the payment form for the picked month(s) —
    one amount per child.

    One month: the amount is the month's TOTAL — raise it to record more,
    lower it (or clear it) to reduce what was recorded.
    Several months: the amount is PER MONTH and only tops up months holding
    less; nothing is reduced.

    Smooth by design:
      - opens INSTANTLY with a skeleton (browser event 'family-open', see
        abOpenFamily in the layout) and fetches its content in one round-trip;
      - picking months, suggested amounts and the totals are worked out in the
        browser from $members — no round-trip; only the year, a save and the
        enrolment buttons go to the server;
      - opens on the family's oldest unpaid month (FamilyModal::defaultMonths).
--}}
<div
    x-data="{
        pending: false,
        hiding: false,
        closing: false,
        saving: false,
        busy: false,
        label: '',
        seq: 0,
        sel: [],
        multi: false,
        amounts: {},
        savedIds: [],
        monthNames: @js($monthNames),
        nowYm: {{ (int) $nowYm }},
        get visible() { return this.pending || ($wire.isOpen && !this.hiding); },
        get members() { return $wire.members || []; },
        byId(id) { return this.members.find((m) => m.id === id) || { months: {}, pay_yms: [] }; },
        cell(id, m) { return (this.byId(id).months || {})[m] || {}; },

        show(d) {
            this.hiding = false;
            this.closing = false;
            this.pending = true;
            this.label = d.name || '';
            this.sel = [];
            this.savedIds = [];
            const t = ++this.seq;
            $wire.open(d.studentId, d.year ?? null, d.month ?? null).finally(() => {
                if (t !== this.seq) return; // superseded by a later open/close
                this.pending = false;
                this.sync();
            });
        },
        close() {
            if (!this.visible || this.closing) return;
            this.closing = true;
            setTimeout(() => {
                this.seq++;
                this.pending = false;
                this.hiding = true;
                this.closing = false;
                $wire.close();
            }, 140);
        },
        {{-- Take the picked months from the server (after open / year / save) and suggest amounts. --}}
        sync() {
            this.sel = ($wire.selectedMonths || []).map(Number);
            this.multi = this.sel.length > 1;
            this.resetAmounts();
            this.focusFirst();
            {{-- On a narrow screen the picked month can sit outside the scrolled table — bring it into view. --}}
            this.$nextTick(() => {
                const th = this.$root.querySelector('.family-table thead th.is-selected-month');
                const wrap = th && th.closest('.family-table-wrap');
                if (wrap && wrap.scrollWidth > wrap.clientWidth) {
                    const r = th.getBoundingClientRect(), w = wrap.getBoundingClientRect();
                    wrap.scrollLeft += (r.left + r.width / 2) - (w.left + w.width / 2);
                }
            });
        },

        // ---- months ----
        isSel(m) { return this.sel.includes(Number(m)); },
        owedMonths() {
            const y = Number($wire.year);
            const out = [];
            for (let m = 1; m <= 12; m++) {
                if (y * 12 + m > this.nowYm) break;
                if (this.members.some((mb) => ['unpaid', 'late', 'partial'].includes((mb.months[m] || {}).status))) out.push(m);
            }
            return out;
        },
        {{-- Click = that month. In multi mode (or Ctrl+click) a click adds/removes a month; Shift+click picks a range. --}}
        pickMonth(m, ev = null) {
            m = Number(m);
            let sel = [...this.sel];
            if (ev && ev.shiftKey && sel.length) {
                const from = sel[0];
                sel = [];
                for (let i = Math.min(from, m); i <= Math.max(from, m); i++) sel.push(i);
            } else if (this.multi || (ev && (ev.ctrlKey || ev.metaKey))) {
                sel = sel.includes(m) ? sel.filter((x) => x !== m) : [...sel, m];
                if (!sel.length) return;
            } else {
                if (sel.length === 1 && sel[0] === m) return;
                sel = [m];
            }
            this.setMonths(sel);
        },
        setMonths(sel) {
            this.sel = [...new Set(sel.map(Number))].sort((a, b) => a - b);
            if (this.sel.length > 1) this.multi = true;
            this.resetAmounts();
            this.focusFirst();
        },
        toggleMulti() {
            this.multi = !this.multi;
            if (!this.multi && this.sel.length > 1) this.setMonths([this.sel[0]]);
        },
        pickOwed() {
            const owed = this.owedMonths();
            if (owed.length) { this.multi = owed.length > 1; this.setMonths(owed); }
        },
        selLabel() {
            return this.sel.map((m) => this.monthNames[m]).join(@js(app()->getLocale() === 'ar' ? '، ' : ', ')) + ' ' + $wire.year;
        },
        setYear(y) {
            this.busy = true;
            $wire.set('year', Number(y)).then(() => this.sync()).finally(() => this.busy = false);
        },

        // ---- amounts ----
        plain(v) {
            v = Math.round((Number(v) || 0) * 100) / 100;
            return Math.abs(v - Math.round(v)) < 0.005 ? String(Math.round(v)) : v.toFixed(2);
        },
        num(v) { return v === '' || v === null || v === undefined ? 0 : (parseFloat(v) || 0); },
        enrolledSel(id) { return this.sel.filter((m) => !this.cell(id, m).outside); },
        isOutside(id) { return this.enrolledSel(id).length === 0; },
        {{-- The fee to fill in: one month → what is owed (or recorded); several → the fee of the first month still owing. --}}
        dueFor(id) {
            const months = this.enrolledSel(id);
            const owing = months.filter((m) => this.cell(id, m).due - this.cell(id, m).paid > 0.005);
            const m = owing[0] ?? months[0];
            return m ? Number(this.cell(id, m).due) || 0 : 0;
        },
        suggest(id) {
            if (this.sel.length === 1) return this.cell(id, this.sel[0]).suggest ?? '';
            const months = this.enrolledSel(id);
            const owing = months.filter((m) => this.cell(id, m).due - this.cell(id, m).paid > 0.005);
            return owing.length && this.cell(id, owing[0]).due > 0.005 ? this.plain(this.cell(id, owing[0]).due) : '';
        },
        resetAmounts() {
            const a = {};
            this.members.forEach((mb) => { a[mb.id] = this.suggest(mb.id); });
            this.amounts = a;
        },
        fill(id) { this.amounts[id] = this.plain(this.dueFor(id)); },
        diffs() {
            const multi = this.sel.length > 1;
            return this.members.filter((mb) => !this.isOutside(mb.id)).map((mb) => {
                const to = this.num(this.amounts[mb.id]);
                if (multi) {
                    {{-- Several months: only months holding less are topped up. --}}
                    const d = this.enrolledSel(mb.id).reduce((sum, m) => {
                        const add = Math.round((to - (Number(this.cell(mb.id, m).paid) || 0)) * 100) / 100;
                        return add > 0.005 ? sum + add : sum;
                    }, 0);
                    return { id: mb.id, name: mb.name, from: 0, to, d: Math.round(d * 100) / 100 };
                }
                const from = Number(this.cell(mb.id, this.sel[0]).paid) || 0;
                return { id: mb.id, name: mb.name, from, to, d: Math.round((to - from) * 100) / 100 };
            }).filter((x) => Math.abs(x.d) >= 0.005);
        },
        diffOf(id) { return this.diffs().find((x) => x.id === id); },
        diffLabel(id) {
            const x = this.diffOf(id);
            return x ? (x.d > 0 ? '+' : '−') + Math.abs(x.d).toFixed(2) + ' €' : '';
        },
        rowState(id) {
            if (this.savedIds.includes(id)) return 'is-saved';
            const x = this.diffOf(id);
            return x ? (x.d > 0 ? 'is-adding' : 'is-reducing') : '';
        },
        saveLabel() {
            const d = this.diffs();
            const add = d.filter((x) => x.d > 0).reduce((s, x) => s + x.d, 0);
            const cut = d.filter((x) => x.d < 0).reduce((s, x) => s - x.d, 0);
            if (!add && !cut) return @js(__('family.no_changes'));
            return [add ? '+' + add.toFixed(2) + ' €' : '', cut ? '−' + cut.toFixed(2) + ' €' : ''].filter(Boolean).join(' · ');
        },
        save() {
            if (this.saving || this.closing || this.pending || this.busy) return;
            const d = this.diffs();
            if (!d.length) return;
            if (this.sel.length > 1 && !confirm(@js(__('family.confirm_multi')) + ' ' + this.selLabel() + '\n\n' + d.map((x) => '• ' + x.name + ': +' + x.d.toFixed(2) + ' €').join('\n'))) return;
            const cuts = d.filter((x) => x.d < 0);
            if (cuts.length && !confirm(@js(__('family.confirm_reduce')) + '\n\n' + cuts.map((x) => '• ' + x.name + ': ' + x.from + ' € → ' + x.to + ' €').join('\n'))) return;
            const ids = d.map((x) => x.id);
            this.saving = true;
            $wire.saveAll(this.sel, this.amounts).then(() => {
                this.sync();
                this.savedIds = ids;
                setTimeout(() => this.savedIds = [], 1400);
            }).finally(() => this.saving = false);
        },

        // ---- enrolment (one month picked) ----
        paymentsBefore(id) {
            const ym = Number($wire.year) * 12 + this.sel[0];
            return (this.byId(id).pay_yms || []).filter((p) => p < ym).length;
        },
        isEnrollMonth(id) { return this.byId(id).enrolled_ym === Number($wire.year) * 12 + this.sel[0]; },
        enroll(id) {
            const month = this.monthNames[this.sel[0]] + ' ' + $wire.year;
            const n = this.paymentsBefore(id);
            let msg = @js(__('enroll.confirm', ['month' => '__M__'])).replace('__M__', month);
            if (n > 0) msg += ' ' + @js(__('enroll.confirm_payments', ['count' => '__N__'])).replace('__N__', n);
            if (!confirm(msg)) return;
            this.busy = true;
            $wire.setEnrollmentMonth(id, this.sel[0]).then(() => this.sync()).finally(() => this.busy = false);
        },
        clearEnroll(id) {
            if (!confirm(@js(__('enroll.clear_confirm')))) return;
            this.busy = true;
            $wire.clearEnrollment(id).then(() => this.sync()).finally(() => this.busy = false);
        },
        pill(status) {
            return { paid: 'pill-success', paid_advance: 'pill-success', legacy_zero: 'pill-success', partial: 'pill-warning', unpaid: 'pill-danger', late: 'pill-danger' }[status] || 'pill-muted';
        },

        focusFirst() {
            this.$nextTick(() => {
                const inputs = [...this.$root.querySelectorAll('[data-fm-amount]')];
                const el = inputs.find((i) => this.diffOf(Number(i.dataset.fmAmount))) || inputs.find((i) => parseFloat(i.value) > 0) || inputs[0];
                if (el) { el.focus({ preventScroll: true }); el.select(); }
            });
        },
    }"
    x-effect="if (!$wire.isOpen) hiding = false"
    x-init="if ($wire.isOpen) sync()"
    @family-open.window="show($event.detail)"
    @keydown.window.escape="close()"
    @keydown.window.ctrl.enter="if (visible) { $event.preventDefault(); save() }"
>
    <div
        class="modal-backdrop"
        x-show="visible"
        x-cloak
        :class="{ 'modal-closing': closing }"
        @click.self="close()"
    >
        <div class="modal-box family-modal-box" @click.stop>
            {{-- Instant shell while the server fetches the family. --}}
            <div x-show="pending" class="family-shell">
                <div class="modal-header">
                    <h3>👨‍👩‍👧‍👦 {{ __('family.title') }} <span x-show="label">— <span x-text="label"></span></span></h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>
                <div class="modal-body modal-skeleton" aria-busy="true" aria-label="{{ __('payment.loading') }}">
                    <div class="sk" style="height:34px;width:55%;margin-bottom:12px"></div>
                    <div class="sk" style="height:34px;width:40%;margin-bottom:12px"></div>
                    <div class="sk" style="height:130px;margin-bottom:16px"></div>
                    <div class="sk" style="height:62px;margin-bottom:8px"></div>
                    <div class="sk" style="height:62px;margin-bottom:8px"></div>
                    <div class="sk" style="height:62px"></div>
                </div>
                <div class="modal-footer">
                    <span class="text-muted fs-xs"><span class="spinner-sm"></span> {{ __('payment.loading') }}</span>
                </div>
            </div>

            <div x-show="!pending" class="family-content">
                @if ($isOpen)
                    @php
                        $shortMonths = collect($monthNames)->map(fn ($n) => mb_substr($n, 0, 3))->all();
                        $familyOwed = collect($members)->sum('balance');
                        $yearTotal = collect($members)->sum('year_paid');
                        $monthTotals = [];
                        foreach (range(1, 12) as $mm) {
                            $monthTotals[$mm] = collect($members)->sum(fn ($x) => $x['months'][$mm]['paid'] ?? 0);
                        }
                    @endphp
                    <div class="modal-header">
                        <h3>👨‍👩‍👧‍👦 {{ __('family.title') }} — {{ $familyTitle }}
                            @if ($guardianPhone)
                                <small class="text-muted fw-600" style="font-family:ui-monospace,monospace" dir="ltr">/ {{ $guardianPhone }}</small>
                            @endif
                        </h3>
                        <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                    </div>

                    <div class="modal-body family-body" :class="{ 'is-busy': busy || saving }">
                        @if (count($members) === 0)
                            <p class="text-soft" style="text-align:center;padding:30px">—</p>
                        @else
                            <div class="family-summary">
                                <strong>{{ __('family.members_count', ['count' => count($members)]) }}</strong>
                                <span class="family-balance {{ $familyOwed > 0 ? 'fam-owed' : 'fam-clear' }}">
                                    💶 {{ __('family.total_balance') }}: <strong>{{ number_format($familyOwed, 2) }} €</strong>
                                </span>
                                @if ($canWrite)
                                    <button type="button" class="btn btn-sm btn-soft-primary" @click="close(); Livewire.dispatch('open-student-form', { studentId: null, phone: @js($guardianPhone ?: null) })">{{ __('student.add_sibling') }}</button>
                                @endif
                                @if ($isAdmin)
                                    <button type="button" class="btn btn-sm btn-soft-danger fp-delete-family"
                                        @click="close(); {{ $familyId ? "Livewire.dispatch('open-delete-family', { familyId: " . (int) $familyId . ' })' : "Livewire.dispatch('open-delete-student', { studentId: " . (int) $studentId . ' })' }}">
                                        {{ $familyId ? __('delete.family_button') : __('delete.student_button') }}
                                    </button>
                                @endif
                            </div>

                            <div class="family-controls">
                                <label class="fp-field">
                                    <span class="fp-label">{{ __('family.year') }}</span>
                                    <select class="form-select fp-year" @change="setYear($event.target.value)">
                                        @foreach ($yearOptions as $y)
                                            <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="fp-field">
                                    <span class="fp-label">{{ __('family.month') }}</span>
                                    <select class="form-select fp-month" @change="multi = false; setMonths([Number($event.target.value)])">
                                        @foreach ($monthNames as $num => $name)
                                            <option value="{{ $num }}" :selected="sel[0] === {{ $num }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @if ($canWrite)
                                    <div class="fp-month-tools">
                                        <button type="button" class="btn btn-sm fp-multi" :class="multi ? 'is-on' : ''" @click="toggleMulti()" :aria-pressed="multi" title="{{ __('family.multi_title') }}">
                                            <span class="fp-switch" aria-hidden="true"></span> {{ __('family.multi') }}
                                        </button>
                                        <button type="button" class="btn btn-sm fp-owed" x-show="owedMonths().length > 1" x-cloak
                                            :class="owedMonths().join() === sel.join() ? 'is-on' : ''"
                                            @click="pickOwed()" title="{{ __('family.pick_owed_title') }}">
                                            ⚠️ <span x-text="@js(__('family.pick_owed', ['count' => '__N__'])).replace('__N__', owedMonths().length)"></span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                            <p class="fp-multi-hint" x-show="multi" x-cloak x-transition.opacity>💡 {{ __('family.multi_hint') }}</p>

                            {{-- Every child's payments for the year --}}
                            <div class="family-table-wrap">
                                <table class="family-table" :class="{ 'is-multi': multi }">
                                    <thead>
                                        <tr>
                                            <th class="fam-name">{{ __('family.col_student') }}</th>
                                            @foreach ($shortMonths as $num => $short)
                                                <th class="fam-month {{ ($year * 12 + $num) === $nowYm ? 'is-now' : '' }}" :class="{ 'is-selected-month': isSel({{ $num }}) }" title="{{ $monthNames[$num] }} {{ $year }}" @click="pickMonth({{ $num }}, $event)">{{ $short }}</th>
                                            @endforeach
                                            <th>{{ __('family.col_year_paid', ['year' => $year]) }}</th>
                                            <th>{{ __('family.col_balance') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($members as $m)
                                            <tr wire:key="ft-{{ $m['id'] }}" class="{{ $m['is_self'] ? 'is-self' : '' }}">
                                                <td class="fam-name">
                                                    <button type="button" class="fam-name-link" @click="close(); Livewire.dispatch('open-student-panel', { studentId: {{ $m['id'] }} })" title="{{ __('actions.view_details') }}">{{ $m['name'] }}</button>
                                                    @if ($m['enrolled_label'])
                                                        <div class="fam-enrolled">📅 {{ __('enroll.since', ['month' => $m['enrolled_label']]) }}</div>
                                                    @endif
                                                </td>
                                                @foreach ($m['months'] as $num => $c)
                                                    <td
                                                        class="fam-cell {{ $c['class'] }}"
                                                        :class="{ 'is-selected-month': isSel({{ (int) $num }}) }"
                                                        title="{{ $monthNames[(int) $num] }} — {{ $c['label'] }}{{ $c['paid'] > 0 ? ' · ' . number_format($c['paid'], 2) . ' €' : '' }}"
                                                        @click="pickMonth({{ (int) $num }}, $event)"
                                                    >{{ $c['display'] }}</td>
                                                @endforeach
                                                <td class="fam-total">{{ number_format($m['year_paid'], 0) }}€</td>
                                                <td class="{{ $m['balance'] > 0 ? 'fam-owed' : 'fam-clear' }}">{{ number_format($m['balance'], 0) }}€</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    @if (count($members) > 1)
                                        <tfoot>
                                            <tr>
                                                <td class="fam-name">{{ __('family.total') }}</td>
                                                @foreach ($monthTotals as $num => $t)
                                                    <td :class="{ 'is-selected-month': isSel({{ $num }}) }">{{ $t > 0 ? number_format($t, 0) : '' }}</td>
                                                @endforeach
                                                <td class="fam-total">{{ number_format($yearTotal, 0) }}€</td>
                                                <td class="{{ $familyOwed > 0 ? 'fam-owed' : 'fam-clear' }}">{{ number_format($familyOwed, 0) }}€</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>

                            @if ($canWrite)
                                {{-- Payment form for the picked month(s) --}}
                                <div class="family-pay">
                                    <div class="family-pay-head">
                                        <h4>
                                            💶 <span x-text="sel.length > 1 ? @js(__('family.pay_for_months', ['count' => '__N__'])).replace('__N__', sel.length) : @js(__('family.pay_for'))"></span>:
                                            <span class="fp-sel-label" x-text="selLabel()"></span>
                                        </h4>
                                        <span class="text-muted fs-xs" x-text="sel.length > 1 ? @js(__('family.form_hint_multi')) : @js(__('family.form_hint'))"></span>
                                    </div>

                                    <div class="family-paybar">
                                        <div class="method-toggle fp-method">
                                            <button type="button" class="cash {{ $method === 'cash' ? 'active' : '' }}" :class="{ active: $wire.method === 'cash' }" @click="$wire.method = 'cash'">💵 {{ __('payment.method_cash') }}</button>
                                            <button type="button" class="bank {{ $method === 'bank' ? 'active' : '' }}" :class="{ active: $wire.method === 'bank' }" @click="$wire.method = 'bank'">🏦 {{ __('payment.method_bank') }}</button>
                                        </div>
                                        <label class="fp-field">
                                            <span class="fp-label">{{ __('payment.date') }}</span>
                                            <input type="date" class="form-input fp-date" wire:model="paid_at">
                                        </label>
                                    </div>
                                    @error('paid_at') <small class="text-danger">{{ $message }}</small> @enderror

                                    <div class="family-members">
                                        @foreach ($members as $m)
                                            @php $id = (int) $m['id']; @endphp
                                            <div
                                                class="family-member {{ $m['is_self'] ? 'is-self' : '' }}"
                                                :class="[rowState({{ $id }}), isOutside({{ $id }}) ? 'is-outside' : '']"
                                                wire:key="fm-{{ $id }}"
                                            >
                                                <div class="member-main">
                                                    <div class="member-name">
                                                        @if ($m['is_self']) <span class="pill pill-info" title="{{ __('family.current') }}">{{ __('family.current') }}</span> @endif
                                                        <strong class="member-name-text">{{ $m['name'] }}</strong>
                                                        @if ($m['badge']) <span class="status-badge" title="{{ $m['skip_reason'] ?? '' }}">{{ $m['badge'] }}</span> @endif
                                                    </div>
                                                    <div class="member-meta">
                                                        <span class="meta-id">🆔 {{ $m['external_id'] ?? $m['id'] }}</span>
                                                        @if ($m['phone'])
                                                            <span class="meta-phone" dir="ltr">📞 {{ $m['phone'] }}</span>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="member-month">
                                                    {{-- One month: its status, fee and what is recorded, plus the enrolment button. --}}
                                                    <template x-if="sel.length === 1">
                                                        <div>
                                                            <span class="pill" :class="pill(cell({{ $id }}, sel[0]).status)" x-text="cell({{ $id }}, sel[0]).label"></span>
                                                            <div class="member-month-sub" x-show="!isOutside({{ $id }})"
                                                                x-text="@js(__('family.month_due', ['amount' => '__D__'])).replace('__D__', Math.round(cell({{ $id }}, sel[0]).due)) + ' · ' + @js(__('family.paid_now', ['amount' => '__P__'])).replace('__P__', Number(cell({{ $id }}, sel[0]).paid).toFixed(2))"></div>
                                                            <div class="fp-enroll-row">
                                                                <template x-if="isEnrollMonth({{ $id }})">
                                                                    <span class="fp-enroll-on">
                                                                        <span class="pill pill-success">📅 {{ __('enroll.is_this') }}</span>
                                                                        <button type="button" class="btn btn-sm btn-ghost fp-enroll" @click="clearEnroll({{ $id }})" title="{{ __('enroll.clear_btn') }}">✕</button>
                                                                    </span>
                                                                </template>
                                                                <template x-if="!isEnrollMonth({{ $id }})">
                                                                    <button type="button" class="btn btn-sm btn-ghost fp-enroll" @click="enroll({{ $id }})"
                                                                        :title="@js(__('enroll.set_btn', ['month' => '__M__'])).replace('__M__', monthNames[sel[0]] + ' ' + $wire.year)">📅 {{ __('enroll.start_here') }}</button>
                                                                </template>
                                                            </div>
                                                        </div>
                                                    </template>
                                                    {{-- Several months: one chip per picked month with what it holds now. --}}
                                                    <template x-if="sel.length > 1">
                                                        <div class="member-months">
                                                            <template x-for="mm in sel" :key="mm">
                                                                <span class="fam-chip" :class="cell({{ $id }}, mm).class" :title="monthNames[mm] + ' — ' + cell({{ $id }}, mm).label">
                                                                    <span class="fam-chip__m" x-text="monthNames[mm].slice(0, 3)"></span>
                                                                    <span class="fam-chip__v" x-text="cell({{ $id }}, mm).display"></span>
                                                                </span>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>

                                                <div class="member-pay">
                                                    <span class="text-muted fs-xs" x-show="isOutside({{ $id }})">{{ __('status.not_enrolled') }}</span>
                                                    <template x-if="!isOutside({{ $id }})">
                                                        <span class="member-pay-inner">
                                                            <button type="button" class="btn btn-sm btn-ghost fp-fill" x-show="dueFor({{ $id }}) > 0" title="{{ __('family.fill_due_title') }}" @click="fill({{ $id }})">{{ __('family.fill_due') }}</button>
                                                            <input
                                                                type="number"
                                                                inputmode="decimal"
                                                                step="0.01"
                                                                min="0"
                                                                class="form-input member-amount"
                                                                data-fm-amount="{{ $id }}"
                                                                x-model="amounts[{{ $id }}]"
                                                                placeholder="0"
                                                                aria-label="{{ __('payment.amount') }} — {{ $m['name'] }}"
                                                                @keydown.enter.prevent="save()"
                                                                @focus="$event.target.select()"
                                                            >
                                                            <span class="member-amount-cur">€<small class="fp-per-month" x-show="sel.length > 1"> / {{ __('family.per_month') }}</small></span>
                                                        </span>
                                                    </template>
                                                </div>

                                                <div class="member-diff" :class="rowState({{ $id }})">
                                                    <span x-show="savedIds.includes({{ $id }})" x-cloak>✓</span>
                                                    <span x-show="!savedIds.includes({{ $id }})" dir="ltr" x-text="diffLabel({{ $id }})"></span>
                                                </div>

                                                <div class="member-actions">
                                                    <button type="button" class="btn btn-sm" @click="close(); abOpenPayment({{ $id }}, Number($wire.year), sel[0], @js($m['name']))" title="{{ __('family.details') }}">💶</button>
                                                    <button type="button" class="btn btn-sm" @click="close(); Livewire.dispatch('open-student-form', { studentId: {{ $id }} })" title="{{ __('student.edit') }}">✏️</button>
                                                    @if ($isAdmin)
                                                        <button type="button" class="btn btn-sm btn-soft-danger" @click="close(); Livewire.dispatch('open-delete-student', { studentId: {{ $id }} })" title="{{ __('delete.student_button') }}" aria-label="{{ __('delete.student_button') }} — {{ $m['name'] }}">🗑️</button>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @error('amounts.*') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                            @endif
                        @endif
                    </div>

                    <div class="modal-footer">
                        @if ($canWrite && count($members) > 0)
                            <span class="text-muted fs-xs fp-hint">{{ __('family.keys_hint') }}</span>
                        @endif
                        <button type="button" class="btn" @click="close()">{{ __('common.close_esc') }}</button>
                        @if ($canWrite && count($members) > 0)
                            <button type="button" class="btn btn-primary fp-save" @click="save()" :disabled="saving || busy || diffs().length === 0">
                                <span x-show="!saving">💾 {{ __('family.save_all') }} — <span class="fp-total" dir="ltr" x-text="saveLabel()"></span></span>
                                <span x-show="saving" x-cloak><span class="spinner-sm"></span> {{ __('family.save_all') }}…</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
