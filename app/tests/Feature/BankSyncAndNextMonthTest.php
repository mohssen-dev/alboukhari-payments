<?php

namespace Tests\Feature;

use App\Livewire\BankSyncDate;
use App\Livewire\PaymentModal;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The navbar's "last bank sync" date, and "save & next" in the payment
 * window moving to the same student's next month.
 */
class BankSyncAndNextMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role) . ' User', 'email' => "{$role}@banksync.test", 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    // ---- bank sync date ----

    public function test_staff_set_the_bank_sync_date_and_it_is_kept_with_who_and_when(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));

        Livewire::test(BankSyncDate::class)
            ->assertSee(__('banksync.not_set_hint'))
            ->set('date', '2026-09-10')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSee('10 ' . __('September') . ' 2026', false)
            ->assertSee(trans_choice('banksync.days_ago', 3, ['count' => 3]));

        $this->assertSame('2026-09-10', Setting::get(BankSyncDate::DATE));
        $this->assertSame('Staff User', Setting::get(BankSyncDate::BY));
        $this->assertSame('2026-09-13 10:00', Setting::get(BankSyncDate::AT));
    }

    public function test_a_future_or_invalid_date_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        Livewire::test(BankSyncDate::class)->set('date', '2026-09-14')->call('save')->assertHasErrors('date');
        Livewire::test(BankSyncDate::class)->set('date', 'yesterday')->call('save')->assertHasErrors('date');
        Livewire::test(BankSyncDate::class)->set('date', '')->call('save')->assertHasErrors('date');

        $this->assertNull(Setting::get(BankSyncDate::DATE));
    }

    public function test_viewers_see_the_date_but_cannot_change_it(): void
    {
        Setting::put(BankSyncDate::DATE, '2026-09-01');
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(BankSyncDate::class)
            ->assertSee('01-09')
            ->assertDontSeeHtml('wire:submit="save"')
            ->set('date', '2026-09-12')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('2026-09-01', Setting::get(BankSyncDate::DATE));
    }

    public function test_the_navbar_shows_the_date_on_every_page(): void
    {
        Setting::put(BankSyncDate::DATE, '2026-09-10');
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $this->get('/reports')->assertOk()->assertSee('bank-sync__chip', false)->assertSee('10-09');
    }

    // ---- payment window: save & next month ----

    public function test_save_and_next_opens_the_same_students_next_month_keeping_date_and_method(): void
    {
        $this->actingAs($this->user(User::ROLE_STAFF));
        $s = Student::create(['name' => 'Two Months', 'default_fee_amount' => 30, 'enrolled_at' => '2026-01-01']);
        $other = Student::create(['name' => 'Next Student', 'default_fee_amount' => 30, 'enrolled_at' => '2026-01-01']);

        Livewire::test(PaymentModal::class)
            ->call('open', $s->id, 2026, 5)
            ->assertSee(__('payment.save_next_month', ['month' => __('June')]))
            ->set('amount', 30)
            ->set('method', 'bank')
            ->set('paid_at', '2026-09-02')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertSet('isOpen', true)
            ->assertSet('studentId', $s->id)       // same student, not the next one
            ->assertSet('year', 2026)
            ->assertSet('month', 6)
            ->assertSet('method', 'bank')
            ->assertSet('paid_at', '2026-09-02')
            ->set('amount', 30)
            ->call('save', true)
            ->assertSet('month', 7);

        $this->assertSame([5, 6], Payment::where('student_id', $s->id)->orderBy('period_month')->pluck('period_month')->all());
        $this->assertSame(0, Payment::where('student_id', $other->id)->count());
        $this->assertSame(['bank'], Payment::where('student_id', $s->id)->distinct()->pluck('method')->all());
    }

    public function test_december_moves_on_to_january_of_the_next_year(): void
    {
        $this->assertSame([2027, 1], PaymentModal::nextMonth(2026, 12));
        $this->assertSame([2026, 10], PaymentModal::nextMonth(2026, 9));
    }
}
