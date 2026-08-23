<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class DefaultTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'nl_first_friday',
                'name' => '🟢 NL — تذكير أول جمعة',
                'language' => 'nl',
                'body' => 'Beste ouder van {{Naam}}, Al Boukhari School groet u en herinnert u eraan om de betaling van {{month}} zo snel mogelijk te voldoen.',
                'default_for' => 'first_friday',
            ],
            [
                'code' => 'nl_mid_month',
                'name' => '🔴 NL — إنذار 15 من الشهر',
                'language' => 'nl',
                'body' => 'Beste familie van student {{Naam}}, betaling voor {{month}} is vertraagd. Graag zo spoedig mogelijk voldoen.',
                'default_for' => 'mid_month',
            ],
            [
                'code' => 'nl_send_all',
                'name' => 'NL — إرسال جماعي عام',
                'language' => 'nl',
                'body' => 'Beste familie van {{Naam}}, dit is een herinnering van Al Boukhari School. Bedankt.',
                'default_for' => 'none',
            ],
            [
                'code' => 'nl_family',
                'name' => 'NL — رسالة عائلية (للإخوة)',
                'language' => 'nl',
                // Uses المتبقي_العائلي (still OUTSTANDING) rather than
                // المبلغ_العائلي (the family's GROSS monthly fee): a dunning
                // message must quote what is still owed, otherwise a parent who
                // has already paid part or all of the month is billed again.
                'body' => 'Beste ouder, herinnering voor {{أسماء_غير_المدفوعين}}. Nog te voldoen: {{المتبقي_العائلي}}€ voor {{month}}. Bedankt.',
                'default_for' => 'none',
            ],
            [
                'code' => 'ar_reminder',
                'name' => 'AR — تذكير دفع شهري',
                'language' => 'ar',
                'body' => 'السلام عليكم، تذكير بدفع رسوم {{الشهر}} لـ {{اسم}}. شكراً لكم.',
                'default_for' => 'none',
            ],
        ];

        foreach ($templates as $tpl) {
            Template::updateOrCreate(['code' => $tpl['code']], $tpl);
        }
    }
}
