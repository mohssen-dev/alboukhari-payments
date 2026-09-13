{{--
    Family window. On top: every child's payments for the chosen year (click
    a month to pick it). Below: the payment form for that month — one amount
    per child, which is the month's TOTAL: raise it to record more, lower it
    (or clear it) to reduce what was recorded. Year / month pickers switch
    both the table and the form; next year is offered for paying ahead.
--}}
<div>
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
        <div
            x-data="{
                closing: false,
                saving: false,
                close() {
                    if (this.closing) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                },
                num(v) {
                    return v === '' || v === null || v === undefined ? 0 : (parseFloat(v) || 0);
                },
                diffs() {
                    const a = $wire.amounts || {};
                    return ($wire.members || [])
                        .filter((m) => !m.outside && (m.id in a))
                        .map((m) => ({ id: m.id, name: m.name, from: Number(m.month_paid) || 0, to: this.num(a[m.id]) }))
                        .map((x) => ({ ...x, d: Math.round((x.to - x.from) * 100) / 100 }))
                        .filter((x) => Math.abs(x.d) >= 0.005);
                },
                diffOf(id) { return this.diffs().find((x) => x.id === id); },
                diffLabel(id) {
                    const x = this.diffOf(id);
                    return x ? (x.d > 0 ? '+' : '−') + Math.abs(x.d).toFixed(2) + ' €' : '';
                },
                rowState(id) {
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
                    if (this.saving || this.closing) return;
                    const cuts = this.diffs().filter((x) => x.d < 0);
                    if (cuts.length && !confirm(@js(__('family.confirm_reduce')) + '\n\n' + cuts.map((x) => '• ' + x.name + ': ' + x.from + ' € → ' + x.to + ' €').join('\n'))) return;
                    this.saving = true;
                    $wire.saveAll().finally(() => this.saving = false);
                },
                focusFirst() {
                    const inputs = [...this.$root.querySelectorAll('[data-fm-amount]')];
                    const el = inputs.find((i) => i.closest('.family-member')?.dataset.paid === '0' && parseFloat(i.value) > 0)
                        || inputs.find((i) => parseFloat(i.value) > 0) || inputs[0];
                    if (el) { el.focus(); el.select(); }
                },
                pickMonth(m) {
                    if (Number(m) === Number($wire.month)) return;
                    $wire.set('month', Number(m)).then(() => this.$nextTick(() => this.focusFirst()));
                },
                pickYear(y) {
                    $wire.set('year', Number(y)).then(() => this.$nextTick(() => this.focusFirst()));
                },
            }"
            x-init="$nextTick(() => focusFirst())"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
            @keydown.window.ctrl.enter.prevent="save()"
        >
            <div class="modal-box family-modal-box" @click.stop>
                <div class="modal-header">
                    <h3>👨‍👩‍👧‍👦 {{ __('family.title') }} — {{ $familyTitle }}
                        @if ($guardianPhone)
                            <small class="text-muted fw-600" style="font-family:ui-monospace,monospace" dir="ltr">/ {{ $guardianPhone }}</small>
                        @endif
                    </h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>

                <div class="modal-body">
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
                                <select class="form-select fp-year" x-on:change="pickYear($event.target.value)">
                                    @foreach ($yearOptions as $y)
                                        <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="fp-field">
                                <span class="fp-label">{{ __('family.month') }}</span>
                                <select class="form-select fp-month" x-on:change="pickMonth($event.target.value)">
                                    @foreach ($monthNames as $num => $name)
                                        <option value="{{ $num }}" @selected($num === $month)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        {{-- Every child's payments for the year --}}
                        <div class="family-table-wrap">
                            <table class="family-table">
                                <thead>
                                    <tr>
                                        <th class="fam-name">{{ __('family.col_student') }}</th>
                                        @foreach ($shortMonths as $num => $short)
                                            <th class="fam-month {{ $num === $month ? 'is-selected-month' : '' }}" title="{{ $monthNames[$num] }} {{ $year }}" @click="pickMonth({{ $num }})">{{ $short }}</th>
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
                                                    class="fam-cell {{ $c['class'] }} {{ (int) $num === $month ? 'is-selected-month' : '' }}"
                                                    title="{{ $monthNames[(int) $num] }} — {{ $c['label'] }}{{ $c['paid'] > 0 ? ' · ' . number_format($c['paid'], 2) . ' €' : '' }}"
                                                    @click="pickMonth({{ (int) $num }})"
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
                                                <td class="{{ $num === $month ? 'is-selected-month' : '' }}">{{ $t > 0 ? number_format($t, 0) : '' }}</td>
                                            @endforeach
                                            <td class="fam-total">{{ number_format($yearTotal, 0) }}€</td>
                                            <td class="{{ $familyOwed > 0 ? 'fam-owed' : 'fam-clear' }}">{{ number_format($familyOwed, 0) }}€</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>

                        @if ($canWrite)
                            {{-- Payment form for the chosen month --}}
                            <div class="family-pay">
                                <div class="family-pay-head">
                                    <h4>💶 {{ __('family.pay_for') }}: {{ $monthNames[$month] }} {{ $year }}</h4>
                                    <span class="text-muted fs-xs">{{ __('family.form_hint') }}</span>
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
                                        @php
                                            $pill = match ($m['month_status']) {
                                                'paid', 'paid_advance', 'legacy_zero' => 'pill-success',
                                                'partial' => 'pill-warning',
                                                'unpaid', 'late' => 'pill-danger',
                                                default => 'pill-muted',
                                            };
                                        @endphp
                                        <div
                                            class="family-member {{ $m['is_self'] ? 'is-self' : '' }}"
                                            :class="rowState({{ $m['id'] }})"
                                            wire:key="fm-{{ $m['id'] }}"
                                            data-paid="{{ $m['month_paid'] > 0 ? '1' : '0' }}"
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
                                                <span class="pill {{ $pill }}">{{ $m['month_label'] }}</span>
                                                @unless ($m['outside'])
                                                    <div class="member-month-sub">
                                                        {{ __('family.month_due', ['amount' => number_format($m['month_due'], 0)]) }} · {{ __('family.paid_now', ['amount' => number_format($m['month_paid'], 2)]) }}
                                                    </div>
                                                @endunless
                                                {{-- Enrolment: make the chosen month this child's first billed month. --}}
                                                <div class="fp-enroll-row">
                                                    @if ($m['is_enroll_month'])
                                                        <span class="pill pill-success">📅 {{ __('enroll.is_this') }}</span>
                                                        <button type="button" class="btn btn-sm btn-ghost fp-enroll" wire:click="clearEnrollment({{ $m['id'] }})" wire:confirm="{{ __('enroll.clear_confirm') }}" title="{{ __('enroll.clear_btn') }}">✕</button>
                                                    @else
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-ghost fp-enroll"
                                                            wire:click="setEnrollmentMonth({{ $m['id'] }})"
                                                            wire:confirm="{{ __('enroll.confirm', ['month' => $monthNames[$month] . ' ' . $year]) }}{{ $m['payments_before'] > 0 ? ' ' . __('enroll.confirm_payments', ['count' => $m['payments_before']]) : '' }}"
                                                            title="{{ __('enroll.set_btn', ['month' => $monthNames[$month] . ' ' . $year]) }}"
                                                        >📅 {{ __('enroll.start_here') }}</button>
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="member-pay">
                                                @if ($m['outside'])
                                                    <span class="text-muted fs-xs">{{ __('status.not_enrolled') }}</span>
                                                @else
                                                    @if ($m['month_due'] > 0)
                                                        <button type="button" class="btn btn-sm btn-ghost fp-fill" title="{{ __('family.fill_due_title') }}" @click="$wire.$set('amounts.{{ $m['id'] }}', '{{ $m['month_due_plain'] }}', false)">{{ __('family.fill_due') }}</button>
                                                    @endif
                                                    <input
                                                        type="number"
                                                        inputmode="decimal"
                                                        step="0.01"
                                                        min="0"
                                                        class="form-input member-amount"
                                                        data-fm-amount
                                                        wire:model="amounts.{{ $m['id'] }}"
                                                        placeholder="0"
                                                        aria-label="{{ __('payment.amount') }} — {{ $m['name'] }}"
                                                        @keydown.enter.prevent="save()"
                                                    >
                                                    <span class="member-amount-cur">€</span>
                                                @endif
                                            </div>

                                            <div class="member-diff" :class="rowState({{ $m['id'] }})" x-text="diffLabel({{ $m['id'] }})"></div>

                                            <div class="member-actions">
                                                <button type="button" class="btn btn-sm" @click="close(); abOpenPayment({{ $m['id'] }}, {{ $year }}, {{ $month }}, @js($m['name']))" title="{{ __('family.details') }}">💶</button>
                                                <button type="button" class="btn btn-sm" @click="close(); Livewire.dispatch('open-student-form', { studentId: {{ $m['id'] }} })" title="{{ __('student.edit') }}">✏️</button>
                                                @if ($isAdmin)
                                                    <button type="button" class="btn btn-sm btn-soft-danger" @click="close(); Livewire.dispatch('open-delete-student', { studentId: {{ $m['id'] }} })" title="{{ __('delete.student_button') }}" aria-label="{{ __('delete.student_button') }} — {{ $m['name'] }}">🗑️</button>
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
                        <button type="button" class="btn btn-primary fp-save" @click="save()" :disabled="saving">
                            <span x-show="!saving">💾 {{ __('family.save_all') }} — <span class="fp-total" x-text="saveLabel()"></span></span>
                            <span x-show="saving" x-cloak><span class="spinner-sm"></span> {{ __('family.save_all') }}…</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
