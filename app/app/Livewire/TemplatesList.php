<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Models\Template;
use App\Support\SmsCounter;
use Livewire\Component;

class TemplatesList extends Component
{
    public bool $editing = false;
    public ?int $editId = null;
    public string $code = '';
    public string $name = '';
    public string $language = 'nl';
    public string $body = '';
    public string $default_for = 'none';

    public function newTemplate()
    {
        $this->reset(['editId', 'code', 'name', 'body']);
        $this->language = 'nl';
        $this->default_for = 'none';
        $this->editing = true;
    }

    public function edit(int $id)
    {
        $t = Template::findOrFail($id);
        $this->editId = $id;
        $this->code = $t->code;
        $this->name = $t->name;
        $this->language = $t->language;
        $this->body = $t->body;
        $this->default_for = $t->default_for;
        $this->editing = true;
    }

    public function save()
    {
        $this->validate([
            'code' => 'required|string|max:50|regex:/^[a-z0-9_]+$/',
            'name' => 'required|string|max:120',
            'language' => 'required|in:nl,ar,en',
            'body' => 'required|string|min:3',
            'default_for' => 'required|in:first_friday,mid_month,none',
        ]);

        if ($this->editId) {
            $t = Template::findOrFail($this->editId);
            $t->update([
                'code' => $this->code,
                'name' => $this->name,
                'language' => $this->language,
                'body' => $this->body,
                'default_for' => $this->default_for,
            ]);
        } else {
            Template::create([
                'code' => $this->code,
                'name' => $this->name,
                'language' => $this->language,
                'body' => $this->body,
                'default_for' => $this->default_for,
            ]);
        }

        $this->editing = false;
        $this->dispatch('flash', message: __('flash.saved'));
    }

    public function delete(int $id)
    {
        Template::findOrFail($id)->delete();
        $this->dispatch('flash', message: __('flash.deleted'));
    }

    public function duplicate(int $id)
    {
        $orig = Template::findOrFail($id);
        Template::create([
            'code' => $orig->code . '_copy_' . time(),
            'name' => $orig->name . ' (نسخة)',
            'language' => $orig->language,
            'body' => $orig->body,
            'default_for' => 'none',
        ]);
        $this->dispatch('flash', message: __('flash.duplicated'));
    }

    public function getCounterProperty(): array
    {
        return SmsCounter::count($this->body, Setting::get('force_ascii', '1') === '1');
    }

    public function render()
    {
        return view('livewire.templates-list', [
            'templates' => Template::orderBy('language')->orderBy('code')->get(),
            'counter' => $this->editing ? $this->counter : null,
        ])->layout('layouts.app');
    }
}
