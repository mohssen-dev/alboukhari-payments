<?php

namespace Tests\Feature;

use App\Livewire\FamilyModal;
use App\Livewire\PaymentModal;
use App\Livewire\QuickEntry;
use App\Livewire\SendCampaign;
use App\Livewire\SettingsDefaults;
use App\Models\Family;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Template;
use App\Models\User;
use App\Support\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Defaults, and the payment method no longer preset to "cash":
 * with no default the windows start with nothing picked and will not save
 * until a method is chosen.
 */
class PaymentDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => "$role@defaults.test", 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    private function student(array $attrs = []): Student
    {
        return Student::create($attrs + ['name' => 'Kid', 'default_fee_amount' => 30, 'enrolled_at' => date('Y') . '-01-01']);
    }

    // ---- no default: pick every time ----

    public function test_the_payment_window_starts_with_no_method_and_refuses_to_save_without_one(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $s = $this->student();

        $lw = Livewire::test(PaymentModal::class)->call('open', $s->id, (int) date('Y'), 1)
            ->assertSet('method', '')
            ->call('save')
            ->assertHasErrors(['method'])
            ->assertSee(__('payment.method_required'));
        $this->assertSame(0, Payment::count());

        $lw->set('method', 'bank')->call('save')->assertHasNoErrors();
        $this->assertSame('bank', Payment::sole()->method);
    }

    public function test_the_family_window_and_quick_entry_also_wait_for_a_method(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $f = Family::create(['guardian_name' => 'F', 'phone_primary_e164' => '+31612345678']);
        $s = $this->student(['family_id' => $f->id]);

        Livewire::test(FamilyModal::class)->call('open', $s->id, null, 1)
            ->assertSet('method', '')
            ->set("amounts.{$s->id}", '30')
            ->call('saveAll')
            ->assertHasErrors(['method']);

        Livewire::test(QuickEntry::class)->call('selectStudent', $s->id)
            ->assertSet('method', '')
            ->call('save')
            ->assertHasErrors(['method']);

        $this->assertSame(0, Payment::count());
    }

    public function test_after_a_family_save_the_next_payment_picks_its_method_again(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $f = Family::create(['guardian_name' => 'F', 'phone_primary_e164' => '+31612345678']);
        $s = $this->student(['family_id' => $f->id]);

        Livewire::test(FamilyModal::class)->call('open', $s->id, null, 1)
            ->set('method', 'cash')
            ->set("amounts.{$s->id}", '30')
            ->call('saveAll')
            ->assertHasNoErrors()
            ->assertSet('method', '');
    }

    // ---- defaults set ----

    public function test_a_default_method_and_an_empty_date_are_applied(): void
    {
        Setting::put(Defaults::PAYMENT_METHOD, 'bank');
        Setting::put(Defaults::PAYMENT_DATE, 'empty');
        $this->actingAs($this->user(User::ROLE_STAFF));
        $s = $this->student();

        Livewire::test(PaymentModal::class)->call('open', $s->id, (int) date('Y'), 1)
            ->assertSet('method', 'bank')
            ->assertSet('paid_at', '')
            ->call('save')
            ->assertHasErrors(['paid_at']);

        Livewire::test(QuickEntry::class)->assertSet('method', 'bank');
    }

    public function test_amounts_can_be_left_to_be_typed_but_recorded_totals_stay(): void
    {
        Setting::put(Defaults::PAYMENT_AMOUNT, 'empty');
        Setting::put(Defaults::PAYMENT_METHOD, 'cash');
        $this->actingAs($this->user(User::ROLE_STAFF));
        $f = Family::create(['guardian_name' => 'F', 'phone_primary_e164' => '+31612345678']);
        $paid = $this->student(['family_id' => $f->id, 'name' => 'Paid']);
        $owes = $this->student(['family_id' => $f->id, 'name' => 'Owes']);
        Payment::create(['student_id' => $paid->id, 'period_year' => (int) date('Y'), 'period_month' => 1, 'amount' => 30, 'method' => 'cash', 'paid_at' => date('Y') . '-01-05']);

        $lw = Livewire::test(FamilyModal::class)->call('open', $paid->id, null, 1);
        $this->assertSame('30', $lw->get('amounts')[$paid->id], 'the recorded total is never blanked — that would reduce it');
        $this->assertSame('', $lw->get('amounts')[$owes->id], 'what is owed is not suggested');

        Livewire::test(PaymentModal::class)->call('open', $owes->id, (int) date('Y'), 1)->assertSet('amount', null);
    }

    public function test_the_family_window_can_open_on_the_current_month(): void
    {
        Setting::put(Defaults::FAMILY_OPENS_ON, 'current');
        $this->actingAs($this->user(User::ROLE_STAFF));
        $s = $this->student(); // owes every month since January

        Livewire::test(FamilyModal::class)->call('open', $s->id)->assertSet('selectedMonths', [(int) date('n')]);
    }

    public function test_the_send_page_starts_with_the_default_template_and_family_grouping(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        Template::create(['code' => 'nl_a', 'name' => 'A', 'language' => 'nl', 'body' => 'Tekst A', 'origin' => 'library', 'default_for' => 'none']);
        $b = Template::create(['code' => 'nl_b', 'name' => 'B', 'language' => 'nl', 'body' => 'Tekst B', 'origin' => 'library', 'default_for' => 'none']);
        Setting::put(Defaults::SEND_TEMPLATE, (string) $b->id);
        Setting::put(Defaults::SEND_GROUP_BY_FAMILY, '1');

        Livewire::test(SendCampaign::class)
            ->assertSet('templateId', $b->id)
            ->assertSet('body', 'Tekst B')
            ->assertSet('groupByFamily', true);
    }

    // ---- the settings tab ----

    public function test_admin_saves_the_defaults_and_bad_values_are_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        Livewire::test(SettingsDefaults::class)
            ->set('values.' . Defaults::PAYMENT_METHOD, 'cheque')
            ->call('save')
            ->assertHasErrors('values.' . Defaults::PAYMENT_METHOD);

        Livewire::test(SettingsDefaults::class)
            ->set('values.' . Defaults::PAYMENT_METHOD, 'bank')
            ->set('values.' . Defaults::FAMILY_OPENS_ON, 'current')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('bank', Defaults::paymentMethod());
        $this->assertFalse(Defaults::familyOpensOnOldestUnpaid());

        $this->get(route('settings', ['tab' => 'defaults']))->assertOk()->assertSee(__('defaults.method'));
    }

    public function test_staff_cannot_change_the_defaults(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(SettingsDefaults::class)->set('values.' . Defaults::PAYMENT_METHOD, 'bank')->call('save')->assertForbidden();
        $this->assertSame('', Defaults::paymentMethod());
    }
}
