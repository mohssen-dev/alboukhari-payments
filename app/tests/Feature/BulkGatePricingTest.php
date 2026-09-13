<?php

namespace Tests\Feature;

use App\Livewire\SendCampaign;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\BulkGatePricing;
use App\Support\MoneyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign cost before sending: BulkGate's price list (a real copy of the
 * Netherlands list is the fixture), the account balance, the credit value of
 * the chosen top-up tier and the EUR→USD rate — and what the preview shows
 * when any of them cannot be reached.
 */
class BulkGatePricingTest extends TestCase
{
    use RefreshDatabase;

    private array $requests = [];
    private array $state = [];
    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('bulkgate_app_id', '35679');
        Setting::put('bulkgate_app_token', 'test-token', true);
        Setting::put('bulkgate_sender_id', 'gText');
        Setting::put('bulkgate_default_country', 'NL');
    }

    /** Every service up: portal page + price list, balance, exchange rate. */
    private function fakeServices(float $credit = 764.9, bool $pricesUp = true, bool $fxUp = true, bool $balanceUp = true): void
    {
        // Http::fake stubs stack (the first match wins), so register once and
        // let later calls only change what the services answer.
        $this->state = compact('credit', 'pricesUp', 'fxUp', 'balanceUp');
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/bulkgate-price-list-nl.json')), true);

        Http::fake(function (Request $request) use ($fixture) {
            ['credit' => $credit, 'pricesUp' => $pricesUp, 'fxUp' => $fxUp, 'balanceUp' => $balanceUp] = $this->state;
            $this->requests[] = $request->method() . ' ' . $request->url();
            $url = $request->url();

            if (str_contains($url, 'sms-price/list?do=sms_price_list-loadPriceList')) {
                return $pricesUp
                    ? Http::response(['data' => ['smsPriceList' => ['server' => $fixture]]])
                    : Http::response('down', 503);
            }
            if (str_contains($url, 'sms-price/list')) {
                return $pricesUp ? Http::response('<html></html>', 200, ['Set-Cookie' => 'PHPSESSID=abc; path=/']) : Http::response('down', 503);
            }
            if (str_contains($url, 'advanced/info')) {
                return $balanceUp ? Http::response(['data' => ['credit' => $credit, 'currency' => 'credits']]) : Http::response('down', 500);
            }
            if (str_contains($url, 'frankfurter')) {
                return $fxUp ? Http::response(['amount' => 1, 'base' => 'EUR', 'date' => '2026-09-11', 'rates' => ['USD' => 1.1592]]) : Http::response('down', 500);
            }

            return Http::response('unexpected', 500);
        });
    }

    private function pricing(): BulkGatePricing
    {
        return app(BulkGatePricing::class);
    }

    public function test_the_real_price_list_is_read_per_sender_and_operator(): void
    {
        $server = json_decode(file_get_contents(base_path('tests/Fixtures/bulkgate-price-list-nl.json')), true);
        $list = BulkGatePricing::parsePriceList($server, 'nl');

        $gText = collect($list['senders']['gText']['operators'])->keyBy('name');
        $this->assertCount(8, $gText);
        $this->assertSame(1.4, $gText['KPN']['credits']);
        $this->assertSame(1.4, $gText['Vodafone']['credits']);
        $this->assertSame(1.7, $gText['Odido']['credits']);
        $this->assertSame(2.0, $gText['CleverEnable']['credits']);   // " MVNO" dropped from the name
        $this->assertSame(25.0, $list['tiers'][0]['credits_per_eur']);
        $this->assertSame(1000, end($list['tiers'])['amount']);
    }

    public function test_the_price_list_is_fetched_with_a_session_then_cached_and_saved(): void
    {
        $this->fakeServices();

        $this->assertNotNull($this->pricing()->priceList());
        $this->assertSame(['GET https://portal.bulkgate.com/sms-price/list', 'POST https://portal.bulkgate.com/sms-price/list?do=sms_price_list-loadPriceList'], $this->requests);

        $this->pricing()->priceList();
        $this->assertCount(2, $this->requests, 'the second read comes from the cache');
        $this->assertNotEmpty(Setting::get('bulkgate_price_snapshot_nl'));
    }

    public function test_a_campaign_is_quoted_in_credits_euros_and_dollars(): void
    {
        $this->fakeServices(credit: 764.9);

        $q = $this->pricing()->quote(300);

        $this->assertTrue($q['available']);
        $this->assertSame('gText', $q['sender']);
        $this->assertEqualsWithDelta(420.0, $q['min']['credits'], 0.001);   // all on KPN / Vodafone (1.4)
        $this->assertEqualsWithDelta(600.0, $q['max']['credits'], 0.001);   // all on CleverEnable (2.0)
        $this->assertEqualsWithDelta(24.0, $q['max']['eur'], 0.001);        // 25 credits per euro
        $this->assertEqualsWithDelta(24.0 * 1.1592, $q['max']['usd'], 0.001);
        $this->assertEqualsWithDelta(30.596, $q['balance']['eur'], 0.001);
        $this->assertEqualsWithDelta(164.9, $q['left']['credits'], 0.001);
        $this->assertSame('enough', $q['status']);
        $this->assertSame('KPN', $q['operators'][0]['name']);
        $this->assertSame('CleverEnable', end($q['operators'])['name']);
    }

    public function test_the_balance_verdict(): void
    {
        $this->fakeServices(credit: 500);
        $this->assertSame('tight', $this->pricing()->quote(300)['status']);   // 420 ≤ 500 < 600

        $this->pricing()->refresh();
        $this->fakeServices(credit: 100);
        $q = $this->pricing()->quote(300);
        $this->assertSame('short', $q['status']);
        $this->assertEqualsWithDelta(320.0, $q['short_min']['credits'], 0.001);
    }

    public function test_the_top_up_tier_sets_what_a_credit_is_worth(): void
    {
        $this->fakeServices();
        Setting::put(BulkGatePricing::TIER_SETTING, '1000');

        $q = $this->pricing()->quote(1);

        $this->assertEqualsWithDelta(30.265, $q['credit']['credits_per_eur'], 0.001);
        $this->assertEqualsWithDelta(1.4 / 30.265, $q['per_part']['min']['eur'], 0.00001);   // ≈ 0.0463 € — BulkGate's advertised price
    }

    public function test_an_unreachable_price_list_falls_back_to_the_last_saved_one(): void
    {
        $this->fakeServices();
        $this->pricing()->priceList();              // saves a snapshot
        $this->pricing()->refresh();

        $this->fakeServices(pricesUp: false);
        $q = $this->pricing()->quote(10);

        $this->assertTrue($q['available']);
        $this->assertTrue($q['stale']);
        $this->assertEqualsWithDelta(14.0, $q['min']['credits'], 0.001);
    }

    public function test_nothing_reachable_still_gives_a_quote_the_page_can_render(): void
    {
        Http::fake(fn () => Http::response('down', 503));

        $q = $this->pricing()->quote(10);

        $this->assertFalse($q['available']);
        $this->assertNull($q['balance']['credits']);
    }

    public function test_the_send_preview_shows_the_cost_panel_and_saves_the_tier(): void
    {
        $this->fakeServices(credit: 764.9);
        $this->actingAs(User::create(['name' => 'S', 'email' => 's@cost.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_STAFF, 'is_active' => true]));
        Student::create(['name' => 'Cost Kid', 'phone_primary_e164' => '+31612345678', 'default_fee_amount' => 30, 'enrolled_at' => now()->startOfYear()]);

        $page = Livewire::test(SendCampaign::class)
            ->set('type', 'send_all')
            ->set('body', 'Beste ouder, dit is een test.')
            ->call('refreshPreview')
            ->assertSee(__('cost.title'))
            ->assertSee('764.9')
            ->assertSee('KPN')
            ->assertSee('1.4')
            ->assertSee('1 € = 1.1592 $', false);

        $page->call('setCreditTier', 100);
        $this->assertSame('100', Setting::get(BulkGatePricing::TIER_SETTING));

        $page->call('setCreditTier', 12345);   // not a BulkGate tier — ignored
        $this->assertSame('100', Setting::get(BulkGatePricing::TIER_SETTING));
    }

    public function test_money_format(): void
    {
        $this->assertSame('1.4', MoneyFormat::credits(1.4));
        $this->assertSame('764.9', MoneyFormat::credits(764.9));
        $this->assertSame('1.45', MoneyFormat::credits(1.45));
        $this->assertSame('0.056 €', MoneyFormat::eur(0.056));
        $this->assertSame('24.00 €', MoneyFormat::eur(24));
        $this->assertSame('—', MoneyFormat::usd(null));
    }
}
