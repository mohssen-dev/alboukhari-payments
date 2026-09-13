{{-- Delete a student or a whole family; their payments stay on record (see App\Livewire\DeleteRecord). --}}
<div>
    @if ($isOpen)
        <div
            x-data="{
                closing: false,
                busy: false,
                close() {
                    if (this.closing || this.busy) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                },
                run() {
                    if (this.busy) return;
                    this.busy = true;
                    $wire.delete().finally(() => this.busy = false);
                },
            }"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
        >
            <div class="modal-box delete-box" @click.stop role="alertdialog" aria-labelledby="delete-title">
                <div class="modal-header">
                    <h3 id="delete-title">🗑️ {{ $kind === 'family' ? __('delete.family_title') : __('delete.student_title') }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>

                <div class="modal-body">
                    <div class="delete-who">
                        @if ($kind === 'family')
                            <strong>👨‍👩‍👧‍👦 {{ $familyName }}</strong>
                            <span class="text-muted">{{ __('delete.children_count', ['count' => count($people)]) }}</span>
                        @elseif (!empty($people))
                            <strong>{{ $people[0]['name'] }}</strong>
                            @if ($people[0]['number'])
                                <span class="text-muted">#{{ $people[0]['number'] }}</span>
                            @endif
                            @if ($familyName)
                                <span class="text-muted">· 👨‍👩‍👧 {{ $familyName }}</span>
                            @endif
                        @endif
                    </div>

                    <div class="delete-kept">
                        <strong>✓ {{ __('delete.payments_kept_title') }}</strong>
                        <span>{{ __('delete.payments_kept', ['count' => $paymentCount, 'total' => number_format($paymentTotal, 2)]) }}</span>
                    </div>

                    <ul class="delete-people">
                        @foreach ($people as $p)
                            <li wire:key="del-{{ $p['id'] }}">
                                <div class="delete-person">
                                    @if ($kind === 'family')
                                        <strong>{{ $p['number'] ? '#' . $p['number'] . ' · ' : '' }}{{ $p['name'] }}</strong>
                                    @endif
                                    <span class="text-muted">
                                        @if ($p['payments'] > 0)
                                            {{ __('delete.paid_range', ['count' => $p['payments'], 'total' => number_format($p['paid'], 2), 'from' => $p['from'], 'to' => $p['lastPaid'] ?? $p['from']]) }}
                                        @else
                                            {{ __('delete.no_payments') }}
                                        @endif
                                    </span>
                                </div>
                                <div class="delete-last">
                                    @if ($p['lastPaid'])
                                        <span>{{ __('delete.last_owed') }}: <strong>{{ $p['lastPaid'] }}</strong></span>
                                    @else
                                        <span>{{ __('delete.nothing_owed') }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="delete-what">
                        <strong>{{ __('delete.what_happens') }}</strong>
                        <ul>
                            <li>{{ __('delete.after_last_month') }}</li>
                            <li>{{ __('delete.leaves_lists') }}</li>
                            <li>{{ __('delete.see_and_restore') }}</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn" @click="close()">{{ __('payment.cancel') }}</button>
                    <button type="button" class="btn btn-danger" @click="run()" :disabled="busy">
                        <span x-show="!busy">🗑️ {{ $kind === 'family' ? __('delete.family_button_confirm') : __('delete.student_button_confirm') }}</span>
                        <span x-show="busy" x-cloak><span class="spinner-sm"></span> …</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
