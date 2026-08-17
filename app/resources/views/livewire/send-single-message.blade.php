<div>
    @if ($isOpen)
        <div
            x-data="{
                closing: false,
                close() {
                    if (this.closing) return;
                    this.closing = true;
                    setTimeout(() => $wire.close(), 160);
                }
            }"
            class="modal-backdrop"
            :class="{ 'modal-closing': closing }"
            @click.self="close()"
            @keydown.window.escape="close()"
        >
            <div class="modal-box" style="width:560px" @click.stop>
                <div class="modal-header">
                    <h3>📲 {{ __('Message to') }} {{ $studentName }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" @click="close()" aria-label="{{ __('common.close') }}">✕</button>
                </div>
                <div class="modal-body">
                    <div class="summary-item" style="margin-bottom:14px">
                        <div class="label">📞 {{ __('columns.phone') }}</div>
                        <div class="value" style="font-family:ui-monospace,monospace">{{ $studentPhone }}</div>
                    </div>

                    <div class="form-group">
                        <label>{{ __('send.template') }}</label>
                        <select class="form-select" wire:model.live="templateId">
                            <option value="">{{ __('send.free_text') }}</option>
                            @foreach ($templates as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            {{ __('send.body') }}
                            @if ($counter)
                                <span style="float:inline-end;font-size:11px;color:{{ $counter['segments'] > 1 ? 'var(--color-danger)' : 'var(--color-success)' }};font-weight:700">
                                    📊 {{ $counter['length'] }}/{{ $counter['max_per_segment'] }} — <strong>{{ $counter['segments'] }} SMS</strong>
                                </span>
                            @endif
                        </label>
                        <textarea class="form-textarea" wire:model.live.debounce.300ms="body" rows="6" placeholder="{{ __('send.type_message_placeholder') }}"></textarea>
                    </div>

                    @if ($resultMessage)
                        <div class="pill {{ $resultOk ? 'pill-success' : 'pill-danger' }}" style="display:block;padding:10px;margin-bottom:8px">
                            {{ $resultMessage }}
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" @click="close()">{{ __('common.cancel') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                        <span wire:loading.remove wire:target="send">📨 {{ __('Send now') }}</span>
                        <span wire:loading wire:target="send"><span class="spinner-sm"></span> …</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
