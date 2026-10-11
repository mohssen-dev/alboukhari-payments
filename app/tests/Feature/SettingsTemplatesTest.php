<?php

namespace Tests\Feature;

use App\Livewire\SettingsTemplates;
use App\Livewire\TemplatesList;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Templates: every template, with the text that is sent and its
 * translation, each edited in its own card. The automatic reminders'
 * templates can be edited but never deleted.
 */
class SettingsTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => "$role@settpl.test", 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    private function template(string $code, array $attrs = []): Template
    {
        return Template::create($attrs + [
            'code' => $code,
            'name' => "Name of $code",
            'language' => 'nl',
            'body' => "Body of $code",
            'body_ar' => "ترجمة $code",
            'origin' => Template::ORIGIN_LIBRARY,
            'default_for' => 'none',
        ]);
    }

    public function test_the_tab_lists_every_template_with_its_text_and_translation(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->template(Template::REMINDER_FIRST_FRIDAY);
        $this->template('nl_general');
        $this->template('manual_1', ['origin' => Template::ORIGIN_MANUAL, 'body' => 'Typed on the send page']);

        Livewire::test(SettingsTemplates::class)
            ->assertSee('Body of nl_first_friday')
            ->assertSee('ترجمة nl_general')
            ->assertSee('Typed on the send page')
            ->assertSee(__('settpl.auto_first_friday'));
    }

    public function test_the_settings_page_has_the_templates_tab(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->template('nl_general');

        $this->get(route('settings', ['tab' => 'templates']))
            ->assertOk()
            ->assertSee(__('settpl.intro'))
            ->assertSee('Body of nl_general');
    }

    public function test_a_template_is_edited_in_place(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $t = $this->template(Template::REMINDER_MID_MONTH);

        Livewire::test(SettingsTemplates::class)
            ->call('edit', $t->id)
            ->assertSet('body', 'Body of nl_mid_month')
            ->set('name', 'Midden van de maand')
            ->set('body', 'Beste ouder van {{student_name}}, betaal a.u.b.')
            ->set('body_ar', 'ولي أمر {{student_name}}، يرجى الدفع.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingId', null)
            ->assertDispatched('toast', type: 'success');

        $t->refresh();
        $this->assertSame('Midden van de maand', $t->name);
        $this->assertSame('Beste ouder van {{student_name}}, betaal a.u.b.', $t->body);
        $this->assertSame('ولي أمر {{student_name}}، يرجى الدفع.', $t->body_ar);
        $this->assertSame(Template::REMINDER_MID_MONTH, $t->code, 'the reminder still finds it');
    }

    public function test_a_misspelt_placeholder_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $t = $this->template('nl_general');

        Livewire::test(SettingsTemplates::class)
            ->call('edit', $t->id)
            ->set('body', 'Beste {{studnet_name}}')
            ->call('save')
            ->assertHasErrors('body');

        $this->assertSame('Body of nl_general', $t->fresh()->body);
    }

    public function test_a_new_template_gets_a_code_from_its_name(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        Livewire::test(SettingsTemplates::class)
            ->call('create')
            ->set('name', 'Rappel juni')
            ->set('body', 'Herinnering voor juni')
            ->call('save')
            ->assertHasNoErrors();
        Livewire::test(SettingsTemplates::class)
            ->call('create')
            ->set('name', 'Rappel juni')
            ->set('body', 'Nog een herinnering')
            ->call('save');

        $this->assertSame(['nl_rappel_juni', 'nl_rappel_juni_2'], Template::where('name', 'Rappel juni')->orderBy('id')->pluck('code')->all());
        $this->assertSame(Template::ORIGIN_LIBRARY, Template::where('code', 'nl_rappel_juni')->value('origin'));
    }

    public function test_the_reminders_templates_cannot_be_deleted_but_others_can(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $reminder = $this->template(Template::REMINDER_FIRST_FRIDAY);
        $other = $this->template('nl_general');

        Livewire::test(SettingsTemplates::class)
            ->call('delete', $reminder->id)
            ->assertDispatched('toast', type: 'error')
            ->call('delete', $other->id);
        Livewire::test(TemplatesList::class)->call('delete', $reminder->id);

        $this->assertNotNull($reminder->fresh(), 'kept in both editors');
        $this->assertSoftDeleted($other);
    }

    public function test_viewers_cannot_change_templates(): void
    {
        $this->actingAs($this->user(User::ROLE_VIEWER));
        $t = $this->template('nl_general');

        Livewire::test(SettingsTemplates::class)->call('edit', $t->id)->assertForbidden();
        $this->assertSame('Body of nl_general', $t->fresh()->body);
    }
}
