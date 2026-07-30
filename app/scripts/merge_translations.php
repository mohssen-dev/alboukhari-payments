<?php
/**
 * One-shot merger: adds missing translation keys to lang/{en,nl,ar}.json
 * Preserves existing keys. Idempotent — safe to re-run.
 */

$root = dirname(__DIR__);

// The single source of truth for new translations.
// Format: 'key' => ['en' => ..., 'nl' => ..., 'ar' => ...]
$translations = [
    // ---------- Generic UI ----------
    'General' => ['en' => 'General', 'nl' => 'Algemeen', 'ar' => 'عام'],
    'Advanced' => ['en' => 'Advanced', 'nl' => 'Geavanceerd', 'ar' => 'متقدم'],
    'Reminders' => ['en' => 'Reminders', 'nl' => 'Herinneringen', 'ar' => 'التذكيرات'],
    'Auto Reminders' => ['en' => 'Auto Reminders', 'nl' => 'Auto-herinneringen', 'ar' => 'تذكيرات آلية'],
    'Automatic Reminders' => ['en' => 'Automatic Reminders', 'nl' => 'Automatische herinneringen', 'ar' => 'التذكيرات التلقائية'],
    'Currency' => ['en' => 'Currency', 'nl' => 'Valuta', 'ar' => 'العملة'],
    'Type' => ['en' => 'Type', 'nl' => 'Type', 'ar' => 'النوع'],
    'Date' => ['en' => 'Date', 'nl' => 'Datum', 'ar' => 'التاريخ'],
    'Cost' => ['en' => 'Cost', 'nl' => 'Kosten', 'ar' => 'التكلفة'],
    'Recipient' => ['en' => 'Recipient', 'nl' => 'Ontvanger', 'ar' => 'المستلم'],
    'Recipients' => ['en' => 'Recipients', 'nl' => 'Ontvangers', 'ar' => 'المستلمون'],
    'Send' => ['en' => 'Send', 'nl' => 'Verzenden', 'ar' => 'إرسال'],
    'Send now' => ['en' => 'Send now', 'nl' => 'Nu verzenden', 'ar' => 'أرسل الآن'],
    'Sent' => ['en' => 'Sent', 'nl' => 'Verzonden', 'ar' => 'أُرسلت'],
    'Failed' => ['en' => 'Failed', 'nl' => 'Mislukt', 'ar' => 'فشلت'],
    'Finished' => ['en' => 'Finished', 'nl' => 'Voltooid', 'ar' => 'انتهت'],
    'Started' => ['en' => 'Started', 'nl' => 'Gestart', 'ar' => 'بدأت'],
    'Open' => ['en' => 'Open', 'nl' => 'Open', 'ar' => 'مفتوحة'],
    'Skipped (top 20)' => ['en' => 'Skipped (top 20)', 'nl' => 'Overgeslagen (top 20)', 'ar' => 'تم تخطيها (أعلى 20)'],
    'Paid' => ['en' => 'Paid', 'nl' => 'Betaald', 'ar' => 'مدفوع'],
    'Partial' => ['en' => 'Partial', 'nl' => 'Gedeeltelijk', 'ar' => 'جزئي'],
    'All OFF' => ['en' => 'All OFF', 'nl' => 'Alles UIT', 'ar' => 'الكل مُطفأ'],
    'Both ON' => ['en' => 'Both ON', 'nl' => 'Beide AAN', 'ar' => 'كلاهما مُشغَّل'],
    'All states' => ['en' => 'All states', 'nl' => 'Alle statussen', 'ar' => 'كل الحالات'],
    'Fully paid' => ['en' => 'Fully paid', 'nl' => 'Volledig betaald', 'ar' => 'مدفوع كاملاً'],
    'Connected' => ['en' => 'Connected', 'nl' => 'Verbonden', 'ar' => 'متصل'],
    'Not configured' => ['en' => 'Not configured', 'nl' => 'Niet ingesteld', 'ar' => 'لم يُهيَّأ'],
    'Selected' => ['en' => 'Selected', 'nl' => 'Geselecteerd', 'ar' => 'محدد'],
    'selected' => ['en' => 'selected', 'nl' => 'geselecteerd', 'ar' => 'محدد'],
    'Clear' => ['en' => 'Clear', 'nl' => 'Wissen', 'ar' => 'مسح'],
    'Press' => ['en' => 'Press', 'nl' => 'Druk op', 'ar' => 'اضغط'],
    'Columns:' => ['en' => 'Columns:', 'nl' => 'Kolommen:', 'ar' => 'الأعمدة:'],
    'Student' => ['en' => 'Student', 'nl' => 'Student', 'ar' => 'طالب'],
    'Students' => ['en' => 'Students', 'nl' => 'Studenten', 'ar' => 'الطلاب'],
    'Year' => ['en' => 'Year', 'nl' => 'Jaar', 'ar' => 'السنة'],
    'Year (used to interpret month columns)' => ['en' => 'Year (used to interpret month columns)', 'nl' => 'Jaar (voor de kolommen)', 'ar' => 'السنة (لتفسير أعمدة الأشهر)'],
    'Period' => ['en' => 'Period', 'nl' => 'Periode', 'ar' => 'الفترة'],
    'Search student (name, phone, or ID)' => ['en' => 'Search student (name, phone, or ID)', 'nl' => 'Zoek student (naam, telefoon of ID)', 'ar' => 'ابحث عن طالب (الاسم أو الهاتف أو المعرّف)'],
    'Start typing...' => ['en' => 'Start typing...', 'nl' => 'Begin met typen...', 'ar' => 'ابدأ الكتابة...'],
    'Back to grid' => ['en' => 'Back to grid', 'nl' => 'Terug naar overzicht', 'ar' => 'العودة إلى الشبكة'],
    'Back to home' => ['en' => 'Back to home', 'nl' => 'Terug naar home', 'ar' => 'العودة إلى الرئيسية'],
    'Back to log' => ['en' => 'Back to log', 'nl' => 'Terug naar logboek', 'ar' => 'العودة إلى السجل'],
    'of' => ['en' => 'of', 'nl' => 'van', 'ar' => 'من'],
    'to' => ['en' => 'to', 'nl' => 'tot', 'ar' => 'إلى'],
    'Showing' => ['en' => 'Showing', 'nl' => 'Toont', 'ar' => 'يعرض'],
    'results' => ['en' => 'results', 'nl' => 'resultaten', 'ar' => 'نتائج'],
    'Pagination Navigation' => ['en' => 'Pagination Navigation', 'nl' => 'Paginanavigatie', 'ar' => 'التنقّل بين الصفحات'],
    'pagination.previous' => ['en' => '« Previous', 'nl' => '« Vorige', 'ar' => '« السابق'],
    'pagination.next' => ['en' => 'Next »', 'nl' => 'Volgende »', 'ar' => 'التالي »'],
    'Message to' => ['en' => 'Message to', 'nl' => 'Bericht aan', 'ar' => 'رسالة إلى'],
    'Original message' => ['en' => 'Original message', 'nl' => 'Origineel bericht', 'ar' => 'الرسالة الأصلية'],

    // ---------- Payment / Student Panel ----------
    'Add override' => ['en' => 'Add override', 'nl' => 'Overschrijving toevoegen', 'ar' => 'إضافة تجاوز'],
    'Add surcharge' => ['en' => 'Add surcharge', 'nl' => 'Toeslag toevoegen', 'ar' => 'إضافة رسم إضافي'],
    'Monthly fee overrides' => ['en' => 'Monthly fee overrides', 'nl' => 'Maandelijkse tarief-overschrijvingen', 'ar' => 'تجاوزات الرسم الشهري'],
    'Surcharges (extra fees)' => ['en' => 'Surcharges (extra fees)', 'nl' => 'Toeslagen (extra kosten)', 'ar' => 'الرسوم الإضافية'],
    'Temporary suspension' => ['en' => 'Temporary suspension', 'nl' => 'Tijdelijke opschorting', 'ar' => 'تعليق مؤقت'],
    'Control flags' => ['en' => 'Control flags', 'nl' => 'Beheervlaggen', 'ar' => 'علامات التحكم'],
    'Family:' => ['en' => 'Family:', 'nl' => 'Familie:', 'ar' => 'العائلة:'],
    'In-person' => ['en' => 'In-person', 'nl' => 'In persoon', 'ar' => 'يدفع شخصياً'],
    'From' => ['en' => 'From', 'nl' => 'Van', 'ar' => 'من'],
    'Primary phone' => ['en' => 'Primary phone', 'nl' => 'Hoofdtelefoon', 'ar' => 'الهاتف الأساسي'],
    'Secondary phone' => ['en' => 'Secondary phone', 'nl' => 'Tweede telefoon', 'ar' => 'الهاتف الثانوي'],
    'Notes' => ['en' => 'Notes', 'nl' => 'Notities', 'ar' => 'ملاحظات'],

    // ---------- Quick Entry ----------
    'Last payments in this session' => ['en' => 'Last payments in this session', 'nl' => 'Laatste betalingen in deze sessie', 'ar' => 'آخر الدفعات في هذه الجلسة'],
    'Saved this session' => ['en' => 'Saved this session', 'nl' => 'Opgeslagen in deze sessie', 'ar' => 'تم الحفظ في هذه الجلسة'],
    'Session total' => ['en' => 'Session total', 'nl' => 'Sessietotaal', 'ar' => 'إجمالي الجلسة'],

    // ---------- Campaigns ----------
    'New campaign' => ['en' => 'New campaign', 'nl' => 'Nieuwe campagne', 'ar' => 'حملة جديدة'],
    'No campaigns yet' => ['en' => 'No campaigns yet', 'nl' => 'Nog geen campagnes', 'ar' => 'لا توجد حملات بعد'],
    'Click Preview to see recipients before sending' => ['en' => 'Click Preview to see recipients before sending', 'nl' => 'Klik op Voorbeeld om ontvangers te zien vóór verzenden', 'ar' => 'انقر معاينة لرؤية المستلمين قبل الإرسال'],
    'Top 20 recipients' => ['en' => 'Top 20 recipients', 'nl' => 'Top 20 ontvangers', 'ar' => 'أعلى 20 مستلم'],
    'Last error' => ['en' => 'Last error', 'nl' => 'Laatste fout', 'ar' => 'آخر خطأ'],
    'Will send to anyone who paid less than this amount this month' => ['en' => 'Will send to anyone who paid less than this amount this month', 'nl' => 'Verzonden naar iedereen die deze maand minder heeft betaald dan dit bedrag', 'ar' => 'سيتم الإرسال لكل من دفع أقل من هذا المبلغ هذا الشهر'],
    'Will send to anyone whose accumulated balance exceeds this' => ['en' => 'Will send to anyone whose accumulated balance exceeds this', 'nl' => 'Verzonden naar iedereen wiens saldo dit overschrijdt', 'ar' => 'سيتم الإرسال لكل من يتجاوز رصيده المتراكم هذا المبلغ'],

    // ---------- Import ----------
    'File' => ['en' => 'File', 'nl' => 'Bestand', 'ar' => 'ملف'],
    'Start import' => ['en' => 'Start import', 'nl' => 'Import starten', 'ar' => 'بدء الاستيراد'],
    'The file must match the original sheet structure:' => ['en' => 'The file must match the original sheet structure:', 'nl' => 'Het bestand moet overeenkomen met de originele bladstructuur:', 'ar' => 'يجب أن يطابق الملف بنية الورقة الأصلية:'],
    'Numeric month values = payments (default method: bank)' => ['en' => 'Numeric month values = payments (default method: bank)', 'nl' => 'Numerieke waarden = betalingen (standaardmethode: bank)', 'ar' => 'القيم الرقمية للأشهر = دفعات (الطريقة الافتراضية: بنك)'],
    'Value 0 = recorded via bank previously (legacy_zero)' => ['en' => 'Value 0 = recorded via bank previously (legacy_zero)', 'nl' => 'Waarde 0 = eerder via bank geregistreerd (legacy_zero)', 'ar' => 'القيمة 0 = مسجَّلة سابقاً عبر البنك (legacy_zero)'],
    'Value X = manually marked late' => ['en' => 'Value X = manually marked late', 'nl' => 'Waarde X = handmatig als te laat gemarkeerd', 'ar' => 'القيمة X = تم تحديدها يدوياً كمتأخرة'],
    'Existing payments this month' => ['en' => 'Existing payments this month', 'nl' => 'Bestaande betalingen deze maand', 'ar' => 'الدفعات الحالية لهذا الشهر'],

    // ---------- Settings - General ----------
    'Fees & Currency' => ['en' => 'Fees & Currency', 'nl' => 'Kosten & Valuta', 'ar' => 'الرسوم والعملة'],
    'Default monthly fee' => ['en' => 'Default monthly fee', 'nl' => 'Standaard maandtarief', 'ar' => 'الرسم الشهري الافتراضي'],
    'Applies to every student unless overridden.' => ['en' => 'Applies to every student unless overridden.', 'nl' => 'Van toepassing op elke student tenzij overschreven.', 'ar' => 'ينطبق على كل طالب ما لم يتم تجاوزه.'],
    'School year starts' => ['en' => 'School year starts', 'nl' => 'Schooljaar begint', 'ar' => 'يبدأ العام الدراسي في'],

    // ---------- Settings - BulkGate ----------
    'API Credentials' => ['en' => 'API Credentials', 'nl' => 'API-referenties', 'ar' => 'بيانات اعتماد API'],
    'Sender Identity' => ['en' => 'Sender Identity', 'nl' => 'Afzenderidentiteit', 'ar' => 'هوية المرسل'],
    'The name parents see as sender.' => ['en' => 'The name parents see as sender.', 'nl' => 'De naam die ouders als afzender zien.', 'ar' => 'الاسم الذي يراه الأولياء كمرسل.'],
    'Default country' => ['en' => 'Default country', 'nl' => 'Standaardland', 'ar' => 'الدولة الافتراضية'],
    'Only used when phone is not in E.164 format.' => ['en' => 'Only used when phone is not in E.164 format.', 'nl' => 'Alleen gebruikt wanneer telefoon niet in E.164-formaat is.', 'ar' => 'تُستخدم فقط عندما لا يكون الهاتف بتنسيق E.164.'],
    'Price per SMS' => ['en' => 'Price per SMS', 'nl' => 'Prijs per SMS', 'ar' => 'سعر الرسالة القصيرة'],
    'Used only to estimate cost.' => ['en' => 'Used only to estimate cost.', 'nl' => 'Alleen om kosten te schatten.', 'ar' => 'تُستخدم فقط لتقدير التكلفة.'],
    'Force ASCII (reduces SMS length)' => ['en' => 'Force ASCII (reduces SMS length)', 'nl' => 'ASCII forceren (verkort SMS)', 'ar' => 'إجبار ASCII (يقلل طول الرسالة)'],
    'Converts é → e, € → EUR, etc. Keeps SMS to 160 chars instead of 70.' => ['en' => 'Converts é → e, € → EUR, etc. Keeps SMS to 160 chars instead of 70.', 'nl' => 'Zet é → e, € → EUR om. Houdt SMS op 160 tekens in plaats van 70.', 'ar' => 'يحوّل é إلى e، € إلى EUR، إلخ. يبقي الرسالة في 160 حرفاً بدل 70.'],
    'Get your credentials from' => ['en' => 'Get your credentials from', 'nl' => 'Krijg uw referenties van', 'ar' => 'احصل على بياناتك من'],
    'Token is stored encrypted.' => ['en' => 'Token is stored encrypted.', 'nl' => 'Token wordt versleuteld opgeslagen.', 'ar' => 'الرمز مُخزَّن مشفَّراً.'],
    'Leave blank to keep current value.' => ['en' => 'Leave blank to keep current value.', 'nl' => 'Laat leeg om huidige waarde te behouden.', 'ar' => 'اترك فارغاً للحفاظ على القيمة الحالية.'],
    'Usually "text" — matches the original Apps Script setting.' => ['en' => 'Usually "text" — matches the original Apps Script setting.', 'nl' => 'Meestal "text" — komt overeen met de originele Apps Script-instelling.', 'ar' => 'عادةً "text" — يطابق إعدادات Apps Script الأصلية.'],
    'Test connection' => ['en' => 'Test connection', 'nl' => 'Verbinding testen', 'ar' => 'اختبار الاتصال'],
    'Send a test SMS to verify your settings work. Saves you from sending bulk before confirming.' => ['en' => 'Send a test SMS to verify your settings work. Saves you from sending bulk before confirming.', 'nl' => 'Verzend een test-SMS om te verifiëren dat uw instellingen werken.', 'ar' => 'أرسل رسالة اختبار للتحقق من أن الإعدادات تعمل قبل الإرسال الجماعي.'],
    'Send test SMS' => ['en' => 'Send test SMS', 'nl' => 'Test-SMS verzenden', 'ar' => 'إرسال SMS للاختبار'],

    // ---------- Settings - WhatsApp ----------
    'WhatsApp Cloud API is the official Meta integration. Get your credentials at' => ['en' => 'WhatsApp Cloud API is the official Meta integration. Get your credentials at', 'nl' => 'WhatsApp Cloud API is de officiële Meta-integratie. Krijg uw referenties bij', 'ar' => 'WhatsApp Cloud API هو التكامل الرسمي لـ Meta. احصل على بياناتك من'],
    'Enable WhatsApp channel' => ['en' => 'Enable WhatsApp channel', 'nl' => 'WhatsApp-kanaal inschakelen', 'ar' => 'تفعيل قناة واتساب'],
    'When enabled, WhatsApp will be available as a sending channel in campaigns and individual messages.' => ['en' => 'When enabled, WhatsApp will be available as a sending channel in campaigns and individual messages.', 'nl' => 'Wanneer ingeschakeld, is WhatsApp beschikbaar als verzendkanaal in campagnes en individuele berichten.', 'ar' => 'عند التفعيل، سيكون واتساب متاحاً كقناة إرسال في الحملات والرسائل الفردية.'],
    'Found in: Meta Developer Console → WhatsApp → API Setup' => ['en' => 'Found in: Meta Developer Console → WhatsApp → API Setup', 'nl' => 'Te vinden in: Meta Developer Console → WhatsApp → API Setup', 'ar' => 'موجود في: Meta Developer Console → WhatsApp → API Setup'],
    'Generate a permanent token (not the temporary 24h one).' => ['en' => 'Generate a permanent token (not the temporary 24h one).', 'nl' => 'Genereer een permanente token (niet de tijdelijke 24-uurs).', 'ar' => 'أنشئ رمزاً دائماً (وليس المؤقت لمدة 24 ساعة).'],
    'Configure the same token in Meta webhook settings.' => ['en' => 'Configure the same token in Meta webhook settings.', 'nl' => 'Configureer dezelfde token in Meta webhook-instellingen.', 'ar' => 'اضبط نفس الرمز في إعدادات webhook في Meta.'],
    'Routing & Fallback' => ['en' => 'Routing & Fallback', 'nl' => 'Routering & Terugval', 'ar' => 'التوجيه والاحتياط'],
    'Auto-fallback to SMS if WhatsApp fails' => ['en' => 'Auto-fallback to SMS if WhatsApp fails', 'nl' => 'Automatische terugval naar SMS als WhatsApp faalt', 'ar' => 'التحويل تلقائياً إلى SMS إذا فشل واتساب'],
    'Fallback delay (minutes)' => ['en' => 'Fallback delay (minutes)', 'nl' => 'Terugvalvertraging (minuten)', 'ar' => 'تأخير الاحتياط (بالدقائق)'],
    'Wait this many minutes before triggering SMS fallback.' => ['en' => 'Wait this many minutes before triggering SMS fallback.', 'nl' => 'Wacht dit aantal minuten voordat SMS-terugval wordt geactiveerd.', 'ar' => 'انتظر هذا العدد من الدقائق قبل التحويل إلى SMS.'],
    'Price per conversation' => ['en' => 'Price per conversation', 'nl' => 'Prijs per gesprek', 'ar' => 'سعر كل محادثة'],
    'Meta charges per conversation, not per message.' => ['en' => 'Meta charges per conversation, not per message.', 'nl' => 'Meta rekent per gesprek, niet per bericht.', 'ar' => 'Meta تحسب السعر لكل محادثة وليس لكل رسالة.'],
    'Default language' => ['en' => 'Default language', 'nl' => 'Standaardtaal', 'ar' => 'اللغة الافتراضية'],

    // ---------- Settings - Reminders ----------
    'Reminder Templates (NL)' => ['en' => 'Reminder Templates (NL)', 'nl' => 'Herinneringstemplates (NL)', 'ar' => 'قوالب التذكير (NL)'],
    'These are the exact templates from the original Apps Script — you can edit them here.' => ['en' => 'These are the exact templates from the original Apps Script — you can edit them here.', 'nl' => 'Dit zijn de exacte templates uit het originele Apps Script — u kunt ze hier bewerken.', 'ar' => 'هذه القوالب من Apps Script الأصلية — يمكنك تعديلها هنا.'],
    'First Friday template (NL)' => ['en' => 'First Friday template (NL)', 'nl' => 'Eerste vrijdag-template (NL)', 'ar' => 'قالب أول جمعة (NL)'],
    'Mid-month template (NL)' => ['en' => 'Mid-month template (NL)', 'nl' => 'Midden-maand-template (NL)', 'ar' => 'قالب منتصف الشهر (NL)'],
    'These reminders run automatically every day at the time you set.' => ['en' => 'These reminders run automatically every day at the time you set.', 'nl' => 'Deze herinneringen worden dagelijks automatisch uitgevoerd op de ingestelde tijd.', 'ar' => 'تعمل هذه التذكيرات تلقائياً كل يوم في الوقت الذي تحدده.'],
    'Enable: First Friday reminder' => ['en' => 'Enable: First Friday reminder', 'nl' => 'Inschakelen: Eerste vrijdag-herinnering', 'ar' => 'تفعيل: تذكير أول جمعة'],
    'Enable: Mid-month late notice' => ['en' => 'Enable: Mid-month late notice', 'nl' => 'Inschakelen: Midden-maand-waarschuwing', 'ar' => 'تفعيل: إشعار منتصف الشهر'],
    'Sent to anyone unpaid on the first Friday of each month.' => ['en' => 'Sent to anyone unpaid on the first Friday of each month.', 'nl' => 'Verzonden naar iedereen die niet heeft betaald op de eerste vrijdag van elke maand.', 'ar' => 'يُرسَل لكل من لم يدفع في أول جمعة من كل شهر.'],
    'Sent to anyone late on day 15 of each month.' => ['en' => 'Sent to anyone late on day 15 of each month.', 'nl' => 'Verzonden naar iedereen die op dag 15 van de maand te laat is.', 'ar' => 'يُرسَل لكل من تأخر في اليوم 15 من كل شهر.'],
    'Trigger hour' => ['en' => 'Trigger hour', 'nl' => 'Uur van activering', 'ar' => 'ساعة التشغيل'],
    'Trigger minute' => ['en' => 'Trigger minute', 'nl' => 'Minuut van activering', 'ar' => 'دقيقة التشغيل'],
    'Mid-month day' => ['en' => 'Mid-month day', 'nl' => 'Dag midden in de maand', 'ar' => 'يوم منتصف الشهر'],
    'Run reminder manually now' => ['en' => 'Run reminder manually now', 'nl' => 'Herinnering nu handmatig uitvoeren', 'ar' => 'تشغيل التذكير يدوياً الآن'],
    'Force-run a reminder right now without waiting for the schedule.' => ['en' => 'Force-run a reminder right now without waiting for the schedule.', 'nl' => 'Voer nu een herinnering uit zonder te wachten op het schema.', 'ar' => 'أجبِر تشغيل التذكير الآن دون انتظار الجدول.'],
    'Run: First Friday' => ['en' => 'Run: First Friday', 'nl' => 'Uitvoeren: Eerste vrijdag', 'ar' => 'تشغيل: أول جمعة'],
    'Run: Mid-month' => ['en' => 'Run: Mid-month', 'nl' => 'Uitvoeren: Midden-maand', 'ar' => 'تشغيل: منتصف الشهر'],
    'Reminder triggered:' => ['en' => 'Reminder triggered:', 'nl' => 'Herinnering geactiveerd:', 'ar' => 'تم تشغيل التذكير:'],

    // ---------- Settings - Advanced ----------
    'Rate Limiting & Batching' => ['en' => 'Rate Limiting & Batching', 'nl' => 'Snelheidsbeperking & Batching', 'ar' => 'تحديد المعدل والتجميع'],
    'Max messages per hour' => ['en' => 'Max messages per hour', 'nl' => 'Max berichten per uur', 'ar' => 'الحد الأقصى للرسائل بالساعة'],
    'Hard cap to prevent provider quota errors.' => ['en' => 'Hard cap to prevent provider quota errors.', 'nl' => 'Harde limiet om provider-quota-fouten te voorkomen.', 'ar' => 'حد صارم لتجنُّب أخطاء حصة المزوّد.'],
    'Batch size' => ['en' => 'Batch size', 'nl' => 'Batchgrootte', 'ar' => 'حجم الدفعة'],
    'Number of messages per batch.' => ['en' => 'Number of messages per batch.', 'nl' => 'Aantal berichten per batch.', 'ar' => 'عدد الرسائل في كل دفعة.'],
    'Sleep between batches (ms)' => ['en' => 'Sleep between batches (ms)', 'nl' => 'Slaaptijd tussen batches (ms)', 'ar' => 'الفاصل بين الدفعات (م.ث)'],
    'Checkpoint every N' => ['en' => 'Checkpoint every N', 'nl' => 'Checkpoint elke N', 'ar' => 'حفظ التقدم كل N'],
    'Save progress after every N messages.' => ['en' => 'Save progress after every N messages.', 'nl' => 'Voortgang opslaan na elke N berichten.', 'ar' => 'احفظ التقدم بعد كل N رسالة.'],
    'Resume & Retry' => ['en' => 'Resume & Retry', 'nl' => 'Hervatten & Opnieuw proberen', 'ar' => 'الاستئناف وإعادة المحاولة'],
    'Resume delay (min)' => ['en' => 'Resume delay (min)', 'nl' => 'Hervattingsvertraging (min)', 'ar' => 'تأخير الاستئناف (دقيقة)'],
    'When hourly quota is exhausted, wait this long.' => ['en' => 'When hourly quota is exhausted, wait this long.', 'nl' => 'Wanneer uurlijkse quota is uitgeput, wacht zo lang.', 'ar' => 'عند استنفاد الحصة الساعية، انتظر هذه المدة.'],
    'Retry short (min)' => ['en' => 'Retry short (min)', 'nl' => 'Korte herhaling (min)', 'ar' => 'إعادة محاولة قصيرة (دقيقة)'],
    'Short retry for transient errors.' => ['en' => 'Short retry for transient errors.', 'nl' => 'Korte herhaling voor tijdelijke fouten.', 'ar' => 'إعادة محاولة قصيرة للأخطاء العابرة.'],
    'Max per tick' => ['en' => 'Max per tick', 'nl' => 'Max per tik', 'ar' => 'الحد الأقصى في كل دورة'],
    '0 = unlimited per worker run.' => ['en' => '0 = unlimited per worker run.', 'nl' => '0 = onbeperkt per worker-run.', 'ar' => '0 = بلا حد لكل تشغيل.'],

    // ---------- New keys used by our own upcoming fixes ----------
    'settings.field.application_id' => ['en' => 'Application ID', 'nl' => 'Applicatie-ID', 'ar' => 'معرّف التطبيق'],
    'settings.field.application_token' => ['en' => 'Application Token', 'nl' => 'Applicatie-token', 'ar' => 'رمز التطبيق'],
    'settings.field.sender_id_type' => ['en' => 'Sender ID Type', 'nl' => 'Type afzender-ID', 'ar' => 'نوع معرّف المرسل'],
    'settings.field.sender_id_value' => ['en' => 'Sender ID Value', 'nl' => 'Waarde afzender-ID', 'ar' => 'قيمة معرّف المرسل'],
    'settings.field.phone_number_id' => ['en' => 'Phone Number ID', 'nl' => 'Telefoonnummer-ID', 'ar' => 'معرّف رقم الهاتف'],
    'settings.field.business_account_id' => ['en' => 'Business Account ID (WABA ID)', 'nl' => 'Zakelijke account-ID (WABA)', 'ar' => 'معرّف حساب الأعمال (WABA)'],
    'settings.field.access_token' => ['en' => 'Permanent Access Token', 'nl' => 'Permanente toegangstoken', 'ar' => 'رمز الوصول الدائم'],
    'settings.field.app_secret' => ['en' => 'App Secret (optional, for webhook security)', 'nl' => 'App Secret (optioneel, voor webhook-beveiliging)', 'ar' => 'App Secret (اختياري، لأمان webhook)'],
    'settings.field.webhook_verify_token' => ['en' => 'Webhook Verify Token', 'nl' => 'Webhook-verificatietoken', 'ar' => 'رمز التحقّق للـ Webhook'],
    'settings.encrypted_hint' => ['en' => '🔒 encrypted', 'nl' => '🔒 versleuteld', 'ar' => '🔒 مشفَّر'],
    'settings.token_already_set' => ['en' => '••••• already set', 'nl' => '••••• al ingesteld', 'ar' => '••••• مضبوط مسبقاً'],
    'settings.paste_your_token' => ['en' => 'paste your token', 'nl' => 'plak uw token', 'ar' => 'الصق الرمز هنا'],
    'settings.webhook_url' => ['en' => 'Webhook URL', 'nl' => 'Webhook-URL', 'ar' => 'رابط Webhook'],
    'settings.verify_endpoint' => ['en' => 'Verify endpoint', 'nl' => 'Verificatie-endpoint', 'ar' => 'نقطة التحقّق'],
    'settings.test_phone_placeholder' => ['en' => '+316xxxxxxxx (your own phone)', 'nl' => '+316xxxxxxxx (uw eigen telefoon)', 'ar' => '+316xxxxxxxx (رقم هاتفك الشخصي)'],
    'settings.webhook_verify_placeholder' => ['en' => 'any random string of your choice', 'nl' => 'een willekeurige tekst naar keuze', 'ar' => 'أي نص عشوائي من اختيارك'],

    'panel.from' => ['en' => 'From', 'nl' => 'Van', 'ar' => 'من'],
    'panel.primary_phone' => ['en' => 'Primary phone', 'nl' => 'Hoofdtelefoon', 'ar' => 'الهاتف الأساسي'],
    'panel.secondary_phone' => ['en' => 'Secondary phone', 'nl' => 'Tweede telefoon', 'ar' => 'الهاتف الثانوي'],
    'panel.notes' => ['en' => 'Notes', 'nl' => 'Notities', 'ar' => 'ملاحظات'],
    'panel.amount_placeholder' => ['en' => 'Amount €', 'nl' => 'Bedrag €', 'ar' => 'المبلغ €'],
    'panel.reason_optional' => ['en' => 'Reason (optional)', 'nl' => 'Reden (optioneel)', 'ar' => 'السبب (اختياري)'],
    'panel.reason_required' => ['en' => 'Reason (required)', 'nl' => 'Reden (verplicht)', 'ar' => 'السبب (مطلوب)'],

    'common.close' => ['en' => 'Close', 'nl' => 'Sluiten', 'ar' => 'إغلاق'],
    'common.close_esc' => ['en' => 'Close (Esc)', 'nl' => 'Sluiten (Esc)', 'ar' => 'إغلاق (Esc)'],
    'send.type_message_placeholder' => ['en' => 'Type your message...', 'nl' => 'Typ uw bericht...', 'ar' => 'اكتب رسالتك...'],
    'payment.save_shortcut_hint' => ['en' => 'Ctrl+Enter to save', 'nl' => 'Ctrl+Enter om op te slaan', 'ar' => 'Ctrl+Enter للحفظ'],

    // ---------- Flash / toast messages ----------
    'flash.saved' => ['en' => 'Saved ✓', 'nl' => 'Opgeslagen ✓', 'ar' => 'تم الحفظ ✓'],
    'flash.updated' => ['en' => 'Updated ✓', 'nl' => 'Bijgewerkt ✓', 'ar' => 'تم التحديث ✓'],
    'flash.deleted' => ['en' => 'Deleted ✓', 'nl' => 'Verwijderd ✓', 'ar' => 'تم الحذف ✓'],
    'flash.duplicated' => ['en' => 'Duplicated ✓', 'nl' => 'Gedupliceerd ✓', 'ar' => 'تم النسخ ✓'],
    'flash.payment_saved' => ['en' => 'Payment saved ✓', 'nl' => 'Betaling opgeslagen ✓', 'ar' => 'تم حفظ الدفعة ✓'],
    'flash.payment_deleted' => ['en' => 'Payment deleted ✓', 'nl' => 'Betaling verwijderd ✓', 'ar' => 'تم حذف الدفعة ✓'],
    'flash.quick_saved' => ['en' => 'Saved :amount€ ✓ — next', 'nl' => 'Opgeslagen :amount€ ✓ — volgende', 'ar' => 'تم حفظ :amount€ ✓ — التالي'],
    'flash.suspension_created' => ['en' => 'Suspension created ✓', 'nl' => 'Opschorting aangemaakt ✓', 'ar' => 'تم إنشاء العزل ✓'],
    'flash.override_added' => ['en' => 'Custom fee added ✓', 'nl' => 'Aangepast tarief toegevoegd ✓', 'ar' => 'تم إضافة الرسم الخاص ✓'],
    'flash.surcharge_added' => ['en' => 'Surcharge added ✓', 'nl' => 'Toeslag toegevoegd ✓', 'ar' => 'تم إضافة الرسم الإضافي ✓'],
    'flash.send_resumed' => ['en' => 'Sending resumed ✓', 'nl' => 'Verzenden hervat ✓', 'ar' => 'تم استئناف الإرسال ✓'],
    'flash.send_halted' => ['en' => 'Sending halted immediately 🔴', 'nl' => 'Verzenden onmiddellijk gestopt 🔴', 'ar' => 'تم إيقاف الإرسال فوراً 🔴'],
    'flash.import_failed' => ['en' => 'Import failed:', 'nl' => 'Import mislukt:', 'ar' => 'فشل الاستيراد:'],
    'flash.import_success' => ['en' => 'Import completed ✓ — :students students, :payments payments', 'nl' => 'Import voltooid ✓ — :students studenten, :payments betalingen', 'ar' => 'تم الاستيراد ✓ — :students طالب، :payments دفعة'],
    'flash.test_phone_required' => ['en' => '✗ Phone number required', 'nl' => '✗ Telefoonnummer vereist', 'ar' => '✗ رقم الهاتف مطلوب'],
    'flash.test_message_prefix' => ['en' => 'Test from Al Boukhari system —', 'nl' => 'Test vanaf Al Boukhari-systeem —', 'ar' => 'اختبار من نظام البخاري —'],
    'flash.test_sent' => ['en' => '✓ Sent to :phone', 'nl' => '✓ Verzonden naar :phone', 'ar' => '✓ تم الإرسال إلى :phone'],
    'flash.send_error' => ['en' => '✗ Error:', 'nl' => '✗ Fout:', 'ar' => '✗ خطأ:'],
    'flash.enter_test_phone' => ['en' => 'Enter test phone number', 'nl' => 'Voer testtelefoonnummer in', 'ar' => 'أدخل رقم الاختبار'],
    'flash.enter_message_body' => ['en' => 'Enter message body', 'nl' => 'Voer berichttekst in', 'ar' => 'اكتب نص الرسالة'],
    'flash.no_recipients' => ['en' => 'No recipients', 'nl' => 'Geen ontvangers', 'ar' => 'لا يوجد مستلمون'],

    // ---------- Domain error messages ----------
    'error.send_halted_by_admin' => ['en' => 'Sending has been halted by the administrator.', 'nl' => 'Verzenden is gestopt door de beheerder.', 'ar' => 'الإرسال موقَف من قِبَل المسؤول.'],
    'error.student_cannot_receive' => ['en' => 'Student cannot receive messages:', 'nl' => 'Student kan geen berichten ontvangen:', 'ar' => 'الطالب لا يستقبل رسائل:'],
    'error.hourly_quota_exceeded' => ['en' => 'Hourly quota exceeded.', 'nl' => 'Uurlijkse quota overschreden.', 'ar' => 'تجاوزت الحد الساعي.'],
    'error.no_data_rows' => ['en' => 'No data rows in the sheet.', 'nl' => 'Geen gegevensrijen in het blad.', 'ar' => 'لا توجد صفوف بيانات في الورقة.'],
    'error.bulkgate_not_configured' => ['en' => 'BulkGate credentials are not configured. Go to Settings.', 'nl' => 'BulkGate-referenties zijn niet ingesteld. Ga naar Instellingen.', 'ar' => 'مفاتيح BulkGate غير مضبوطة. اذهب إلى الإعدادات.'],
    'error.whatsapp_not_configured' => ['en' => 'WhatsApp credentials are not configured (Settings → WhatsApp).', 'nl' => 'WhatsApp-referenties zijn niet ingesteld (Instellingen → WhatsApp).', 'ar' => 'بيانات WhatsApp غير مضبوطة (الإعدادات → WhatsApp).'],
];

$locales = ['en', 'nl', 'ar'];
$stats = ['added' => 0, 'existing' => 0];

foreach ($locales as $loc) {
    $path = "$root/lang/$loc.json";
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) { fwrite(STDERR, "Failed to parse $path\n"); exit(1); }

    $added = 0;
    foreach ($translations as $key => $vals) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $vals[$loc];
            $added++;
        }
    }

    if ($added > 0) {
        // Pretty-print with 4-space indent, unescaped Unicode + slashes
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json . "\n");
    }

    echo str_pad($loc, 4) . " → added: $added  total keys: " . count($data) . PHP_EOL;
    $stats['added'] += $added;
}

echo PHP_EOL . "Done. New keys added across all locales: {$stats['added']}" . PHP_EOL;
