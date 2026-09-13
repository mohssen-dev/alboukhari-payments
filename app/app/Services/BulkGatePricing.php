<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What a campaign costs before it is sent, in BulkGate credits, euros and
 * dollars, next to the credit left in the account.
 *
 * - Prices: BulkGate's own public price list (the one its portal renders),
 *   per country, sender type (gText…) and mobile operator, in credits per
 *   SMS part. A parent's operator is not known in advance (numbers are
 *   portable), so a campaign is quoted from its cheapest to its dearest
 *   operator.
 * - Credits → euros: BulkGate sells credits in top-up tiers (25 credits per
 *   euro, more with a bonus). The tier the school buys at is a setting.
 * - Euros → dollars: the ECB reference rate (frankfurter.app).
 * - Balance: the account's credit (BulkGate info API).
 *
 * Everything is cached and the last good price list / rate is kept in the
 * settings table, so a slow or unreachable service never breaks the page —
 * the preview just says the figures are from an older check.
 */
class BulkGatePricing
{
    private const LIST_PAGE = 'https://portal.bulkgate.com/sms-price/list';
    private const LIST_LOAD = 'https://portal.bulkgate.com/sms-price/list?do=sms_price_list-loadPriceList';
    private const FX_URL = 'https://api.frankfurter.app/latest';

    public const TIER_SETTING = 'bulkgate_credit_tier';
    private const PRICES_TTL_HOURS = 12;
    private const FX_TTL_HOURS = 12;
    private const BALANCE_TTL_MINUTES = 5;
    private const RETRY_MINUTES = 30;

    /** Credits per euro when BulkGate's own list cannot be read (its 0 % bonus tier). */
    private const DEFAULT_TIERS = [
        ['amount' => 0, 'bonus' => 0.0, 'credits_per_eur' => 25.0],
    ];

    /**
     * The price list for one country: senders keyed by type (gText…), each
     * with its operators and credits per SMS part, plus the euro top-up tiers.
     */
    public function priceList(?string $iso = null): ?array
    {
        $iso = strtolower($iso ?: (string) Setting::get('bulkgate_default_country', 'NL'));
        $key = "bulkgate:prices:{$iso}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        if (!Cache::has("{$key}:retry")) {
            try {
                $list = $this->fetchPriceList($iso);
                Cache::put($key, $list, now()->addHours(self::PRICES_TTL_HOURS));
                Setting::put("bulkgate_price_snapshot_{$iso}", json_encode($list));

                return $list;
            } catch (\Throwable $e) {
                Log::warning('BulkGate price list unavailable: ' . $e->getMessage());
                Cache::put("{$key}:retry", true, now()->addMinutes(self::RETRY_MINUTES));
            }
        }

        $snapshot = json_decode((string) Setting::get("bulkgate_price_snapshot_{$iso}"), true);

        return is_array($snapshot) ? ['stale' => true] + $snapshot : null;
    }

    /** ['rate' => USD per EUR, 'date' => ECB date] or null if never known. */
    public function usdPerEur(): ?array
    {
        if ($cached = Cache::get('fx:eur-usd')) {
            return $cached;
        }

        if (!Cache::has('fx:eur-usd:retry')) {
            try {
                $response = Http::timeout(6)->acceptJson()->get(self::FX_URL, ['from' => 'EUR', 'to' => 'USD']);
                $rate = (float) $response->json('rates.USD');
                if (!$response->successful() || $rate <= 0) {
                    throw new \RuntimeException('HTTP ' . $response->status());
                }
                $fx = ['rate' => $rate, 'date' => (string) $response->json('date')];
                Cache::put('fx:eur-usd', $fx, now()->addHours(self::FX_TTL_HOURS));
                Setting::put('fx_eur_usd_snapshot', json_encode($fx));

                return $fx;
            } catch (\Throwable $e) {
                Log::warning('EUR→USD rate unavailable: ' . $e->getMessage());
                Cache::put('fx:eur-usd:retry', true, now()->addMinutes(self::RETRY_MINUTES));
            }
        }

        $snapshot = json_decode((string) Setting::get('fx_eur_usd_snapshot'), true);

        return is_array($snapshot) && ($snapshot['rate'] ?? 0) > 0 ? ['stale' => true] + $snapshot : null;
    }

    /** The account's credit — same cache entry as the settings page. */
    public function balance(): ?array
    {
        if ($cached = Cache::get('bulkgate:credit')) {
            return $cached;
        }
        if (Cache::has('bulkgate:credit:retry')) {
            return null;
        }

        try {
            $info = app(BulkGateClient::class)->info();
            Cache::put('bulkgate:credit', $info, now()->addMinutes(self::BALANCE_TTL_MINUTES));

            return $info;
        } catch (\Throwable $e) {
            Cache::put('bulkgate:credit:retry', true, now()->addMinutes(2));

            return null;
        }
    }

    /** Euro top-up tiers, cheapest credit last. @return list<array{amount:int,bonus:float,credits_per_eur:float}> */
    public function tiers(?array $list = null): array
    {
        $list ??= $this->priceList();

        return $list['tiers'] ?? self::DEFAULT_TIERS;
    }

    public function creditsPerEur(?array $list = null): float
    {
        $tiers = $this->tiers($list);
        $chosen = (int) Setting::get(self::TIER_SETTING, '0');
        foreach ($tiers as $tier) {
            if ($tier['amount'] === $chosen) {
                return $tier['credits_per_eur'];
            }
        }

        return $tiers[0]['credits_per_eur'];
    }

    /** Forget every cached figure so the next quote asks the services again. */
    public function refresh(): void
    {
        $iso = strtolower((string) Setting::get('bulkgate_default_country', 'NL'));
        foreach (["bulkgate:prices:{$iso}", "bulkgate:prices:{$iso}:retry", 'fx:eur-usd', 'fx:eur-usd:retry', 'bulkgate:credit', 'bulkgate:credit:retry'] as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Everything the preview shows for $segments SMS parts: the cost range
     * across operators, per-operator prices, the balance and what is left.
     */
    public function quote(int $segments): array
    {
        $list = $this->priceList();
        $senderType = (string) Setting::get('bulkgate_sender_id', 'gText');
        $sender = $list['senders'][$senderType] ?? ($list ? reset($list['senders']) : null);

        if (!$sender || empty($sender['operators'])) {
            return ['available' => false, 'segments' => $segments, 'balance' => $this->money($this->balance()['credit'] ?? null, null, null)];
        }

        $perEur = $this->creditsPerEur($list);
        $fx = $this->usdPerEur();
        $usd = $fx['rate'] ?? null;

        $prices = array_column($sender['operators'], 'credits');
        $min = min($prices);
        $max = max($prices);

        $operators = array_map(fn ($o) => ['name' => $o['name'], 'code' => $o['code']] + $this->money($o['credits'], $perEur, $usd), $sender['operators']);
        usort($operators, fn ($a, $b) => [$a['credits'], $a['name']] <=> [$b['credits'], $b['name']]);

        $balance = $this->balance();
        $credit = $balance['credit'] ?? null;
        $costMax = $max * $segments;
        $costMin = $min * $segments;

        return [
            'available' => true,
            'stale' => !empty($list['stale']),
            'fetched_at' => $list['fetched_at'] ?? null,
            'country' => strtoupper($list['iso']),
            'sender' => $sender['type'],
            'segments' => $segments,
            'per_part' => ['min' => $this->money($min, $perEur, $usd), 'max' => $this->money($max, $perEur, $usd)],
            'min' => $this->money($costMin, $perEur, $usd),
            'max' => $this->money($costMax, $perEur, $usd),
            'operators' => $operators,
            'balance' => $credit !== null ? $this->money($credit, $perEur, $usd) + ['checked_at' => $balance['checked_at'] ?? null] : null,
            'left' => $credit !== null ? $this->money($credit - $costMax, $perEur, $usd) : null,
            // Missing credit at the dearest / cheapest operator.
            'short' => $credit !== null && $credit < $costMax ? $this->money($costMax - $credit, $perEur, $usd) : null,
            'short_min' => $credit !== null && $credit < $costMin ? $this->money($costMin - $credit, $perEur, $usd) : null,
            'status' => match (true) {
                $credit === null => 'unknown',
                $credit >= $costMax => 'enough',
                $credit >= $costMin => 'tight',
                default => 'short',
            },
            'credit' => $this->money(1, $perEur, $usd) + ['credits_per_eur' => $perEur],
            'tier' => (int) Setting::get(self::TIER_SETTING, '0'),
            'tiers' => $this->tiers($list),
            'fx' => $fx,
        ];
    }

    /** @return array{credits: ?float, eur: ?float, usd: ?float} */
    private function money(?float $credits, ?float $perEur, ?float $usdPerEur): array
    {
        $eur = $credits !== null && $perEur ? $credits / $perEur : null;

        return [
            'credits' => $credits,
            'eur' => $eur,
            'usd' => $eur !== null && $usdPerEur ? $eur * $usdPerEur : null,
        ];
    }

    /** BulkGate's portal loads its price list with a session cookie — do the same. */
    private function fetchPriceList(string $iso): array
    {
        $jar = new CookieJar();
        $http = fn () => Http::withOptions(['cookies' => $jar])
            ->withUserAgent('AlBoukhariPayments/1.0 (+https://payments.alboukhari.nl)')
            ->timeout(8);

        $http()->get(self::LIST_PAGE)->throw();

        $server = $http()
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => self::LIST_PAGE])
            ->asJson()
            ->post(self::LIST_LOAD, ['iso' => $iso, 'channel' => 'sms'])
            ->throw()
            ->json('data.smsPriceList.server');

        return self::parsePriceList($server, $iso);
    }

    /** Keep only what a quote needs from the portal's payload. */
    public static function parsePriceList(mixed $server, string $iso): array
    {
        if (!is_array($server) || empty($server['senders'])) {
            throw new \RuntimeException('unexpected price list format');
        }

        $senders = [];
        foreach ($server['senders'] as $s) {
            $operators = [];
            foreach ($s['operators'] ?? [] as $o) {
                if (isset($o['operator'], $o['price']) && is_numeric($o['price']) && $o['price'] > 0) {
                    $operators[] = [
                        'name' => preg_replace('/\s+MVNO$/i', '', (string) $o['operator']),
                        'code' => (string) ($o['operator_code'] ?? ''),
                        'credits' => (float) $o['price'],
                    ];
                }
            }
            if (!empty($s['type']) && $operators) {
                $senders[$s['type']] = ['type' => $s['type'], 'unicode' => (bool) ($s['unicode'] ?? true), 'operators' => $operators];
            }
        }

        $tiers = [];
        foreach ($server['levels']['EUR'] ?? [] as $level) {
            if (isset($level['amount'], $level['coefficient']) && $level['coefficient'] > 0) {
                $tiers[] = ['amount' => (int) $level['amount'], 'bonus' => (float) ($level['bonus'] ?? 0), 'credits_per_eur' => (float) $level['coefficient']];
            }
        }

        if (!$senders) {
            throw new \RuntimeException('price list has no SMS prices');
        }

        return [
            'iso' => $iso,
            'senders' => $senders,
            'tiers' => $tiers ?: self::DEFAULT_TIERS,
            'fetched_at' => now()->format('Y-m-d H:i'),
        ];
    }
}
