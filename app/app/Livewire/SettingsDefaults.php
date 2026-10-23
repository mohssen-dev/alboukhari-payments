<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Models\Template;
use App\Support\AuthorizesLivewireWrite;
use App\Support\Defaults;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Defaults: what the payment windows (student, family, quick
 * entry) and the send page start with. See App\Support\Defaults.
 */
class SettingsDefaults extends Component
{
    use AuthorizesLivewireWrite;

    public array $values = [];

    public function mount(): void
    {
        $this->values = Defaults::all();
    }

    public function save(): void
    {
        $this->assertAdmin();

        $this->validate([
            'values.' . Defaults::PAYMENT_METHOD => 'nullable|in:cash,bank',
            'values.' . Defaults::PAYMENT_DATE => 'required|in:today,empty',
            'values.' . Defaults::PAYMENT_AMOUNT => 'required|in:remaining,empty',
            'values.' . Defaults::FAMILY_OPENS_ON => 'required|in:oldest_unpaid,current',
            'values.' . Defaults::SEND_GROUP_BY_FAMILY => 'required|in:0,1',
            'values.' . Defaults::SEND_TEMPLATE => ['nullable', Rule::exists('templates', 'id')->whereNull('deleted_at')],
        ]);

        foreach (Defaults::KEYS as $key) {
            Setting::put($key, (string) ($this->values[$key] ?? ''));
        }

        $this->dispatch('toast', type: 'success', message: __('defaults.saved'));
    }

    public function resetToFactory(): void
    {
        $this->assertAdmin();

        $this->values = Defaults::FACTORY;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.settings-defaults', [
            'templates' => Template::library()->orderBy('id')->get(['id', 'name']),
        ]);
    }
}
