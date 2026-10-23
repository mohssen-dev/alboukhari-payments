<?php

namespace Tests\Feature;

use App\Livewire\BankSyncDate;
use App\Livewire\LogsPage;
use App\Models\MessageLog;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\GridRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The logs page: changes and messages in words, searchable by student, and
 * the grid's "has earlier unpaid months" filter.
 */
class LogsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin One', 'email' => 'admin@logs.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    private function pay(Student $s, int $month, float $amount = 30, string $method = 'cash'): Payment
    {
        return Payment::create(['student_id' => $s->id, 'period_year' => (int) date('Y'), 'period_month' => $month, 'amount' => $amount, 'method' => $method, 'paid_at' => date('Y') . '-0' . min(9, $month) . '-05']);
    }

    public function test_changes_read_as_sentences_with_the_student_to_open(): void
    {
        $s = Student::create(['name' => 'Aadam Taibi', 'default_fee_amount' => 30]);
        $p = $this->pay($s, 6, 30, 'bank');
        $p->update(['amount' => 25]);
        $s->update(['default_fee_amount' => 20]);

        $this->get(route('activity.index'))->assertOk()
            ->assertSee(__('log.payment_created'))
            ->assertSee('30.00 €')
            ->assertSee(__('log.payment_updated'))
            ->assertSee(__('log.changed', ['field' => __('log.field.amount'), 'from' => '30.00 €', 'to' => '25.00 €']))
            ->assertDontSee(__('log.changed', ['field' => __('log.field.hidden'), 'from' => __('log.no'), 'to' => __('log.no')]))
            ->assertSee(__('log.student_updated'))
            ->assertSee('Aadam Taibi')
            ->assertSee("open-student-panel', { studentId: {$s->id} }", false)
            ->assertSee('Admin One');
    }

    public function test_search_by_student_finds_their_payments_and_changes_only(): void
    {
        $a = Student::create(['name' => 'Aadam Taibi', 'default_fee_amount' => 30]);
        $b = Student::create(['name' => 'Safa Laghmich', 'default_fee_amount' => 30]);
        $this->pay($a, 3);
        $this->pay($b, 4);

        $rows = Livewire::test(LogsPage::class)->set('search', 'Safa')->viewData('rows');
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($r) => $r['student_id'] === $b->id));

        $rows = Livewire::test(LogsPage::class)->call('forStudent', $a->id)->viewData('rows');
        $this->assertTrue($rows->every(fn ($r) => $r['student_id'] === $a->id));
        $this->assertTrue($rows->contains(fn ($r) => $r['title'] === __('log.payment_created')));
    }

    public function test_filters_by_kind_action_user_and_date(): void
    {
        $s = Student::create(['name' => 'Kid', 'default_fee_amount' => 30]);
        $p = $this->pay($s, 1);
        $p->delete();
        activity(BankSyncDate::LOG)->causedBy($this->admin)->withProperties(['date' => '2026-09-13', 'note' => 'ING'])->log('bank payments synced up to 2026-09-13');

        $kinds = fn ($lw) => $lw->viewData('rows')->map(fn ($r) => $r['activity']->log_name)->unique()->values()->all();
        $this->assertSame(['bank-sync'], $kinds(Livewire::test(LogsPage::class)->set('kind', 'bank-sync')));

        $deleted = Livewire::test(LogsPage::class)->set('event', 'deleted')->viewData('rows');
        $this->assertSame([__('log.payment_deleted')], $deleted->pluck('title')->unique()->values()->all());

        $this->assertCount(0, Livewire::test(LogsPage::class)->set('from', now()->addDay()->format('Y-m-d'))->viewData('rows'));
        $this->assertCount(0, Livewire::test(LogsPage::class)->set('causer', (string) ($this->admin->id + 99))->viewData('rows'));

        $this->get(route('activity.index', ['kind' => 'bank-sync']))->assertOk()->assertSee('📝 ING');
    }

    public function test_messages_tab_lists_sent_and_failed_messages_by_student(): void
    {
        $s = Student::create(['name' => 'Aadam Taibi', 'phone_primary_e164' => '+31624915721', 'default_fee_amount' => 30]);
        MessageLog::create(['student_id' => $s->id, 'type' => 'manual_single', 'provider' => 'bulkgate', 'phone' => '+31624915721', 'body' => 'Herinnering juni', 'segments' => 1, 'status' => 'OK', 'cost' => 0.08]);
        MessageLog::create(['student_id' => $s->id, 'type' => 'manual_single', 'provider' => 'bulkgate', 'phone' => '+31624915721', 'body' => 'Tweede', 'segments' => 1, 'status' => 'ERROR: NO_CREDIT']);

        Livewire::test(LogsPage::class)->set('tab', 'messages')
            ->assertSee('Herinnering juni')
            ->assertSee('NO_CREDIT')
            ->set('status', 'failed')
            ->assertDontSee('Herinnering juni')
            ->set('status', '')
            ->set('search', '0624915721')
            ->assertSee('Herinnering juni');
    }

    public function test_a_deleted_student_is_named_but_not_opened(): void
    {
        $s = Student::create(['name' => 'Gone Kid', 'default_fee_amount' => 30]);
        $this->pay($s, 2);
        $s->delete();

        $this->get(route('activity.index'))->assertOk()
            ->assertSee('Gone Kid')
            ->assertDontSee("open-student-panel', { studentId: {$s->id} }", false);
    }

    public function test_staff_cannot_open_the_logs(): void
    {
        $staff = User::create(['name' => 'S', 'email' => 's@logs.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_STAFF, 'is_active' => true]);

        $this->actingAs($staff)->get(route('activity.index'))->assertForbidden();
    }

    // ---- grid filter ----

    public function test_a_student_who_skipped_a_month_has_earlier_unpaid_months(): void
    {
        $y = (int) date('Y');
        $skipped = Student::create(['name' => 'Skipped Feb', 'default_fee_amount' => 30, 'enrolled_at' => "$y-01-01"]);
        $inOrder = Student::create(['name' => 'In order', 'default_fee_amount' => 30, 'enrolled_at' => "$y-01-01"]);
        $this->pay($skipped, 1);
        $this->pay($skipped, 3);
        $this->pay($inOrder, 1);
        $this->pay($inOrder, 2);

        $gap = fn (Student $s) => GridRow::payload($s->id, $y)['row']['gapMonths'];
        $this->assertSame(1, $gap($skipped), 'February owes, March is paid');
        $this->assertSame(0, $gap($inOrder), 'owing only after the last paid month is not a gap');
    }
}
