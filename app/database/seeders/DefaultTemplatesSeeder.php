<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * The shipped templates: Dutch text (sent) with an Arabic translation shown
 * to staff only, English placeholders (see App\Support\TemplateVariables).
 */
class DefaultTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'nl_first_friday',
                'name' => '🟢 تذكير أول جمعة',
                'body' => 'Beste ouder van {{student_name}}, Al Boukhari School herinnert u aan de betaling voor {{month_nl}}. Graag zo snel mogelijk voldoen. Hartelijk dank.',
                'body_ar' => 'ولي أمر {{student_name}} الكريم، تذكّركم مدرسة البخاري بسداد رسوم شهر {{month_ar}} في أقرب وقت. شكراً لكم.',
                'default_for' => 'first_friday',
            ],
            [
                'code' => 'nl_mid_month',
                'name' => '🔴 تنبيه التأخير (15 من الشهر)',
                'body' => 'Beste familie van {{student_name}}, wij hebben de betaling voor {{month_nl}} nog niet ontvangen. Openstaand: €{{balance}}. Graag zo spoedig mogelijk voldoen.',
                'body_ar' => 'عائلة {{student_name}} الكريمة، لم تصلنا بعد رسوم شهر {{month_ar}}. المبلغ المتبقي: {{balance}}€. نرجو السداد في أقرب وقت.',
                'default_for' => 'mid_month',
            ],
            [
                'code' => 'nl_send_all',
                'name' => '📢 إرسال جماعي عام',
                'body' => 'Beste familie van {{student_name}}, dit is een bericht van Al Boukhari School. Hartelijk dank.',
                'body_ar' => 'عائلة {{student_name}} الكريمة، هذه رسالة من مدرسة البخاري. شكراً لكم.',
                'default_for' => 'none',
            ],
            [
                'code' => 'nl_family',
                'name' => '👨‍👩‍👧 رسالة عائلية (للإخوة)',
                // family_balance (still OUTSTANDING), not family_total (the
                // gross monthly fee): a dunning message must quote what is
                // still owed, or a parent who paid part of it is billed again.
                'body' => 'Beste ouder, herinnering voor {{unpaid_names}}: nog te voldoen €{{family_balance}} voor {{month_nl}}. Hartelijk dank, Al Boukhari School',
                'body_ar' => 'ولي الأمر الكريم، تذكير بخصوص {{unpaid_names}}: المبلغ المتبقي {{family_balance}}€ عن شهر {{month_ar}}. شكراً لكم، مدرسة البخاري',
                'default_for' => 'none',
            ],
            [
                // No month on purpose: a general reminder of everything owed,
                // for "balance above" campaigns sent once per family.
                'code' => 'nl_family_arrears',
                'name' => '🧾 تذكير المتأخرات للعائلة',
                'body' => 'Assalamu alaikum, geachte familie. Er staan nog achterstallige betalingen open voor uw kinderen bij Al Boukhari School. Wilt u deze spoedig voldoen? Dank u wel.',
                'body_ar' => 'السلام عليكم، العائلة الكريمة. توجد مبالغ متأخرة مستحقة لأبنائكم في مدرسة البخاري، نرجو التكرّم بسدادها في أقرب وقت. شكراً لكم.',
                'default_for' => 'none',
            ],
            [
                'code' => 'ar_reminder',
                'name' => '📅 تذكير دفع شهري',
                'body' => 'Assalamu alaikum, herinnering voor de betaling van {{month_nl}} voor {{student_name}}. Hartelijk dank, Al Boukhari School',
                'body_ar' => 'السلام عليكم، تذكير بدفع رسوم شهر {{month_ar}} لـ {{student_name}}. شكراً لكم، مدرسة البخاري',
                'default_for' => 'none',
            ],
        ];

        foreach ($templates as $tpl) {
            Template::updateOrCreate(['code' => $tpl['code']], $tpl + [
                'language' => 'nl',
                'origin' => Template::ORIGIN_LIBRARY,
            ]);
        }
    }
}
