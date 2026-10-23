<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Template;

/**
 * What the payment and send windows start with — set in Settings → Defaults.
 *
 * The payment method used to start as "cash" everywhere, so a bank payment
 * entered in a hurry was recorded as cash. With no default method the
 * windows start with nothing picked and refuse to save until one is.
 */
final class Defaults
{
    public const PAYMENT_METHOD = 'default_payment_method';   // '' (ask every time) | cash | bank
    public const PAYMENT_DATE = 'default_payment_date';       // today | empty
    public const PAYMENT_AMOUNT = 'default_payment_amount';   // remaining | empty
    public const FAMILY_OPENS_ON = 'default_family_month';    // oldest_unpaid | current
    public const SEND_GROUP_BY_FAMILY = 'default_send_group_by_family'; // 0 | 1
    public const SEND_TEMPLATE = 'default_send_template_id';  // '' (the first template) | template id

    public const KEYS = [
        self::PAYMENT_METHOD, self::PAYMENT_DATE, self::PAYMENT_AMOUNT,
        self::FAMILY_OPENS_ON, self::SEND_GROUP_BY_FAMILY, self::SEND_TEMPLATE,
    ];

    /** The values the app shipped with, so nothing changes until someone picks otherwise — except the method, which now asks. */
    public const FACTORY = [
        self::PAYMENT_METHOD => '',
        self::PAYMENT_DATE => 'today',
        self::PAYMENT_AMOUNT => 'remaining',
        self::FAMILY_OPENS_ON => 'oldest_unpaid',
        self::SEND_GROUP_BY_FAMILY => '0',
        self::SEND_TEMPLATE => '',
    ];

    /** '' when the method must be picked for every payment. */
    public static function paymentMethod(): string
    {
        $v = (string) Setting::get(self::PAYMENT_METHOD, self::FACTORY[self::PAYMENT_METHOD]);

        return in_array($v, ['cash', 'bank'], true) ? $v : '';
    }

    /** Today's date (Y-m-d), or '' when the date must be typed for every payment. */
    public static function paymentDate(): string
    {
        return Setting::get(self::PAYMENT_DATE, self::FACTORY[self::PAYMENT_DATE]) === 'empty' ? '' : now()->format('Y-m-d');
    }

    /** Whether the windows suggest the amount still owed (otherwise the amount is typed). */
    public static function suggestAmount(): bool
    {
        return Setting::get(self::PAYMENT_AMOUNT, self::FACTORY[self::PAYMENT_AMOUNT]) !== 'empty';
    }

    /** Whether the family window opens on the oldest unpaid month (otherwise on the current month). */
    public static function familyOpensOnOldestUnpaid(): bool
    {
        return Setting::get(self::FAMILY_OPENS_ON, self::FACTORY[self::FAMILY_OPENS_ON]) !== 'current';
    }

    public static function sendGroupByFamily(): bool
    {
        return Setting::get(self::SEND_GROUP_BY_FAMILY, self::FACTORY[self::SEND_GROUP_BY_FAMILY]) === '1';
    }

    /** The template the send page opens with: the chosen one if it still exists, else the first one. */
    public static function sendTemplate(): ?Template
    {
        $id = (int) Setting::get(self::SEND_TEMPLATE, '');

        return ($id ? Template::find($id) : null) ?? Template::library()->orderBy('id')->first();
    }

    /** The current values, for the settings form. */
    public static function all(): array
    {
        $out = [];
        foreach (self::FACTORY as $key => $factory) {
            $out[$key] = (string) Setting::get($key, $factory);
        }

        return $out;
    }
}
