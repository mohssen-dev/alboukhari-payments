<?php

namespace App\Livewire;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Models\Template;
use App\Services\BulkGatePricing;
use App\Services\CampaignSender;
use App\Services\MonthNames;
use App\Services\RecipientListBuilder;
use App\Support\AuthorizesLivewireWrite;
use App\Support\SmsCounter;
use App\Support\TemplateVariables;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SendCampaign extends Component
{
    use AuthorizesLivewireWrite;

    public string $type = 'send_all';
    public int $year;
    public int $month;
    public ?float $thresholdAmount = null;
    public bool $groupByFamily = false;

    /** The selected template, or null for a manual message (kept in the template history on send). */
    public ?int $templateId = null;
    public string $body = '';
    public string $tag = '';

    public string $testPhone = '';

    public ?array $previewStats = null;
    public ?array $previewRecipients = null;
    public ?array $previewSkipped = null;
    /** Why the automatic preview could not be built (e.g. an empty message). */
    public ?string $previewError = null;
    public ?int $campaignId = null;
    public string $resultMessage = '';

    // Scheduling: prepare the campaign now, fire it later via the scheduler.
    public bool $scheduleEnabled = false;
    public string $scheduledAt = '';

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
        // The preview itself is built right after the first paint (wire:init).
    }

    public function loadDefaultTemplate()
    {
        $tpl = Template::library()->orderBy('id')->first();
        if ($tpl) {
            $this->templateId = $tpl->id;
            $this->body = $tpl->messageBody();
        }
    }

    /**
     * Every change rebuilds the preview automatically — recipients, segments,
     * cost and the real message of the first recipient — so what is shown is
     * always what would be sent. It used to wait for a Preview click and could
     * go stale: an admin previewed "20 late payers", switched the type to
     * "send to all" and launched while the screen still said 20.
     */
    public function updated($property): void
    {
        if ($property === 'templateId') {
            $tpl = $this->templateId ? Template::find($this->templateId) : null;
            if ($tpl) {
                $this->body = $tpl->messageBody();
            }
        }

        if ($property === 'body' && $this->templateId) {
            // Editing a template's text makes it a manual message: sent exactly
            // as typed, and kept in the template history when sent.
            $tpl = Template::find($this->templateId);
            if (!$tpl || trim($tpl->messageBody()) !== trim($this->body)) {
                $this->templateId = null;
            }
        }

        if (in_array($property, ['type', 'year', 'month', 'thresholdAmount', 'groupByFamily', 'templateId', 'body'], true)) {
            $this->refreshPreview();
        }
    }

    public function updatedType()
    {
        $this->thresholdAmount = in_array($this->type, ['paid_less_than', 'balance_above']) ? 30 : null;
    }

    public function refreshPreview(): void
    {
        try {
            $this->preview();
            $this->previewError = null;
        } catch (ValidationException $e) {
            $this->previewStats = null;
            $this->previewRecipients = null;
            $this->previewSkipped = null;
            $this->previewError = collect($e->errors())->flatten()->first();
            // Explain in the preview panel; don't paint red errors while typing.
            $this->resetErrorBag();
        }
    }

    /** The top-up tier the school buys credits at — sets what one credit is worth in euros. */
    public function setCreditTier(int $amount): void
    {
        $this->assertCanWrite();

        $pricing = app(BulkGatePricing::class);
        if (in_array($amount, array_column($pricing->tiers(), 'amount'), true)) {
            Setting::put(BulkGatePricing::TIER_SETTING, (string) $amount);
        }
        $this->refreshPreview();
    }

    /** Ask BulkGate (and the exchange rate) again instead of the cached figures. */
    public function refreshPricing(): void
    {
        app(BulkGatePricing::class)->refresh();
        $this->refreshPreview();
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
        ], [], [
            'body' => __('send.body'),
            'thresholdAmount' => __('send.threshold'),
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

        // Stored on the campaign in euros: the dearest operator, so it never under-promises.
        $quote = app(BulkGatePricing::class)->quote((int) $result['stats']['total_segments']);
        $result['stats']['estimated_cost'] = $quote['max']['eur']
            ?? (float) Setting::get('bulkgate_price_per_sms', '0.08') * $result['stats']['total_segments'];

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

        // The real message of the first recipient, not the raw {{…}} template.
        $text = $this->previewRecipients[0]['body'] ?? $this->body;

        try {
            $client = app(\App\Services\BulkGateClient::class);
            $result = $client->send($this->testPhone, $text, 'TEST');

            $count = SmsCounter::count($text, Setting::get('force_ascii', '1') === '1');
            \App\Models\MessageLog::create([
                'type' => 'test',
                'provider' => 'bulkgate',
                'phone' => $this->testPhone,
                'body' => $text,
                'segments' => $count['segments'],
                'status' => $result['status'],
                'tag' => 'TEST',
            ]);
            $this->dispatch('flash', message: __('flash.test_sent', ['phone' => $this->testPhone]) . ' (' . $result['status'] . ')');
        } catch (\Throwable $e) {
            $this->dispatch('flash', message: __('flash.send_error') . ' ' . $e->getMessage());
        }
    }

    /**
     * Save the campaign with a future fire time instead of sending now.
     * Recipients are NOT built here — campaigns:dispatch-scheduled builds
     * them at fire time so the list reflects payments made in between.
     */
    public function schedule()
    {
        $this->assertCanWrite();

        if ($this->campaignId) {
            $this->dispatch('flash', message: __('send.already_launched'));
            return;
        }

        $this->validate([
            'scheduledAt' => 'required|date|after:now',
        ], [], ['scheduledAt' => __('send.schedule_time')]);

        // Preview to catch empty target lists early (final list is rebuilt at fire time).
        $this->preview();
        if (!$this->previewStats || $this->previewStats['total_recipients'] === 0) {
            $this->dispatch('flash', message: __('flash.no_recipients'));
            return;
        }

        $this->keepManualTemplate();

        $campaign = Campaign::create([
            'type' => $this->type,
            'status' => 'queued',
            'scheduled_at' => $this->scheduledAt,
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

        $this->campaignId = $campaign->id;
        $this->resultMessage = __('send.scheduled_ok', [
            'time' => $campaign->scheduled_at->format('Y-m-d H:i'),
            'count' => $this->previewStats['total_recipients'],
        ]);
        $this->dispatch('flash', message: $this->resultMessage);
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

        $this->keepManualTemplate();

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

    /**
     * A manual message is kept in the template history ('manual' origin) the
     * moment it is sent or scheduled, so it can be reviewed or reused later.
     * The same text sent again reuses its history entry.
     */
    private function keepManualTemplate(): void
    {
        if ($this->templateId) {
            return;
        }

        $body = trim($this->body);
        $tpl = Template::manual()->where('body', $body)->first();

        if (!$tpl) {
            $plain = trim(preg_replace('/\s+/u', ' ', preg_replace(TemplateVariables::PATTERN, '…', $body)));
            $tpl = Template::create([
                'code' => 'manual_' . now()->format('YmdHis') . '_' . Str::lower(Str::random(4)),
                'name' => '✍️ ' . now()->format('Y-m-d H:i') . ' — ' . Str::limit($plain, 40),
                'language' => preg_match('/\p{Latin}/u', $body) ? 'nl' : 'ar',
                'body' => $body,
                'origin' => Template::ORIGIN_MANUAL,
                'default_for' => 'none',
            ]);
        }

        $this->templateId = $tpl->id;
        $this->dispatch('toast', message: __('send.manual_saved'), type: 'success');
    }

    public function render()
    {
        $forceAscii = Setting::get('force_ascii', '1') === '1';
        $sample = $this->previewRecipients[0]['body'] ?? null;

        // The selected template's Arabic translation — shown to staff so they
        // understand the message, never sent. Filled in for the sample recipient.
        $tpl = $this->templateId ? Template::find($this->templateId) : null;
        $translation = $tpl && trim((string) $tpl->body_ar) !== '' ? trim($tpl->body_ar) : null;
        $sampleTranslation = null;
        $sampleStudentId = $this->previewRecipients[0]['student_id'] ?? null;
        if ($translation && $sampleStudentId && ($student = \App\Models\Student::find($sampleStudentId))) {
            $sampleTranslation = $this->groupByFamily && $student->family
                ? \App\Services\TemplateRenderer::renderForFamily($translation, $student->family, $this->year, $this->month)
                : \App\Services\TemplateRenderer::renderForStudent($translation, $student, $this->year, $this->month);
        }

        return view('livewire.send-campaign', [
            'libraryTemplates' => Template::library()->orderBy('id')->get(),
            'manualTemplates' => Template::manual()->latest('id')->limit(15)->get(),
            'months' => MonthNames::full(),
            'types' => self::TYPES,
            'counter' => $this->counter,
            'sampleCounter' => $sample !== null ? SmsCounter::count($sample, $forceAscii) : null,
            'translation' => $translation,
            'sampleTranslation' => $sampleTranslation,
            'unknownVars' => TemplateVariables::unknownIn($this->body),
            'quote' => $this->previewStats ? app(BulkGatePricing::class)->quote((int) $this->previewStats['total_segments']) : null,
        ])->layout('layouts.app');
    }
}
