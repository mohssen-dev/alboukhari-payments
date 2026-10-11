{{--
    Settings → Templates (App\Livewire\SettingsTemplates): every template as a
    card — the Dutch text that is sent, the Arabic translation (staff only),
    the SMS count — edited in place, one card at a time.
--}}
<div class="settpl">
    <div class="page-card settpl-head">
        <div>
            <h3 style="margin:0">📝 {{ __('nav.templates') }}</h3>
            <p class="field-help" style="margin:6px 0 0">{{ __('settpl.intro') }}</p>
        </div>
        @if ($canWrite)
            <button type="button" class="btn btn-primary" wire:click="create" @disabled($editingId === 0)>+ {{ __('templates.new') }}</button>
        @endif
    </div>

    @if ($editingId === 0)
        @include('livewire.partials.settings-template-editor', ['title' => __('templates.new')])
    @endif

    @forelse ($library as $t)
        @if ($editingId === $t->id)
            @include('livewire.partials.settings-template-editor', ['title' => $t->name, 'template' => $t])
        @else
            @include('livewire.partials.settings-template-card', ['t' => $t, 'counter' => $counters[$t->id] ?? null])
        @endif
    @empty
        <div class="page-card settpl-empty">{{ __('templates.empty_title') }}</div>
    @endforelse

    @if ($manual->isNotEmpty())
        <h4 class="settpl-section">✍️ {{ __('settpl.manual_section', ['count' => $manual->count()]) }}</h4>
        <p class="field-help settpl-section-hint">{{ __('settpl.manual_hint') }}</p>
        @foreach ($manual as $t)
            @if ($editingId === $t->id)
                @include('livewire.partials.settings-template-editor', ['title' => $t->name, 'template' => $t])
            @else
                @include('livewire.partials.settings-template-card', ['t' => $t, 'counter' => $counters[$t->id] ?? null])
            @endif
        @endforeach
    @endif
</div>
