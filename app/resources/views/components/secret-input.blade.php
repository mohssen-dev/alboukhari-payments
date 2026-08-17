@props(['name', 'masked' => '', 'placeholder' => ''])
{{--
    Encrypted-secret field with 👁 reveal + 📋 copy.
    The real value is NEVER rendered in the page — it's fetched on demand
    from settings.reveal_secret (admin-only, audit-logged) when 👁 is clicked.
--}}
<div
    x-data="{
        revealed: false,
        copied: false,
        loaded: false,
        async toggle() {
            if (this.revealed) { this.revealed = false; return; }
            if (!this.loaded) {
                try {
                    const r = await fetch(@js(route('settings.reveal_secret')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ key: @js($name) }),
                    });
                    if (!r.ok) throw new Error(r.status);
                    const d = await r.json();
                    this.$refs.field.value = d.value;
                    this.loaded = true;
                } catch (e) {
                    alert(@js(__('settings.reveal_failed')));
                    return;
                }
            }
            this.revealed = true;
        },
        async copy() {
            try {
                await navigator.clipboard.writeText(this.$refs.field.value);
                this.copied = true;
                setTimeout(() => this.copied = false, 1500);
            } catch (e) {}
        },
    }"
    style="display:flex;gap:6px;align-items:center"
>
    <input
        :type="revealed ? 'text' : 'password'"
        type="password"
        name="{{ $name }}"
        x-ref="field"
        class="form-input"
        value="{{ $masked }}"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        style="flex:1;font-family:ui-monospace,monospace"
    >
    <button type="button" class="btn btn-sm" @click="toggle()" :title="revealed ? @js(__('settings.hide_secret')) : @js(__('settings.reveal_secret_btn'))">
        <span x-show="!revealed">👁</span><span x-show="revealed" x-cloak>🙈</span>
    </button>
    <button type="button" class="btn btn-sm" x-show="revealed" x-cloak @click="copy()" title="{{ __('settings.copy_secret') }}">
        <span x-show="!copied">📋</span><span x-show="copied" x-cloak>✅</span>
    </button>
</div>
