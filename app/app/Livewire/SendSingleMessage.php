<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Models\Student;
use App\Models\Template;
use App\Services\CampaignSender;
use App\Services\TemplateRenderer;
use App\Support\SmsCounter;
use Livewire\Attributes\On;
use Livewire\Component;

class SendSingleMessage extends Component
{
    public bool $isOpen = false;
    public ?int $studentId = null;
    public string $studentName = '';
    public string $studentPhone = '';
    public ?int $templateId = null;
    public string $body = '';
    public string $resultMessage = '';

    public function mount(?int $initialStudentId = null): void
    {
        if ($initialStudentId) {
            $this->open($initialStudentId);
        }
    }

    #[On('open-send-message')]
    public function open(int $studentId): void
    {
        $student = Student::findOrFail($studentId);
        $this->studentId = $studentId;
        $this->studentName = $student->name;
        $this->studentPhone = $student->phone_primary_e164 ?? '—';
        $this->templateId = null;
        $this->body = '';
        $this->resultMessage = '';
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'studentId', 'studentName', 'studentPhone', 'templateId', 'body', 'resultMessage']);
        $this->dispatch('close-modal');
    }

    public function updatedTemplateId()
    {
        if (!$this->templateId) {
            $this->body = '';
            return;
        }
        $tpl = Template::find($this->templateId);
        if ($tpl) {
            $student = Student::find($this->studentId);
            $year = (int) date('Y');
            $month = (int) date('n');
            $this->body = TemplateRenderer::renderForStudent($tpl->body, $student, $year, $month);
        }
    }

    public function getCounterProperty(): array
    {
        return SmsCounter::count($this->body, Setting::get('force_ascii', '1') === '1');
    }

    public function send(): void
    {
        $this->validate([
            'body' => 'required|string|min:3',
        ]);

        try {
            $sender = app(CampaignSender::class);
            $result = $sender->sendSingle($this->studentId, $this->body, 'manual');
            $this->resultMessage = __('send.single_sent', [
                'status' => (string) ($result['provider_status'] ?? ''),
                'cost' => number_format((float) ($result['cost'] ?? 0), 4),
            ]);
            $this->dispatch('flash', message: $this->resultMessage);
        } catch (\Throwable $e) {
            $this->resultMessage = __('send.send_failed', ['error' => $e->getMessage()]);
            $this->dispatch('flash', message: $this->resultMessage);
        }
    }

    public function render()
    {
        $templates = Template::all();
        return view('livewire.send-single-message', [
            'templates' => $templates,
            'counter' => $this->isOpen ? $this->counter : null,
        ]);
    }
}
