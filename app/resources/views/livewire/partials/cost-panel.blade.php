{{--
    Campaign cost before sending (App\Services\BulkGatePricing::quote):
    cheapest → dearest operator in credits, euros and dollars, next to the
    account's credit. $quote is null-safe: an unreachable BulkGate still
    renders a readable panel.
--}}
@use('App\Support\MoneyFormat', 'M')
@php
    $status = $quote['available'] ? $quote['status'] : 'unknown';
@endphp
<section class="cost-panel cost-panel--{{ $status }}" aria-labelledby="cost-panel-title">
    <header class="cost-panel__head">
        <h4 id="cost-panel-title">💳 {{ __('cost.title') }}</h4>
        @if ($quote['available'])
            <span class="cost-panel__source">{{ __('cost.source', ['sender' => $quote['sender'], 'country' => $quote['country']]) }}</span>
        @endif
        <button type="button" class="btn btn-sm btn-ghost cost-panel__refresh" wire:click="refreshPricing" wire:loading.attr="disabled" wire:target="refreshPricing">
            <span wire:loading.remove wire:target="refreshPricing">🔄</span><span wire:loading wire:target="refreshPricing" class="spinner-sm"></span>
            {{ __('cost.refresh') }}
        </button>
    </header>

    @if ($quote['available'])
        <div class="cost-figures">
            <div class="cost-figure">
                <span class="cost-figure__label">{{ __('cost.min') }}</span>
                <span class="cost-figure__credits"><bdi dir="ltr">{{ M::credits($quote['min']['credits']) }}</bdi> <small>{{ __('cost.credits') }}</small></span>
                <span class="cost-figure__money"><bdi dir="ltr">{{ M::eur($quote['min']['eur']) }}</bdi> · <bdi dir="ltr">{{ M::usd($quote['min']['usd']) }}</bdi></span>
                <span class="cost-figure__hint">{{ __('cost.per_part_hint', ['credits' => M::credits($quote['per_part']['min']['credits'])]) }}</span>
            </div>
            <div class="cost-figure">
                <span class="cost-figure__label">{{ __('cost.max') }}</span>
                <span class="cost-figure__credits"><bdi dir="ltr">{{ M::credits($quote['max']['credits']) }}</bdi> <small>{{ __('cost.credits') }}</small></span>
                <span class="cost-figure__money"><bdi dir="ltr">{{ M::eur($quote['max']['eur']) }}</bdi> · <bdi dir="ltr">{{ M::usd($quote['max']['usd']) }}</bdi></span>
                <span class="cost-figure__hint">{{ __('cost.per_part_hint', ['credits' => M::credits($quote['per_part']['max']['credits'])]) }}</span>
            </div>
            <div class="cost-figure cost-figure--balance">
                <span class="cost-figure__label">{{ __('cost.balance') }}</span>
                @if ($quote['balance'])
                    <span class="cost-figure__credits"><bdi dir="ltr">{{ M::credits($quote['balance']['credits']) }}</bdi> <small>{{ __('cost.credits') }}</small></span>
                    <span class="cost-figure__money"><bdi dir="ltr">{{ M::eur($quote['balance']['eur']) }}</bdi> · <bdi dir="ltr">{{ M::usd($quote['balance']['usd']) }}</bdi></span>
                    <span class="cost-figure__hint">{{ __('cost.checked_at', ['time' => $quote['balance']['checked_at']]) }}</span>
                @else
                    <span class="cost-figure__credits">—</span>
                    <span class="cost-figure__hint">{{ __('cost.balance_unavailable') }}</span>
                @endif
            </div>
        </div>

        <p class="cost-verdict" role="status">
            @switch ($status)
                @case ('enough')
                    ✅ {{ __('cost.enough', ['left' => M::iso(M::credits($quote['left']['credits'])), 'eur' => M::iso(M::eur($quote['left']['eur'])), 'usd' => M::iso(M::usd($quote['left']['usd']))]) }}
                    @break
                @case ('tight')
                    ⚠️ {{ __('cost.tight', ['short' => M::iso(M::credits($quote['short']['credits'])), 'eur' => M::iso(M::eur($quote['short']['eur']))]) }}
                    @break
                @case ('short')
                    ⛔ {{ __('cost.short', ['short' => M::iso(M::credits($quote['short_min']['credits'])), 'eur' => M::iso(M::eur($quote['short_min']['eur'])), 'usd' => M::iso(M::usd($quote['short_min']['usd']))]) }}
                    @break
                @default
                    ℹ️ {{ __('cost.balance_unavailable') }}
            @endswitch
        </p>

        <details class="cost-operators">
            <summary>📶 {{ __('cost.operators', ['count' => count($quote['operators'])]) }}</summary>
            <div class="cost-operators__scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('cost.operator') }}</th>
                            <th scope="col">{{ __('cost.per_sms') }}</th>
                            <th scope="col">€</th>
                            <th scope="col">$</th>
                            <th scope="col">{{ __('cost.this_campaign', ['count' => $quote['segments']]) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quote['operators'] as $op)
                            <tr>
                                <th scope="row">{{ $op['name'] }}</th>
                                <td><bdi dir="ltr">{{ M::credits($op['credits']) }}</bdi></td>
                                <td><bdi dir="ltr">{{ M::eur($op['eur']) }}</bdi></td>
                                <td><bdi dir="ltr">{{ M::usd($op['usd']) }}</bdi></td>
                                <td><bdi dir="ltr">{{ M::credits($op['credits'] * $quote['segments']) }}</bdi></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="cost-note">{{ __('cost.why_range') }}</p>
        </details>

        <footer class="cost-panel__foot">
            <label class="cost-tier">
                <span>{{ __('cost.tier_label') }}</span>
                <select class="form-select" wire:change="setCreditTier($event.target.value)">
                    @foreach ($quote['tiers'] as $tier)
                        <option value="{{ $tier['amount'] }}" @selected($tier['amount'] === $quote['tier'])>
                            {{ __('cost.tier_option', ['amount' => $tier['amount'], 'credits' => rtrim(rtrim(number_format($tier['credits_per_eur'], 3), '0'), '.'), 'bonus' => rtrim(rtrim(number_format($tier['bonus'], 2), '0'), '.')]) }}
                        </option>
                    @endforeach
                </select>
            </label>
            <span>{{ __('cost.credit_value', ['eur' => M::iso(M::eur($quote['credit']['eur'])), 'usd' => M::iso(M::usd($quote['credit']['usd']))]) }}</span>
            @if ($quote['fx'])
                <span>{{ __('cost.fx', ['rate' => number_format($quote['fx']['rate'], 4), 'date' => $quote['fx']['date']]) }}</span>
            @else
                <span>{{ __('cost.fx_unavailable') }}</span>
            @endif
            <span class="{{ $quote['stale'] ? 'cost-stale' : '' }}">
                {{ $quote['stale'] ? __('cost.stale', ['time' => $quote['fetched_at']]) : __('cost.updated', ['time' => $quote['fetched_at']]) }}
            </span>
        </footer>
    @else
        <p class="cost-verdict">ℹ️ {{ __('cost.unavailable') }}</p>
        @if ($quote['balance']['credits'] !== null)
            <p class="cost-note">{{ __('cost.balance') }}: <bdi dir="ltr">{{ M::credits($quote['balance']['credits']) }}</bdi> {{ __('cost.credits') }}</p>
        @endif
    @endif
</section>
