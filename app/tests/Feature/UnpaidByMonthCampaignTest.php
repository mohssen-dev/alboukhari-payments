<?php

namespace Tests\Feature;

use App\Livewire\SendCampaign;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\MonthStatusResolver;
use App\Services\RecipientListBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Didn't pay a month": pick the month, get everyone who still owes for it.
 * On 13 September, May used to find nobody — only 'unpaid' matched, and a
 * month turns 'late' on the 15th of the following month.
 */
class UnpaidByMonthCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-13 10:00:00');
        $this->resetResolverToday();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetResolverToday();
        parent::tearDown();
    }

    private function resetResolverToday(): void
    {
        $r = new \ReflectionClass(MonthStatusResolver::class);
        foreach (['todayYm', 'todayDay'] as $prop) {
            $r->getProperty($prop)->setValue(null, null);
        }
    }

    private function student(string $name, array $paidMonths = [], array $attrs = []): Student
    {
        static $n = 0;
        $s = Student::create($attrs + [
            'name' => $name,
            'phone_primary_e164' => sprintf('+316123456%02d', ++$n),   // real mobile ranges — +3160… is not SMS-capable
            'default_fee_amount' => 30,
            'enrolled_at' => '2026-01-01',
            'allow_sms' => true,
        ]);
        foreach ($paidMonths as $month => $amount) {
            Payment::create(['student_id' => $s->id, 'period_year' => 2026, 'period_month' => $month, 'amount' => $amount, 'paid_at' => '2026-05-01', 'method' => 'cash']);
        }

        return $s;
    }

    private function names(int $month): array
    {
        $campaign = new Campaign(['type' => 'unpaid_by_month', 'period_year' => 2026, 'period_month' => $month, 'body_template' => 'Beste ouder van {{student_name}}', 'group_by_family' => false]);

        return collect((new RecipientListBuilder())->build($campaign)['recipients'])->pluck('name')->sort()->values()->all();
    }

    public function test_an_older_month_lists_everyone_who_still_owes_for_it(): void
    {
        $this->student('Paid May', [5 => 30]);
        $this->student('Nothing for May', [6 => 30]);
        $this->student('Half of May', [5 => 15]);
        $this->student('Joined in June', [], ['enrolled_at' => '2026-06-01']);
        $this->student('Left in April', [], ['withdrawn_at' => '2026-05-01']);

        $this->assertSame('late', MonthStatusResolver::resolve(Student::where('name', 'Nothing for May')->first(), 2026, 5));
        $this->assertSame(['Half of May', 'Nothing for May'], $this->names(5));
    }

    public function test_last_month_before_the_15th_and_a_future_month(): void
    {
        $this->student('Paid August', [8 => 30]);
        $this->student('Owes August');

        $this->assertSame(['Owes August'], $this->names(8));   // still 'unpaid' until 15 September
        $this->assertSame([], $this->names(11));              // November is not due yet
    }

    public function test_the_send_page_preview_lists_them_for_the_chosen_month(): void
    {
        $this->actingAs(User::create(['name' => 'S', 'email' => 's@unpaid.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_STAFF, 'is_active' => true]));
        $this->student('Paid May', [5 => 30]);
        $this->student('Owes May A');
        $this->student('Owes May B', [5 => 10]);

        Livewire::test(SendCampaign::class)
            ->set('type', 'unpaid_by_month')
            ->set('year', 2026)
            ->set('month', 5)
            ->set('body', 'Beste ouder van {{student_name}}, betaling voor {{month_nl}}.')
            ->assertSet('previewStats.total_recipients', 2)
            ->assertSee('Owes May A')
            ->assertSee('Owes May B')
            ->assertSee('mei');
    }
}
