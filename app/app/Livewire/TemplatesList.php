<?php

namespace App\Livewire;

use App\Models\Family;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Template;
use App\Services\MonthNames;
use App\Services\TemplateRenderer;
use App\Support\AuthorizesLivewireWrite;
use App\Support\SmsCounter;
use App\Support\TemplateVariables;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Templates are the message bodies the cron sends to every parent, so write
 * access here is effectively write access to what the school broadcasts.
 * The component had no authorization at all — it is mounted on /templates,
 * but Livewire endpoints are reachable regardless of the page the caller is
 * on, so every mutating method now checks.
 *
 * A template is the Dutch text that is sent, plus an Arabic translation shown
 * only to staff. The editor shows the message exactly as a real parent
 * receives it (with its SMS count and cost) and the translation under it.
 */
class TemplatesList extends Component
{
    use AuthorizesLivewireWrite;

    public bool $editing = false;
    public ?int $editId = null;
    public string $code = '';
    public string $name = '';
    public string $language = 'nl';
    public string $body = '';
    public string $body_ar = '';
    public string $default_for = 'none';

    public function newTemplate()
    {
        $this->assertCanWrite();

        $this->reset(['editId', 'code', 'name', 'body', 'body_ar']);
        $this->resetValidation();
        $this->language = 'nl';
        $this->default_for = 'none';
        $this->editing = true;
    }

    public function edit(int $id)
    {
        $this->assertCanWrite();

        $t = Template::findOrFail($id);
        $this->resetValidation();
        $this->editId = $id;
        $this->code = $t->code;
        $this->name = $t->name;
        $this->language = $t->language;
        $this->body = $t->body;
        $this->body_ar = (string) $t->body_ar;
        $this->default_for = $t->default_for;
        $this->editing = true;
    }

    public function save()
    {
        $this->assertCanWrite();

        $this->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('templates', 'code')->ignore($this->editId)],
            'name' => 'required|string|max:120',
            'language' => 'required|in:nl,ar,en',
            'body' => 'required|string|min:3|max:2000',
            'body_ar' => 'nullable|string|max:2000',
            'default_for' => 'required|in:first_friday,mid_month,none',
        ]);

        // A misspelt placeholder would reach parents verbatim — refuse it.
        $unknown = TemplateVariables::unknownIn($this->body . "\n" . $this->body_ar);
        if ($unknown) {
            $this->addError('body', __('tplvar.unknown', ['vars' => TemplateVariables::display($unknown)]));
            return;
        }

        $data = [
            'code' => $this->code,
            'name' => $this->name,
            'language' => $this->language,
            'body' => TemplateVariables::normalize(trim($this->body)),
            'body_ar' => trim($this->body_ar) !== '' ? TemplateVariables::normalize(trim($this->body_ar)) : null,
            'default_for' => $this->default_for,
        ];

        if ($this->editId) {
            Template::findOrFail($this->editId)->update($data);
        } else {
            Template::create($data + ['origin' => Template::ORIGIN_LIBRARY]);
        }

        $this->editing = false;
        $this->dispatch('flash', message: __('flash.saved'));
    }

    public function delete(int $id)
    {
        $this->assertCanWrite();

        Template::findOrFail($id)->delete();
        $this->dispatch('flash', message: __('flash.deleted'));
    }

    public function duplicate(int $id)
    {
        $this->assertCanWrite();

        $orig = Template::findOrFail($id);
        Template::create([
            'code' => $orig->code . '_copy_' . time(),
            'name' => $orig->name . ' (نسخة)',
            'language' => $orig->language,
            'body' => $orig->body,
            'body_ar' => $orig->body_ar,
            'origin' => Template::ORIGIN_LIBRARY,
            'default_for' => 'none',
        ]);
        $this->dispatch('flash', message: __('flash.duplicated'));
    }

    public function render()
    {
        return view('livewire.templates-list', [
            'libraryTemplates' => Template::library()->orderBy('language')->orderBy('code')->get(),
            'manualTemplates' => Template::manual()->latest('id')->get(),
            'preview' => $this->editing ? $this->samplePreview() : null,
        ])->layout('layouts.app');
    }

    /**
     * The message exactly as a real parent receives it this month (SMS count
     * and cost), and the translation filled in for the same child — shown to
     * staff, never sent.
     */
    private function samplePreview(): ?array
    {
        $body = trim($this->body);
        if ($body === '') {
            return null;
        }

        $translation = trim($this->body_ar);
        $both = $body . "\n" . $translation;

        $year = (int) date('Y');
        $month = (int) date('n');
        $usesFamily = (bool) array_intersect(TemplateVariables::usedIn($both), array_keys(TemplateVariables::FAMILY));

        $student = $usesFamily
            ? Student::whereIn('family_id', Family::has('students', '>=', 2)->select('id'))->orderBy('id')->first()
            : null;
        $student ??= Student::orderBy('id')->first();

        $who = null;
        $render = fn (string $text) => $text;
        if ($student) {
            if ($usesFamily && $student->family) {
                $render = fn (string $text) => TemplateRenderer::renderForFamily($text, $student->family, $year, $month);
                $who = $student->family->displayName();
            } else {
                $render = fn (string $text) => TemplateRenderer::renderForStudent($text, $student, $year, $month);
                $who = $student->name;
            }
        }

        $counter = SmsCounter::count($render($body), Setting::get('force_ascii', '1') === '1');

        return [
            'counter' => $counter,
            'translation' => $translation !== '' ? $render($translation) : null,
            'who' => $who,
            'month' => (MonthNames::full()[$month] ?? '') . ' ' . $year,
            'cost' => $counter['segments'] * (float) Setting::get('bulkgate_price_per_sms', '0.08'),
            'unknown' => TemplateVariables::unknownIn($both),
        ];
    }
}
