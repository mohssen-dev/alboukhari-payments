{{--
    Placeholder chips: click to insert {{name}} at the cursor of the last
    focused [data-tpl-target] textarea (or the first one on the page).
--}}
@props(['family' => true])
<div
    class="tpl-vars"
    x-data="{
        last: null,
        insert(name) {
            const ta = this.last && document.body.contains(this.last) ? this.last : document.querySelector('[data-tpl-target]');
            if (!ta) return;
            const token = '{' + '{' + name + '}' + '}';
            const start = ta.selectionStart ?? ta.value.length;
            const end = ta.selectionEnd ?? start;
            ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
            ta.focus();
            ta.setSelectionRange(start + token.length, start + token.length);
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        },
    }"
    @focusin.window="if ($event.target.matches('[data-tpl-target]')) last = $event.target"
>
    <span class="tpl-vars__title">{{ __('tplvar.title') }}</span>
    <div class="tpl-vars__list">
        @foreach (\App\Support\TemplateVariables::STUDENT as $name => $key)
            <button type="button" class="tpl-var" @mousedown.prevent @click="insert('{{ $name }}')" title="{{ __($key) }}">{{ '{' . '{' . $name . '}' . '}' }}</button>
        @endforeach
    </div>
    @if ($family)
        <div class="tpl-vars__list">
            <span class="tpl-vars__group">{{ __('tplvar.family_group') }}:</span>
            @foreach (\App\Support\TemplateVariables::FAMILY as $name => $key)
                <button type="button" class="tpl-var" @mousedown.prevent @click="insert('{{ $name }}')" title="{{ __($key) }}">{{ '{' . '{' . $name . '}' . '}' }}</button>
            @endforeach
        </div>
    @endif
</div>
