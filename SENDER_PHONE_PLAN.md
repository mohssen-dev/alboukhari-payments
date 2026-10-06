# الهاتف المرسِل — ما بُني في الباك إند وخطة واجهات الويب

تطبيق Android «مرسل البخاري» (مجلد `mobile/`، **غير مرفوع إلى Git عمداً** لأن GitHub مربوط بالنشر التلقائي على السيرفر) يستقبل رسائل الحملات من هذا النظام ويرسلها من الهاتف عبر SMS أو واتساب ثم يبلّغ النتيجة.

هذا الملف يوثّق: (1) ما هو موجود وجاهز في Laravel الآن، (2) عقد الـ API، (3) **خطة واجهات الويب المتبقية** بتفصيل يسمح ببنائها بسرعة.

---

## 1. ما هو موجود الآن في Laravel (مبني ومختبَر)

### الجداول

| الجدول | الغرض |
|---|---|
| `sender_devices` | كل هاتف مقترن: `name, token_hash, model, manufacturer, android_version, sdk_int, app_version, capabilities(json), last_heartbeat(json), last_seen_at, is_active, paired_by` |
| `sender_device_pairing_codes` | رموز اقتران 8 أحرف، صالحة 10 دقائق، استخدام واحد: `code, expires_at, used_at, sender_device_id, created_by` |
| `sender_whatsapp_groups` | مجموعات واتساب بالاسم الحرفي لكل هاتف: `sender_device_id, name, verified_at, notes` |
| `campaigns` (+أعمدة) | `channel` = `bulkgate_sms` (افتراضي) \| `device_sms` \| `device_whatsapp`، و`sender_device_id` |
| `campaign_recipients` (+أعمدة) | `claimed_at`, `sender_device_id`, `sender_whatsapp_group_id` |
| `message_logs.provider` | قيمتان جديدتان: `device_sms`, `device_whatsapp` |

### الكود

- النماذج: `SenderDevice`, `SenderDevicePairingCode`, `SenderWhatsappGroup`؛ إضافات على `Campaign` (`isDeviceChannel()`, `deviceChannelName()`, `providerName()`, `senderDevice()`) و`CampaignRecipient` (`deviceTarget()`, `isFinal()`).
- `App\Services\SenderDeviceQueue`: الحجز (`claim`)، التقارير (`report`)، إعادة الرسائل العالقة (`requeueStale`)، إغلاق الحملة (`finalizeIfDone`)، عدّاد المعلّق (`pendingCount`).
- `App\Services\SenderDeviceConfig`: إعدادات الوتيرة (`sender_*`) بقيم افتراضية محافظة لهاتف شخصي، مع `forDevice()` للحمولة التي يستقبلها التطبيق.
- `App\Http\Middleware\AuthenticateSenderDevice` (alias `sender.device`) و`App\Http\Controllers\Api\SenderDeviceController` و`routes/api.php`.
- `CampaignSender::sendCampaign` يسلّم حملات `device_*` للهاتف بدل BulkGate (`handed_to_device`).
- `SettingsController::KEYS` يقبل مفاتيح `sender_*` (الفورم يُبنى في الخطة أدناه).
- أوامر مؤقتة حتى تُبنى الواجهات: `sender:pair-code`, `sender:devices`, `sender:test-campaign`.
- اختبارات: `tests/Feature/SenderDeviceApiTest.php`.

### عقد الـ API (`/api/device/...`)

| المسار | الطلب | الاستجابة |
|---|---|---|
| `POST /pair` (بلا توكن، throttle 10/دقيقة) | `{code, device_name, model?, manufacturer?, android_version?, sdk_int?, app_version?}` | `201 {device_id, token, server_time, config}` |
| `POST /jobs/claim` | `{limit?≤100, channels?: ["sms","whatsapp"]}` | `{halt, jobs:[{id, campaign_id, channel, to, name, body, segments, tag}], pending_remote, config}` |
| `POST /jobs/report` | `{results:[{id, status: sent\|failed\|skipped, error?, attempts?, sent_at?}]}` | `{ok, accepted, duplicate, unknown, upgraded}` — idempotent؛ `upgraded` = إعادة محاولة يدوية من الهاتف حوّلت failed/skipped إلى sent |
| `POST /heartbeat` | `{pending_local, sent_today, sent_today_sms, sent_today_whatsapp, failed_local, whatsapp_package, whatsapp_mode, accessibility_enabled, sms_permission, sms_subscription_id, battery_level, battery_charging, screen_on, screen_locked, paused, attention}` | `{halt, pending_remote, config}` — الحمولة كاملة تُخزَّن في `sender_devices.last_heartbeat` |
| `GET /schedule` | — | `{campaigns:[{id, type, channel, status, total_recipients, sent_count, scheduled_at}]}` |
| `POST /unpair` | — | `{ok}` |

`config` = `min_delay_sec, max_delay_sec, batch_size, batch_pause_sec, daily_cap_whatsapp, daily_cap_sms, window_start, window_end, whatsapp_mode, poll_interval_sec, max_attempts`.

`to` إمّا رقم E.164 أو `group:<اسم المجموعة>`.

---

## 2. خطة واجهات الويب (لم تُبنَ بعد)

الترتيب أدناه هو ترتيب البناء المقترح. كل بند يذكر الملفات، الحقول، والمنطق، ومفاتيح الترجمة المطلوبة في `lang/{ar,en,nl}.json`.

### 2.1 تبويب «الهاتف المرسِل» في الإعدادات (أولوية 1)

**المسار:** `/settings?tab=sender` — أدمن فقط (المجموعة الموجودة `role:admin`).

**الملفات:**
- `resources/views/settings.blade.php`: إضافة التبويب بجانب BulkGate/WhatsApp، ويستدعي partial جديد `resources/views/settings/_sender.blade.php`.
- `SettingsController::edit`: تمرير `$senderDevices = SenderDevice::withCount(...)`, `$senderConfig = SenderDeviceConfig::all()`, `$activePairingCode` (آخر رمز غير مستخدم وغير منتهٍ لهذا المستخدم).
- مسارات جديدة في `routes/web.php` داخل مجموعة الأدمن:
  - `POST /settings/sender/pair-code` → `SenderDeviceWebController@createPairingCode` (ينشئ `SenderDevicePairingCode::generate(auth()->id())` ويعود للتبويب).
  - `POST /settings/sender/devices/{device}/rename`
  - `POST /settings/sender/devices/{device}/toggle` (تفعيل/تعطيل = يبطل التوكن عند التعطيل بنفس منطق `unpair`).
  - `DELETE /settings/sender/devices/{device}` (يمنع الحذف إن كانت له حملات `running`).
  - `POST /settings/sender/devices/{device}/groups` و`DELETE .../groups/{group}` لإدارة `sender_whatsapp_groups`.

**قسم أ — الاقتران:**
- زر «توليد رمز اقتران» → يعرض الرمز كبيراً + عدّاد تنازلي للصلاحية + QR.
- QR: ارسم بمكتبة JS خفيفة (مثل `qrcode` عبر CDN كما يُحمَّل Alpine) من `$code->qrPayload()`، أي `{"u":"https://payments.alboukhari.nl","c":"ABCD2345"}`.
- تعليمات مختصرة: «افتح التطبيق ← امسح الرمز أو اكتبه».

**قسم ب — الأجهزة المقترنة (جدول):**
- الأعمدة: الاسم (قابل للتعديل inline)، الطراز/Android/إصدار التطبيق، حالة الاتصال (`isOnline()` → نقطة خضراء + «منذ x»)، واتساب المختار (`capabilities.whatsapp_package` → «واتساب» / «واتساب للأعمال»)، خدمة إمكانية الوصول (`capabilities.accessibility_enabled`)، الرسائل المعلّقة (`SenderDeviceQueue::pendingCount`)، أزرار: تعطيل/تفعيل، حذف.
- من `last_heartbeat` (json): البطارية `battery_level`/`battery_charging`، الشاشة `screen_on`/`screen_locked`، `paused`، وسبب الانتظار `attention` (`SCREEN_LOCKED`, `ACCESSIBILITY_DISABLED`, `SMS_PERMISSION`, `WHATSAPP_APP_NOT_SELECTED`) → شارة حمراء «الهاتف ينتظر: …» حتى يعرف المدير لماذا لا تتحرك الحملة دون فتح الهاتف.
- تحذير أصفر إن كان هناك حملة `running` بقناة `device_*` وهاتفها غير متصل منذ > 10 دقائق.

**قسم ج — وتيرة الإرسال (فورم يُرسل إلى `settings.update` مع `tab=sender`):**
- الحقول من `SenderDeviceConfig::KEYS` مع القيم الافتراضية وتلميح لكل حقل:
  - `sender_min_delay_sec` / `sender_max_delay_sec` (ثوانٍ، عشوائي بينهما)
  - `sender_batch_size` / `sender_batch_pause_sec`
  - `sender_daily_cap_whatsapp` / `sender_daily_cap_sms` (0 = بلا حد)
  - `sender_window_start` / `sender_window_end` (HH:mm بتوقيت الهاتف)
  - `sender_whatsapp_mode` (select: auto / assisted)
  - `sender_poll_interval_sec`, `sender_max_attempts`, `sender_claim_stale_minutes`
- تحقق في `SettingsController::update`: min ≤ max، الأوقات بصيغة HH:mm، الأعداد ≥ 0. (اليوم يحفظ أي نص؛ أضف قواعد validation لهذه المفاتيح فقط.)
- ملاحظة تظهر فوق الفورم: «القيم الافتراضية محافظة لأن الهاتف شخصي؛ رفع الحد اليومي لواتساب يزيد خطر الحظر».

**قسم د — مجموعات واتساب لكل هاتف:**
- لكل جهاز: قائمة أسماء + حقل إضافة + شارة «مُتحقَّق» إن كان `verified_at` غير فارغ. (التحقق يتم من الهاتف لاحقاً عبر endpoint `POST /api/device/groups/{id}/verify` — يُضاف عند بناء ميزة المجموعات في التطبيق.)

**مفاتيح الترجمة المقترحة:** `settings.tab_sender`, `sender.pair_code`, `sender.generate_code`, `sender.code_expires_in`, `sender.devices`, `sender.online`, `sender.offline`, `sender.last_seen`, `sender.whatsapp_app`, `sender.accessibility`, `sender.pending`, `sender.disable`, `sender.enable`, `sender.delete`, `sender.groups`, `sender.add_group`, `sender.config_title`, `sender.config_hint_personal_phone`, ومفتاح لكل حقل.

### 2.2 محدّد القناة والهاتف في صفحة الإرسال (أولوية 2)

**الملفات:** `app/Livewire/SendCampaign.php`, `resources/views/livewire/send-campaign.blade.php`.

- خاصيتان جديدتان: `public string $channel = Campaign::CHANNEL_BULKGATE_SMS;` و`public ?int $senderDeviceId = null;`.
- في الواجهة (فوق القالب): مجموعة أزرار راديو: «SMS عبر BulkGate» / «SMS من الهاتف» / «واتساب من الهاتف». عند اختيار قناة هاتف يظهر select بالأجهزة النشطة (`SenderDevice::where('is_active', true)`) مع نقطة الاتصال؛ إن لم يوجد جهاز → رسالة تحيل إلى تبويب الإعدادات.
- `preview()`: للقناة `device_whatsapp` لا يُعرض «التكلفة التقديرية» (صفر) بل «عدد الرسائل» فقط؛ ويُضاف فحص `allow_whatsapp` للطالب في `RecipientListBuilder` عندما تكون القناة واتساب (اليوم يُفلتر على `allow_sms` فقط — أضف معاملاً `channel` إلى `build()` واستخدم `allow_whatsapp` بدل `allow_sms` في `skipReason` عند القناة واتساب).
- `launch()` و`schedule()`: تمرير `channel` و`sender_device_id` إلى `Campaign::create`. رسالة النتيجة عند `handed_to_device`: «سُلِّمت للهاتف {الاسم} — {N} رسالة بانتظاره».
- `sendTest()`: عند قناة هاتف، أنشئ حملة اختبار بمستلم واحد (نفس منطق `sender:test-campaign`) بدل استدعاء BulkGate.
- التحقق: `senderDeviceId` مطلوب وموجود ونشط عندما تكون القناة `device_*`.
- `updated()`: تغيير القناة يُلغي المعاينة (مثل باقي المعايير).

### 2.3 صفحة الحملة وقائمة الحملات (أولوية 3)

**الملفات:** `resources/views/campaigns/show.blade.php`, `campaigns/index.blade.php`, `CampaignsController`.

- شارة القناة في القائمة والصفحة: «BulkGate» / «هاتف · SMS» / «هاتف · واتساب» + اسم الهاتف.
- في صفحة الحملة لقناة الهاتف: صندوق حالة «بانتظار الهاتف / الهاتف يرسل الآن / الهاتف غير متصل منذ x» مبني على `senderDevice->isOnline()`، وعدّاد `pending/sending/sent/failed/skipped`، وزر «إعادة إسناد إلى هاتف آخر» (يغيّر `sender_device_id` ويعيد المستلمين `sending` إلى `pending` مع `claimed_at = null`).
- زر «إلغاء» الموجود يبقى؛ الرسائل `sending` لدى الهاتف تُبلَّغ لاحقاً وتُرفض إن كانت الحملة `canceled` (أضف هذا الشرط في `SenderDeviceQueue::report`: تجاهل تقارير حملة ملغاة مع عدّها `duplicate`).
- في جدول المستلمين: عمود «آخر خطأ» يعرض `last_error` (مثل `NOT_ON_WHATSAPP`, `NO_SERVICE`, `ACCESSIBILITY_DISABLED`) مع ترجمة الرموز الشائعة.

### 2.4 لوحة المؤشرات والتقارير (أولوية 4)

- في الرئيسية: بطاقة «الهاتف المرسِل» تعرض عدد الأجهزة المتصلة والرسائل المعلّقة لديها.
- في التقارير: فلتر `provider` يشمل `device_sms` و`device_whatsapp`؛ التكلفة لهذه القنوات صفر.

### 2.5 الإرسال إلى مجموعات واتساب (بعد استقرار الإرسال الفردي)

- في صفحة الإرسال: نوع حملة جديد «إعلان لمجموعات واتساب» (`type = whatsapp_groups`) يختار هاتفاً ومجموعة أو أكثر من `sender_whatsapp_groups`، وينشئ مستلماً لكل مجموعة بـ `sender_whatsapp_group_id` و`phone_e164 = ''`.
- يتطلب إضافة `whatsapp_groups` إلى enum `campaigns.type` (migration) وإلى `campaigns.type.*` في الترجمة.
- التطبيق يستقبل `to = "group:<الاسم>"` ويستخدم شاشة «إرسال إلى…».

### 2.6 قائمة التحقق قبل الإطلاق

- [ ] `php artisan migrate` على الإنتاج (يجري تلقائياً مع النشر).
- [ ] تبويب الإعدادات يولّد رمزاً ويُظهر الجهاز بعد الاقتران بحالة «متصل».
- [ ] حملة SMS من الهاتف لطالب واحد → تصل وتظهر `SENT` في `message_logs` بمزوّد `device_sms`.
- [ ] حملة واتساب لرقم غير موجود على واتساب → `skipped` مع `NOT_ON_WHATSAPP`.
- [ ] الإيقاف الفوري من الويب يوقف الهاتف خلال ≤ 20 ثانية.
- [ ] إطفاء الهاتف أثناء حملة → بعد 30 دقيقة تعود الرسائل المحجوزة للطابور وتُرسل عند عودته.

---

## 3. ملاحظات تشغيلية (هاتف شخصي)

- الوضع التلقائي لواتساب يحتاج الشاشة مضاءة وغير مقفلة أثناء الدفعة؛ على هاتف شخصي شغّل الدفعات في وقت لا تستخدم فيه الهاتف، أو استخدم الوضع المساعد.
- الحدود الافتراضية (100 واتساب / 300 SMS يومياً، 10–25 ثانية بين الرسائل) مقصودة لحماية الرقم الشخصي؛ لا ترفعها قبل أسابيع من الاستخدام المنتظم.
- الرسائل المرسَلة من التطبيق لا تظهر في تطبيق الرسائل الافتراضي (قيد نظام Android)؛ السجل الكامل في صفحة الحملة وفي التطبيق.
- تشغيل محلي للاختبار: `php artisan serve --host=0.0.0.0` ثم في التطبيق رابط `http://<IP اللابتوب>:8009`، أو عبر USB: `adb reverse tcp:8009 tcp:8009` ورابط `http://127.0.0.1:8009` (نسخة debug فقط تسمح بـ HTTP).
