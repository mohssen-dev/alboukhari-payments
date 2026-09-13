<?php

namespace Tests\Feature;

use App\Livewire\SendCampaign;
use App\Livewire\TemplatesList;
use App\Models\Campaign;
use App\Models\Family;
use App\Models\Student;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateRenderer;
use App\Support\SmsCounter;
use App\Support\SmsText;
use App\Support\TemplateVariables;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Templates: Dutch text (sent) + an Arabic translation shown to staff only,
 * English placeholder names, a manual message kept in the history on send,
 * and a preview that builds itself.
 */
class TemplatesBilingualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@templates.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]));
    }

    private function messageable(string $name = 'Kid'): Student
    {
        return Student::create(['name' => $name, 'phone_primary_e164' => '+31612345678', 'default_fee_amount' => 30]);
    }

    // ---- placeholders ----

    public function test_english_placeholders_render_and_old_names_still_work(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);

        $out = TemplateRenderer::renderForStudent('{{student_name}} {{month_nl}} {{month_ar}} {{balance}} | {{Naam}} {{المتبقي}}', $s, 2026, 3);

        $this->assertSame('Kid maart مارس 30.00 | Kid 30.00', $out);
    }

    public function test_family_placeholders_also_work_when_sent_per_student(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);

        $out = TemplateRenderer::renderForStudent('{{children_names}} · {{family_balance}} · {{unpaid_names}}', $s, 2026, 3);

        $this->assertSame('Kid · 30.00 · Kid', $out, 'no raw {{…}} may reach a parent');
    }

    public function test_family_names_read_right_in_both_languages(): void
    {
        $f = Family::create(['phone_primary_e164' => '+31612345678']);
        Student::create(['name' => 'Kid A', 'family_id' => $f->id, 'default_fee_amount' => 30]);
        Student::create(['name' => 'Kid B', 'family_id' => $f->id, 'default_fee_amount' => 30]);

        $out = TemplateRenderer::renderForFamily('{{student_name}} / {{children_names}} / {{family_balance}}', $f->fresh(), 2026, 3);

        $this->assertSame('Kid A & Kid B / Kid A, Kid B / 60.00', $out);
    }

    public function test_unknown_placeholders_are_found_and_old_names_normalised(): void
    {
        $this->assertSame(['naam_student'], TemplateVariables::unknownIn('{{naam_student}} {{student_name}} {{المبلغ_العائلي}}'));
        $this->assertSame('{{family_total}} {{student_name}}', TemplateVariables::normalize('{{المبلغ_العائلي}} {{ Naam }}'));
    }

    // ---- what is sent ----

    public function test_the_translation_is_never_part_of_the_message(): void
    {
        $t = new Template(['body' => 'NL', 'body_ar' => 'AR']);

        $this->assertSame('NL', $t->messageBody());
    }

    public function test_arabic_typed_into_a_message_is_not_deleted_by_force_ascii(): void
    {
        $mixed = SmsText::prepare("Hallo é\n\nمرحبا", true);
        $this->assertTrue($mixed['unicode']);
        $this->assertStringContainsString('مرحبا', $mixed['text'], 'force_ascii used to delete every Arabic letter');

        $dutch = SmsText::prepare('Hallo é €', true);
        $this->assertFalse($dutch['unicode']);
        $this->assertSame('Hallo e EUR', $dutch['text'], 'Dutch-only text is still sanitised to cheap GSM');

        $counter = SmsCounter::count(str_repeat('ب', 100), true);
        $this->assertSame('unicode', $counter['encoding']);
        $this->assertSame(2, $counter['segments']);
    }

    public function test_the_shipped_templates_fit_one_sms_and_the_arrears_reminder_is_polite(): void
    {
        (new DefaultTemplatesSeeder())->run();

        $arrears = Template::where('code', 'nl_family_arrears')->firstOrFail();
        $this->assertStringStartsWith('Assalamu alaikum', $arrears->body, 'the arrears reminder opens with the greeting');
        $this->assertStringStartsWith('السلام عليكم', $arrears->body_ar);
        $this->assertStringNotContainsString('month', $arrears->body, 'no specific month');

        foreach (Template::all() as $tpl) {
            $this->assertSame([], TemplateVariables::unknownIn($tpl->body . $tpl->body_ar), $tpl->code);
            $this->assertSame('gsm', SmsCounter::count($tpl->messageBody(), true)['encoding'], $tpl->code . ' must stay cheap GSM');
        }
        $this->assertSame(1, SmsCounter::count($arrears->messageBody(), true)['segments']);
    }

    // ---- send page ----

    public function test_the_preview_builds_itself_when_the_message_changes(): void
    {
        $this->messageable();

        Livewire::test(SendCampaign::class)
            ->set('body', 'Beste {{student_name}}, test.')
            ->assertSet('previewError', null)
            ->assertSet('previewStats.total_recipients', 1)
            ->assertSet('previewRecipients.0.body', 'Beste Kid, test.');
    }

    public function test_a_template_sends_only_dutch_and_shows_its_translation(): void
    {
        $this->messageable();
        $tpl = Template::create(['code' => 'nl_x', 'name' => 'X', 'language' => 'nl', 'body' => 'Hallo {{student_name}}', 'body_ar' => 'مرحبا {{student_name}}', 'default_for' => 'none']);

        Livewire::test(SendCampaign::class)
            ->set('templateId', $tpl->id)
            ->assertSet('body', 'Hallo {{student_name}}')
            ->assertSet('previewRecipients.0.body', 'Hallo Kid')
            ->assertSee('مرحبا Kid');   // the filled-in translation, for staff
    }

    public function test_editing_a_templates_text_makes_it_a_manual_message(): void
    {
        $tpl = Template::create(['code' => 'nl_x', 'name' => 'X', 'language' => 'nl', 'body' => 'Hallo {{student_name}}', 'default_for' => 'none']);

        Livewire::test(SendCampaign::class)
            ->set('templateId', $tpl->id)
            ->set('body', 'Hallo {{student_name}}!')
            ->assertSet('templateId', null);
    }

    public function test_a_manual_message_is_kept_in_the_template_history(): void
    {
        $this->messageable();

        Livewire::test(SendCampaign::class)
            ->set('templateId', null)
            ->set('body', 'Beste {{student_name}}, handmatig bericht.')
            ->set('scheduleEnabled', true)
            ->set('scheduledAt', now()->addHour()->format('Y-m-d\TH:i'))
            ->call('schedule');

        $tpl = Template::manual()->first();
        $this->assertNotNull($tpl, 'the manual text must be saved to the template history');
        $this->assertSame('Beste {{student_name}}, handmatig bericht.', $tpl->body);
        $this->assertSame($tpl->id, Campaign::first()->template_id);
    }

    // ---- templates editor ----

    public function test_a_template_with_an_unknown_placeholder_is_refused(): void
    {
        $before = Template::count(); // the migration ships the arrears reminder

        Livewire::test(TemplatesList::class)
            ->call('newTemplate')
            ->set('code', 'nl_new')->set('name', 'New')
            ->set('body', 'Hallo {{naam_student}}')
            ->call('save')
            ->assertHasErrors('body');

        $this->assertSame($before, Template::count());
    }

    public function test_saving_keeps_the_translation_and_renames_old_placeholders(): void
    {
        Livewire::test(TemplatesList::class)
            ->call('newTemplate')
            ->set('code', 'nl_new')->set('name', 'New')
            ->set('body', 'Hallo {{Naam}}')
            ->set('body_ar', 'مرحبا {{اسم}}')
            ->call('save')
            ->assertHasNoErrors();

        $t = Template::where('code', 'nl_new')->firstOrFail();
        $this->assertSame('Hallo {{student_name}}', $t->body);
        $this->assertSame('مرحبا {{student_name}}', $t->body_ar);
        $this->assertSame(Template::ORIGIN_LIBRARY, $t->origin);
        $this->assertSame('Hallo {{student_name}}', $t->messageBody());
    }

    public function test_the_editor_shows_the_real_message_and_the_translation_live(): void
    {
        $this->messageable('Kid');

        Livewire::test(TemplatesList::class)
            ->call('newTemplate')
            ->set('body', 'Beste {{student_name}}')
            ->set('body_ar', 'مرحبا {{student_name}}')
            ->assertSee('Beste Kid')
            ->assertSee('مرحبا Kid');
    }
}
