{{-- Add / edit a student. Opened with the 'open-student-form' event (see App\Livewire\StudentForm). --}}
<div>
    @if ($isOpen)
        <div
            x-data="{
                closing: false,
                saving: false,
                close() {
                    if (this.closing) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                },
                save() {
                    if (this.saving || this.closing) return;
                    this.saving = true;
                    $wire.save().finally(() => this.saving = false);
                },
            }"
            x-init="$nextTick(() => $root.querySelector('[data-sf-first]')?.focus())"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
            @keydown.window.ctrl.enter.prevent="save()"
        >
            <div class="modal-box student-form-box" @click.stop>
                <div class="modal-header">
                    <h3>
                        @if ($studentId)
                            ✏️ {{ __('student.edit_title') }}
                        @else
                            ➕ {{ __('student.add_title') }}
                        @endif
                    </h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>

                <form class="modal-body" @submit.prevent="save()" novalidate>
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>{{ __('columns.name') }} *</label>
                            <input type="text" class="form-input" wire:model="name" maxlength="255" data-sf-first autocomplete="off">
                            @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label>{{ __('student.external_id') }}</label>
                            <input type="number" min="1" class="form-input" wire:model="external_id">
                            <div class="field-help">{{ __('student.external_id_help') }}</div>
                            @error('external_id') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label>📞 {{ __('panel.primary_phone') }}</label>
                            <input type="tel" class="form-input" wire:model.live.debounce.400ms="phone_primary_raw" placeholder="06xxxxxxxx" autocomplete="off" dir="ltr">
                            @error('phone_primary_raw') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label>📞 {{ __('panel.secondary_phone') }}</label>
                            <input type="tel" class="form-input" wire:model="phone_secondary_raw" autocomplete="off" dir="ltr">
                            @error('phone_secondary_raw') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    @if ($familyHint)
                        <div class="student-form-family pill pill-{{ $familyTone }}">👨‍👩‍👧 {{ $familyHint }}</div>
                    @endif

                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label>💶 {{ __('Default monthly fee') }}</label>
                            <input type="number" step="0.01" min="0" class="form-input" wire:model="default_fee_amount" placeholder="{{ number_format($defaultFee, 2) }}">
                            <div class="field-help">{{ __('student.fee_help', ['fee' => number_format($defaultFee, 2)]) }}</div>
                            @error('default_fee_amount') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label>📅 {{ __('panel.enrolled_at') }}</label>
                            <input type="date" class="form-input" wire:model="enrolled_at">
                            @error('enrolled_at') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label>🚪 {{ __('panel.withdrawn_at') }}</label>
                            <input type="date" class="form-input" wire:model="withdrawn_at">
                            @error('withdrawn_at') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    <label class="student-form-check">
                        <input type="checkbox" wire:model="allow_sms">
                        <span>📲 {{ __('student.allow_sms') }}</span>
                    </label>

                    <div class="form-group">
                        <label>{{ __('panel.notes') }}</label>
                        <textarea class="form-textarea" wire:model="notes" rows="2"></textarea>
                        @error('notes') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>

                    {{-- Enter in any field submits. --}}
                    <button type="submit" hidden></button>
                </form>

                <div class="modal-footer">
                    @if ($studentId && auth()->user()?->isAdmin())
                        <button type="button" class="btn btn-soft-danger student-form-delete" @click="close(); Livewire.dispatch('open-delete-student', { studentId: {{ (int) $studentId }} })">{{ __('delete.student_button') }}</button>
                    @endif
                    <span class="text-muted fs-xs fp-hint">Enter · Ctrl+Enter = {{ __('common.save') }} · Esc</span>
                    <button type="button" class="btn" @click="close()">{{ __('payment.cancel') }}</button>
                    <button type="button" class="btn btn-primary" @click="save()" :disabled="saving">
                        <span x-show="!saving">💾 {{ __('common.save') }}</span>
                        <span x-show="saving" x-cloak><span class="spinner-sm"></span> {{ __('common.save') }}…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
