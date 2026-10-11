<?php

namespace App\Livewire;

use App\Models\Template;
use App\Services\TemplatePreview;
use App\Support\AuthorizesLivewireWrite;
use App\Support\TemplateVariables;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Settings → Templates: every template the school sends, each as a card with
 * the Dutch text that goes out, its Arabic translation (staff only) and its
 * SMS count — and edited right in its card.
 *
 * The same templates as /templates; this is where an admin finds them next
 * to the reminder and BulkGate settings. The technical code is never asked
 * for: a new template gets one from its name.
 */
class SettingsTemplates extends Component
{
    use AuthorizesLivewireWrite;

    /** The template being edited: its id, 0 for a new one, null when none. */
    public ?int $editingId = null;
    public string $name = '';
    public string $body = '';
    public string $body_ar = '';

    public function create(): void
    {
        $this->assertCanWrite();

        $this->resetValidation();
        $this->editingId = 0;
        $this->name = '';
        $this->body = '';
        $this->body_ar = '';
    }

    public function edit(int $id): void
    {
        $this->assertCanWrite();

        $template = Template::find($id);
        if (!$template) {
            $this->dispatch('toast', message: __('flash.deleted'), type: 'error');
            return;
        }

        $this->resetValidation();
        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->body = $template->body;
        $this->body_ar = (string) $template->body_ar;
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'body', 'body_ar']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->assertCanWrite();

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'body' => 'required|string|min:3|max:2000',
            'body_ar' => 'nullable|string|max:2000',
        ], [], [
            'name' => __('templates.display_name'),
            'body' => __('templates.body_nl'),
            'body_ar' => __('templates.body_ar'),
        ]);

        // A misspelt placeholder would reach parents verbatim — refuse it.
        $unknown = TemplateVariables::unknownIn($this->body . "\n" . $this->body_ar);
        if ($unknown) {
            $this->addError('body', __('tplvar.unknown', ['vars' => TemplateVariables::display($unknown)]));
            return;
        }

        $data = [
            'name' => trim($this->name),
            'body' => TemplateVariables::normalize(trim($this->body)),
            'body_ar' => trim($this->body_ar) !== '' ? TemplateVariables::normalize(trim($this->body_ar)) : null,
        ];

        if ($this->editingId) {
            $template = Template::find($this->editingId);
            if (!$template) {
                $this->dispatch('toast', message: __('flash.deleted'), type: 'error');
                $this->cancel();
                return;
            }
            $template->update($data);
        } else {
            Template::create($data + [
                'code' => self::newCode($data['name']),
                'language' => 'nl',
                'origin' => Template::ORIGIN_LIBRARY,
                'default_for' => 'none',
            ]);
        }

        $this->cancel();
        $this->dispatch('toast', message: __('settpl.saved'), type: 'success');
    }

    public function delete(int $id): void
    {
        $this->assertCanWrite();

        $template = Template::find($id);
        if (!$template) {
            return;
        }
        if ($template->reminderKind()) {
            $this->dispatch('toast', message: __('settpl.cannot_delete_reminder'), type: 'error');
            return;
        }

        $template->delete();
        if ($this->editingId === $id) {
            $this->cancel();
        }
        $this->dispatch('toast', message: __('flash.deleted'), type: 'success');
    }

    public function render()
    {
        // The automatic reminders' templates first, then the rest as made.
        $library = Template::library()->orderBy('id')->get()
            ->sortBy(fn (Template $t) => $t->reminderKind() ? 0 : 1, SORT_REGULAR)
            ->values();
        $manual = Template::manual()->latest('id')->get();

        $subjects = TemplatePreview::subjects();
        $counters = $library->concat($manual)
            ->mapWithKeys(fn (Template $t) => [$t->id => TemplatePreview::counter($t->body, $subjects)]);

        return view('livewire.settings-templates', [
            'library' => $library,
            'manual' => $manual,
            'counters' => $counters,
            'preview' => $this->editingId !== null ? TemplatePreview::sample($this->body, $this->body_ar) : null,
            'canWrite' => (bool) auth()->user()?->canWrite(),
        ]);
    }

    /** A unique code from the name — "Rappel juni" → nl_rappel_juni. */
    private static function newCode(string $name): string
    {
        $slug = substr(Str::slug($name, '_'), 0, 40);
        $base = 'nl_' . ($slug !== '' ? $slug : 'template');
        $code = $base;
        for ($i = 2; Template::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = $base . '_' . $i;
        }

        return $code;
    }
}
