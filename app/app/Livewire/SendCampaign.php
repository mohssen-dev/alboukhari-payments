<?php

namespace App\Livewire;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Template;
use App\Services\CampaignSender;
use App\Services\MonthNames;
use App\Services\RecipientListBuilder;
use App\Support\AuthorizesLivewireWrite;
use App\Support\SmsCounter;
use Livewire\Component;

class SendCampaign extends Component
{
    use AuthorizesLivewireWrite;

    public string $type = 'send_all';
    public int $year;
    public int $month;
    public ?float $thresholdAmount = null;
    public bool $groupByFamily = false;

    public ?int $templateId = null;
    public string $body = '';
    public string $tag = '';

    public string $testPhone = '';

    public ?array $previewStats = null;
    public ?array $previewRecipients = null;
    public ?array $previewSkipped = null;
    public ?int $campaignId = null;
    public string $resultMessage = '';

    // Labels are i18n keys — rendered with __() in the blade so every locale
    // sees its own language (they were hardcoded Arabic before).
    private const TYPES = [
        'send_all' => 'send.type_send_all',
        'unpaid_by_month' => 'send.type_unpaid_by_month',
        'late_mid_month' => 'send.type_late_mid_month',
        'paid_less_than' => 'send.type_paid_less_than',
        'balance_above' => 'send.type_balance_above',
    ];

    public function mount()
    {
        $this->year = (int) date('Y');
        $this->month = (int) date('n');

        // Honor the grid's targeted-send links (/send?type=late_mid_month …).
        // Ignoring this param silently opened every link as "send to ALL".
        $requestedType = request()->query('type');
        if (is_string($requestedType) && array_key_exists($requestedType, self::TYPES)) {
            $this->type = $requestedType;
            $this->updatedType();
        }

        $this->loadDefaultTemplate();
    }

    public function loadDefaultTemplate()
    {
        $tpl = Template::query()->first();
        if ($tpl) {
            $this->templateId = $tpl->id;
            $this->body = $tpl->body;
        }
    }

    public function updatedType()
    {
        $this->thresholdAmount = in_array($this->type, ['paid_less_than', 'balance_above']) ? 30 : null;
    }

    public function updatedTemplateId()
    {
        if ($this->templateId) {
            $tpl = Template::find($this->templateId);
            if ($tpl) $this->body = $tpl->body;
        }
    }

    public function getCounterProperty(): array
    {
        return SmsCounter::count($this->body, Setting::get('force_ascii', '1') === '1');
    }

    public function preview()
    {
        $this->validate([
            'type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'body' => 'required|string|min:3',
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
            'thresholdAmount' => in_array($this->type, ['paid_less_than', 'balance_above']) ? 'required|numeric|min:0' : 'nullable',
        ]);

        // إنشاء حملة draft مؤقتة (لا نحفظها بعد)
        $tempCampaign = new Campaign([
            'type' => $this->type,
            'status' => 'draft',
            'period_year' => $this->year,
            'period_month' => $this->month,
            'threshold_amount' => $this->thresholdAmount,
            'body_template' => $this->body,
            'group_by_family' => $this->groupByFamily,
            'tag' => $this->tag ?: $this->type,
        ]);

        $builder = new RecipientListBuilder();
        $result = $builder->build($tempCampaign);

        $price = (float) Setting::get('bulkgate_price_per_sms', '0.08');
        $result['stats']['estimated_cost'] = $price * $result['stats']['total_segments'];

        $this->previewStats = $result['stats'];
        $this->previewRecipients = array_slice($result['recipients'], 0, 20);
        $this->previewSkipped = array_slice($result['skipped'], 0, 20);
    }

    public function sendTest()
    {
        $this->assertCanWrite();

        if (empty($this->testPhone)) {
            $this->dispatch('flash', message: __('flash.enter_test_phone'));
            return;
        }
        if (empty(trim($this->body))) {
            $this->dispatch('flash', message: __('flash.enter_message_body'));
            return;
        }

        try {
            $sender = app(CampaignSender::class);
            $client = app(\App\Services\BulkGateClient::class);
            $result = $client->send($this->testPhone, $this->body, 'TEST');

            $count = SmsCounter::count($this->body, true);
            \App\Models\MessageLog::create([
                'type' => 'test',
                'provider' => 'bulkgate',
                'phone' => $this->testPhone,
                'body' => $this->body,
                'segments' => $count['segments'],
                'status' => $result['status'],
                'tag' => 'TEST',
            ]);
            $this->dispatch('flash', message: __('flash.test_sent', ['phone' => $this->testPhone]) . ' (' . $result['status'] . ')');
        } catch (\Throwable $e) {
            $this->dispatch('flash', message: __('flash.send_error') . ' ' . $e->getMessage());
        }
    }

    public function launch()
    {
        $this->assertCanWrite();

        // Re-entry guard: a second click (or queued Livewire request) after a
        // successful launch would create and send a SECOND campaign —
        // double-billing every SMS. Force an explicit page refresh to send again.
        if ($this->campaignId) {
            $this->dispatch('flash', message: __('send.already_launched'));
            return;
        }

        $this->preview();
        if (!$this->previewStats || $this->previewStats['total_recipients'] === 0) {
            $this->dispatch('flash', message: __('flash.no_recipients'));
            return;
        }

        \DB::transaction(function () {
            $campaign = Campaign::create([
                'type' => $this->type,
                'status' => 'queued',
                'period_year' => $this->year,
                'period_month' => $this->month,
                'threshold_amount' => $this->thresholdAmount,
                'template_id' => $this->templateId,
                'body_template' => $this->body,
                'group_by_family' => $this->groupByFamily,
                'tag' => $this->tag ?: $this->type,
                'total_recipients' => $this->previewStats['total_recipients'],
                'estimated_cost' => $this->previewStats['estimated_cost'] ?? 0,
            ]);

            $builder = new RecipientListBuilder();
            $result = $builder->build($campaign);

            foreach ($result['recipients'] as $r) {
                CampaignRecipient::firstOrCreate(
                    ['idempotency_key' => $r['idempotency_key']],
                    [
                        'campaign_id' => $campaign->id,
                        'student_id' => $r['student_id'] ?? null,
                        'family_id' => $r['family_id'] ?? null,
                        'phone_e164' => $r['phone'],
                        'body_personalized' => $r['body'],
                        'status' => 'pending',
                        'segments' => $r['segments'],
                    ]
                );
            }

            $this->campaignId = $campaign->id;
        });

        // Send now (synchronously for simplicity; in production use Queue)
        $campaign = Campaign::find($this->campaignId);
        $sender = app(CampaignSender::class);
        $r = $sender->sendCampaign($campaign);
        $this->resultMessage = __('Sent') . ': ' . ($r['sent'] ?? 0) . ' · ' . __('Failed') . ': ' . ($r['failed'] ?? 0) . ' · ' . __('columns.status') . ': ' . ($r['status'] ?? '');

        $this->dispatch('flash', message: $this->resultMessage);
    }

    public function render()
    {
        $templates = Template::all();
        $months = MonthNames::full();
        return view('livewire.send-campaign', [
            'templates' => $templates,
            'months' => $months,
            'types' => self::TYPES,
            'counter' => $this->counter,
        ])->layout('layouts.app');
    }
}
