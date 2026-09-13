<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Templates get a Dutch text (sent) with an Arabic translation shown to
     * staff only, and English placeholder names.
     *
     * - body_ar: the translation — displayed in the app so staff understand
     *   what parents receive; it is never part of the SMS.
     * - origin: 'library' templates vs 'manual' messages kept automatically
     *   from the send page.
     * - Every template's placeholders are renamed ({{المبلغ_العائلي}} →
     *   {{family_total}}); the renderer still accepts the old names.
     * - The five shipped templates get their new text + translation — but
     *   only while they still hold a text they shipped with, so a template
     *   the school edited is never overwritten (it only gets the rename).
     * - A family arrears reminder (no month) is added when missing.
     *
     * No other table is touched: payments, students and families are left
     * exactly as they are.
     *
     * The maps are frozen copies on purpose: a migration must keep doing what
     * it did the day it ran (live lists: App\Support\TemplateVariables,
     * Database\Seeders\DefaultTemplatesSeeder).
     */
    private const ALIASES = [
        'Naam' => 'student_name', 'name' => 'student_name', 'اسم' => 'student_name',
        'month' => 'month_en', 'الشهر' => 'month_ar', 'السنة' => 'year',
        'المستحق' => 'due', 'المدفوع' => 'paid', 'المتبقي' => 'balance',
        'أسماء_الأبناء' => 'children_names', 'أسماء_غير_المدفوعين' => 'unpaid_names',
        'عدد_الأبناء' => 'children_count', 'المبلغ_العائلي' => 'family_total',
        'المتبقي_العائلي' => 'family_balance', 'تفاصيل_الأبناء' => 'children_details',
    ];

    private const SHIPPED = [
        'nl_first_friday' => ['Beste ouder van {{Naam}}, Al Boukhari School groet u en herinnert u eraan om de betaling van {{month}} zo snel mogelijk te voldoen.'],
        'nl_mid_month' => ['Beste familie van student {{Naam}}, betaling voor {{month}} is vertraagd. Graag zo spoedig mogelijk voldoen.'],
        'nl_send_all' => ['Beste familie van {{Naam}}, dit is een herinnering van Al Boukhari School. Bedankt.'],
        'nl_family' => [
            'Beste ouder, herinnering voor {{أسماء_غير_المدفوعين}}. Totaal {{المبلغ_العائلي}}€ voor {{month}}. Bedankt.',
            'Beste ouder, herinnering voor {{أسماء_غير_المدفوعين}}. Nog te voldoen: {{المتبقي_العائلي}}€ voor {{month}}. Bedankt.',
        ],
        'ar_reminder' => ['السلام عليكم، تذكير بدفع رسوم {{الشهر}} لـ {{اسم}}. شكراً لكم.'],
    ];

    private const UPDATED = [
        'nl_first_friday' => [
            'name' => '🟢 تذكير أول جمعة',
            'body' => 'Beste ouder van {{student_name}}, Al Boukhari School herinnert u aan de betaling voor {{month_nl}}. Graag zo snel mogelijk voldoen. Hartelijk dank.',
            'body_ar' => 'ولي أمر {{student_name}} الكريم، تذكّركم مدرسة البخاري بسداد رسوم شهر {{month_ar}} في أقرب وقت. شكراً لكم.',
        ],
        'nl_mid_month' => [
            'name' => '🔴 تنبيه التأخير (15 من الشهر)',
            'body' => 'Beste familie van {{student_name}}, wij hebben de betaling voor {{month_nl}} nog niet ontvangen. Openstaand: €{{balance}}. Graag zo spoedig mogelijk voldoen.',
            'body_ar' => 'عائلة {{student_name}} الكريمة، لم تصلنا بعد رسوم شهر {{month_ar}}. المبلغ المتبقي: {{balance}}€. نرجو السداد في أقرب وقت.',
        ],
        'nl_send_all' => [
            'name' => '📢 إرسال جماعي عام',
            'body' => 'Beste familie van {{student_name}}, dit is een bericht van Al Boukhari School. Hartelijk dank.',
            'body_ar' => 'عائلة {{student_name}} الكريمة، هذه رسالة من مدرسة البخاري. شكراً لكم.',
        ],
        'nl_family' => [
            'name' => '👨‍👩‍👧 رسالة عائلية (للإخوة)',
            'body' => 'Beste ouder, herinnering voor {{unpaid_names}}: nog te voldoen €{{family_balance}} voor {{month_nl}}. Hartelijk dank, Al Boukhari School',
            'body_ar' => 'ولي الأمر الكريم، تذكير بخصوص {{unpaid_names}}: المبلغ المتبقي {{family_balance}}€ عن شهر {{month_ar}}. شكراً لكم، مدرسة البخاري',
        ],
        'ar_reminder' => [
            'name' => '📅 تذكير دفع شهري',
            'body' => 'Assalamu alaikum, herinnering voor de betaling van {{month_nl}} voor {{student_name}}. Hartelijk dank, Al Boukhari School',
            'body_ar' => 'السلام عليكم، تذكير بدفع رسوم شهر {{month_ar}} لـ {{student_name}}. شكراً لكم، مدرسة البخاري',
        ],
    ];

    private const ARREARS = [
        'code' => 'nl_family_arrears',
        'name' => '🧾 تذكير المتأخرات للعائلة',
        'language' => 'nl',
        'body' => 'Assalamu alaikum, geachte familie. Er staan nog achterstallige betalingen open voor uw kinderen bij Al Boukhari School. Wilt u deze spoedig voldoen? Dank u wel.',
        'body_ar' => 'السلام عليكم، العائلة الكريمة. توجد مبالغ متأخرة مستحقة لأبنائكم في مدرسة البخاري، نرجو التكرّم بسدادها في أقرب وقت. شكراً لكم.',
        'default_for' => 'none',
        'origin' => 'library',
    ];

    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->text('body_ar')->nullable()->after('body');
            $table->string('origin', 20)->default('library')->after('body_ar')->index();
        });

        foreach (DB::table('templates')->get() as $tpl) {
            $body = trim((string) $tpl->body);

            if (isset(self::UPDATED[$tpl->code]) && in_array($body, self::SHIPPED[$tpl->code], true)) {
                DB::table('templates')->where('id', $tpl->id)->update(self::UPDATED[$tpl->code] + [
                    'language' => 'nl',
                    'updated_at' => now(),
                ]);
                continue;
            }

            $renamed = $this->rename((string) $tpl->body);
            if ($renamed !== $tpl->body) {
                DB::table('templates')->where('id', $tpl->id)->update(['body' => $renamed, 'updated_at' => now()]);
            }
        }

        // The code column is unique even for soft-deleted rows.
        if (!DB::table('templates')->where('code', self::ARREARS['code'])->exists()) {
            DB::table('templates')->insert(self::ARREARS + ['created_at' => now(), 'updated_at' => now()]);
        }

        // The legacy reminder texts kept in settings get the same rename.
        foreach (['template_first_friday_nl', 'template_mid_month_nl'] as $key) {
            $row = DB::table('settings')->where('key', $key)->first();
            if ($row && !$row->is_encrypted && is_string($row->value)) {
                $renamed = $this->rename($row->value);
                if ($renamed !== $row->value) {
                    DB::table('settings')->where('key', $key)->update(['value' => $renamed]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('templates')->where('code', self::ARREARS['code'])->delete();

        Schema::table('templates', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropColumn(['body_ar', 'origin']);
        });
    }

    private function rename(string $text): string
    {
        return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', function ($m) {
            $name = trim($m[1]);

            return isset(self::ALIASES[$name]) ? '{{' . self::ALIASES[$name] . '}}' : $m[0];
        }, $text);
    }
};
