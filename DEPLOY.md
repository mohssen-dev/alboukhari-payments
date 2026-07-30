# 🚀 دليل النشر على Hostinger — Git-based auto-sync

نمط النشر: **دفع (push) إلى `main` → GitHub Actions يفتح SSH إلى Hostinger → `git pull` + `composer install` + `migrate` + `cache rebuild` تلقائياً**.

هذا الملف يشرح الإعداد لمرّة واحدة، ثم كيف تعمل التحديثات بعد ذلك.

---

## 🖥️ متطلبات الاستضافة

- **Hostinger Business** (يدعم SSH + Git). خطط أرخص لا تدعم SSH — لن يعمل النشر التلقائي.
- **PHP ≥ 8.3** مع الإكستنشنز: `pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, fileinfo, bcmath, gd, zip, intl`
- **MySQL/MariaDB** (SQLite لا يصلح للإنتاج على استضافة مشتركة)
- **Composer 2** (مثبَّت افتراضياً على Hostinger Business)
- **Git 2** (مثبَّت افتراضياً)
- **SSL** — أنشئ عبر Hostinger (Let's Encrypt مجاني)

---

## 📁 هيكل الملفات على السيرفر

```
/home/u475164459/domains/payments.alboukhari.nl/
└── public_html/
    ├── .htaccess          ← ملف خادم يدوي (نسخة من deploy/hostinger-wrapper.htaccess)
    └── laravel/           ← Git clone للمستودع
        ├── app/           ← مصدر Laravel (محجوب من الويب)
        ├── vendor/
        ├── deploy/
        ├── .env           ← أسرار (محجوبة من الويب)
        ├── .github/
        └── app/public/    ← جذر الويب الحقيقي (يُقدَّم عبر wrapper)
```

**مهم**: الـ Laravel app داخل مجلد `app/` وليس في جذر المستودع. لذا الـ wrapper يوجّه إلى `laravel/app/public/index.php`.

---

## 1️⃣ إعداد لمرّة واحدة (Onboarding)

### أ) على Hostinger — قاعدة البيانات

من hPanel → **Databases → MySQL Databases**:
1. أنشئ قاعدة جديدة (مثلاً `u475164459_payments`)
2. أنشئ مستخدماً بكلمة سر قوية
3. اربطهما بصلاحيات ALL PRIVILEGES
4. سجّل: DB_NAME, DB_USER, DB_PASSWORD

### ب) على Hostinger — SSH + Git

من hPanel → **Advanced → SSH Access**:
1. فعّل SSH
2. ولّد أو أضف SSH Key
3. سجّل: `SSH host` (مثل `ssh.hostinger.com` أو `de-fra-webXX.main-hosting.eu`)، `port` (عادة `65002`)، `username` (مثل `u475164459`)

### ج) استنساخ المستودع على السيرفر

```bash
ssh -p 65002 u475164459@YOUR_SSH_HOST
cd ~/domains/payments.alboukhari.nl/public_html
git clone https://github.com/M9hs3n/alboukhari-payments.git laravel
cd laravel
composer install --no-dev --optimize-autoloader --no-interaction
cp app/.env.production.example app/.env
```

عدّل `app/.env` من nano/vim وضع:
- قيم DB الحقيقية
- كلمة سر SMTP
- APP_URL الفعلي

ثم:
```bash
cd app
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=DefaultAdminSeeder --force   # يُنشئ حساب أدمن أوّلي
php artisan storage:link 2>/dev/null || ln -s ../storage/app/public public/storage
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod -R 775 storage bootstrap/cache
```

### د) رفع wrapper .htaccess

من hPanel → File Manager → `public_html/`:
- انسخ محتوى [`deploy/hostinger-wrapper.htaccess`](deploy/hostinger-wrapper.htaccess) إلى ملف جديد اسمه `.htaccess` مباشرة داخل `public_html/`

### هـ) اختبار مبدئي

افتح `https://payments.alboukhari.nl/` — يجب أن تظهر شاشة تسجيل الدخول.

سجّل دخول بالحساب الأولي (أنشأه seeder) وغيّر كلمة السر فوراً من `/profile`.

---

## 2️⃣ إعداد GitHub Actions — النشر التلقائي

هذا يُشغَّل عبر [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) عند كل push إلى `main`.

من GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**، أضف:

| Secret | القيمة |
|---|---|
| `SSH_HOST` | مثل `de-fra-web2071.main-hosting.eu` |
| `SSH_USER` | مثل `u475164459` |
| `SSH_PORT` | مثل `65002` |
| `SSH_PRIVATE_KEY` | محتوى ملف مفتاح SSH الخاص (يبدأ بـ `-----BEGIN OPENSSH PRIVATE KEY-----`) |
| `DEPLOY_PATH` | مثل `/home/u475164459/domains/payments.alboukhari.nl/public_html/laravel` |
| `APP_URL` | `https://payments.alboukhari.nl` |

**توليد SSH key** (على جهازك المحلي):
```bash
ssh-keygen -t ed25519 -f ~/.ssh/hostinger_payments -C "github-actions-payments"
# الملف الخاص (السرّي): ~/.ssh/hostinger_payments
# الملف العام (الذي يُرفع للسيرفر): ~/.ssh/hostinger_payments.pub
```

ثم على السيرفر:
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
cat ~/.ssh/hostinger_payments.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

واختبر:
```bash
ssh -i ~/.ssh/hostinger_payments -p 65002 u475164459@YOUR_HOST 'echo OK'
```

انسخ محتوى `~/.ssh/hostinger_payments` (الخاص) كاملاً إلى GitHub Secret `SSH_PRIVATE_KEY`.

---

## 3️⃣ التحديثات الروتينية (بعد الإعداد)

من جهازك المحلي:
```bash
git add .
git commit -m "feat: whatever"
git push origin main
```

GitHub Actions يشتغل تلقائياً:
1. يفتح SSH إلى السيرفر
2. `git pull --ff-only origin main`
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan config:cache && route:cache && view:cache`
6. Health check: `curl -sSI $APP_URL`

راقب النشر من GitHub → repo → **Actions**.

### تشغيل النشر يدوياً

من GitHub UI → **Actions → Deploy to Hostinger → Run workflow**، أو من الطرفية:
```bash
gh workflow run deploy.yml
```

---

## 4️⃣ الأعمال التي تتطلّب SSH يدوي

هذه لا يقوم بها الـ workflow ولا يمكن أتمتتها:

| العمل | الأمر |
|---|---|
| إضافة seeder جديد (أول مرة) | `php artisan db:seed --class=Foo --force` |
| Rollback migration | `php artisan migrate:rollback --step=1` |
| مسح كل الكاش (نادراً) | `php artisan optimize:clear` |
| نسخة احتياطية DB | `mysqldump -u USER -p DB > backup.sql` |
| قراءة السجلات | `tail -f storage/logs/laravel.log` |

---

## 5️⃣ استكشاف الأعطال

| الخطأ | السبب المحتمل | الحل |
|---|---|---|
| **500 Server Error** | `.env` غير مضبوط، `APP_KEY` فارغ، صلاحيات storage خاطئة | `tail -50 app/storage/logs/laravel.log` |
| **419 Page Expired** | `SESSION_DOMAIN` غير مطابق | امسح `app/storage/framework/sessions/*` واضبط `SESSION_DOMAIN=null` |
| **CSS/JS لا تظهر** | wrapper .htaccess يحجب `/assets/` | تحقق من قواعد Rewrite في الـ wrapper |
| **`Could not find driver`** | `pdo_mysql` غير مفعّل | hPanel → PHP Configuration → Extensions |
| **GitHub Action تفشل بـ Permission denied** | مفتاح SSH خاطئ في Secrets أو غير مضاف لـ authorized_keys | أعد اختبار SSH يدوياً |
| **`fatal: refusing to merge unrelated histories`** | Git على السيرفر ليس نسخة نظيفة من remote | `git reset --hard origin/main` (يحذف تعديلات السيرفر — احتفظ بنسخة من `.env` أولاً!) |

---

## 6️⃣ التراجع (Rollback)

**الأسرع**: من طرفية السيرفر
```bash
cd $DEPLOY_PATH
git log --oneline -5             # حدّد commit سليم
git reset --hard <good-sha>
composer install --no-dev
php artisan migrate --force      # إن كانت هناك migrations للتراجع، شغّل migrate:rollback بدلاً
php artisan optimize:clear
php artisan optimize
```

**الأنظف**: من GitHub — revert الـ commit المشكل بـ PR جديد، ادمج، والـ workflow سيُشغَّل تلقائياً.

---

## 7️⃣ قائمة فحص أمنيّة نهائية

- [ ] `APP_ENV=production` و `APP_DEBUG=false` في `.env`
- [ ] `SESSION_SECURE_COOKIE=true` و HTTPS مفروض
- [ ] كلمة سر حساب الأدمن الأولي غُيّرت
- [ ] `.env` غير قابل للقراءة عبر الويب: `curl -I https://payments.alboukhari.nl/laravel/.env` يجب أن يعطي 403
- [ ] `.git/` غير قابل للقراءة: `curl -I https://payments.alboukhari.nl/laravel/.git/config` يجب أن يعطي 403
- [ ] نسخة احتياطية يومية لـ DB من hPanel → Backups
- [ ] `SSH_PRIVATE_KEY` في GitHub Secrets وليس في الكود
