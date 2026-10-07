# NISHATI Meeting Room Booking System (MRS) — README

**Kinasasishwa:** 29/09/2026
**Lengo la faili hii:** kuwa chanzo cha ukweli (source of truth) cha mfumo mzima —
kwa ajili ya kutengeneza **User Manual** na **User Matrix**.

Mfumo unaruhusu watumiaji kuchagua chumba cha mikutano na kukihifadhi kwa tarehe
na muda maalum (single meeting au continuous meeting), kupitia terminal ya
mtandaoni. Admin anasimamia vyumba, projectors, watumiaji, ripoti, audit log,
mail na **roles & permissions matrix**.

---

## 1. Teknolojia na Mahitaji

- PHP 7.4+ (inafanya kazi pia na PHP 8.x), MySQL/MariaDB — XAMPP / WAMP / Laragon
- Hakuna framework (PHP ya moja kwa moja + mysqli/PDO prepared statements)
- Frontend: HTML/CSS/JS halisi (`assets/css/style.css`, `assets/js/`)
- Email: PHPMailer (`vendor/phpmailer/`) kupitia SMTP
- Browser yoyote; design ni responsive + **dark mode** (theme toggle kwenye navbar)

---

## 2. Watumiaji na Roles

Kuna **roles 2** tu (`users.role`):

| Role | Maelezo |
|------|---------|
| `admin` | Msimamizi kamili. Ana **full access daima** (column yake kwenye permissions matrix ni read-only LOCKED, haizimwi). |
| `staff` | Mtumiaji wa kawaida anayehifadhi vyumba, kuona ratiba, kuwasilisha matatizo. Ruhusa zake hudhibitiwa na permissions matrix. |

**Hali za mtumiaji** (`users.status`):

| Hali | Maana |
|------|-------|
| `pending` | Hajakamilisha thibitisho ya barua pepe — hawezi kuingia. |
| `active` | Anafanya kazi. |
| `inactive` | Amezuiwa na admin (hawezi kuingia). |

**Sheria za msingi:**
- Usajili (register) na admin akiongeza mtumiaji: email **lazima iwe `@nishati.go.tz`**.
- Mtumiaji mpya huanza `pending` → anakuwa `active` baada ya kuweka **code ya tarakimu 6** kutoka email yake.
- Admin hupita verification moja kwa moja (akaunti ni `active` mara moja).
- Admin wa mwisho ACTIVE **hawezi** kushushwa hadi `staff` au kufutwa (lockout protection).
- Admin **hawezi** kubadilisha password yake mwenyewe kwenye paneli (lazima aodie reset).

---

## 3. USER MATRIX — Ruhusa za Mfumo (Registry)

**Chanzo cha ukweli:** `includes/permissions.php` → `permissions_registry()`
(admin_roles.php huingiza kwenye DB kwa `CREATE + INSERT IGNORE`).

**Kanuni za jumla:**
- **Admin** = `has_permission()` daima `true` (full access, upande wake umefungwa read-only).
- **Staff** = anasomwa kutoka `role_permissions` (matrix); kama activity haijahifadhiwa bado → default ya registry inatumika.
- **`lock_staff=1`** (`roles.edit`) = staff **HAUWEZI** kuwa nayo hata akipewa (kuzuia self-elevation).
- **`book.room.{id}`** = ruhusa ya kila chumba (dynamically kutoka jedwali la `rooms`), default **ON** kwa admin na staff; admin anaweza kukikataa staff pekee. Booking inahitaji **mbili**: `bookings.create` (global) **NA** `book.room.{id}`.
- Staff aliye na ruhusa yoyote ya admin-area anaona **pill ya "Admin"** kwenye navbar; ndani ya hub kila box huonekana kwa permission ya page husika.

### 3.1 Matrix ya kudumu (static permissions)

| # | Module | Submodule | Code | Activity (label) | admin | staff | lock |
|---|--------|-----------|------|------------------|:---:|:---:|:---:|
| 1 | CORE | Session | `auth.login` | Can log in to the system | ✅ | ✅ | |
| 2 | CORE | Session | `auth.logout` | Can log out of the system | ✅ | ✅ | |
| 3 | CORE | Session | `auth.reset_password` | Can reset password via email | ✅ | ✅ | |
| 4 | CORE | Profile | `profile.view` | Can view own profile | ✅ | ✅ | |
| 5 | CORE | Profile | `profile.edit` | Can edit own profile | ✅ | ✅ | |
| 6 | CORE | Profile | `profile.change_password` | Can change own password | ✅ | ✅ | |
| 7 | MEETING ROOMS | Rooms | `rooms.view` | Can view meeting rooms list | ✅ | ✅ | |
| 8 | MEETING ROOMS | Rooms | `rooms.manage` | Can add / edit / delete rooms | ✅ | ❌ | |
| 9 | MEETING ROOMS | Rooms | `rooms.toggle_status` | Can activate / deactivate rooms | ✅ | ❌ | |
| 10 | MEETING ROOMS | Issues | `issues.resolve` | Can resolve reported room issues | ✅ | ❌ | |
| 11 | MEETING ROOMS | Issues | `issues.delete` | Can delete resolved room issues | ✅ | ❌ | |
| 12 | BOOKINGS | Booking | `bookings.create` | Can book a meeting room | ✅ | ✅ | |
| 13 | BOOKINGS | Booking | `bookings.view_own` | Can view own bookings | ✅ | ✅ | |
| 14 | BOOKINGS | Booking | `bookings.cancel_own` | Can cancel own booking | ✅ | ✅ | |
| 15 | BOOKINGS | Booking | `bookings.postpone_own` | Can postpone own booking | ✅ | ✅ | |
| 16 | BOOKINGS | Booking | `bookings.delete_own` | Can delete own finished booking | ✅ | ✅ | |
| 17 | BOOKINGS | Booking | `bookings.report_issue` | Can report a room issue | ✅ | ✅ | |
| 18 | BOOKINGS | Booking | `bookings.manage_all` | Can cancel / postpone / delete any booking | ✅ | ❌ | |
| 19 | SCHEDULE | Calendar | `schedule.view` | Can view the room schedule (calendar) | ✅ | ✅ | |
| 20 | SCHEDULE | Calendar | `schedule.view_all` | Can view meetings of all users on schedule | ✅ | ✅ | |
| 21 | PROJECTORS | Inventory | `projectors.view` | Can view projector inventory | ✅ | ❌ | |
| 22 | PROJECTORS | Inventory | `projectors.manage` | Can add / edit / delete projectors | ✅ | ❌ | |
| 23 | USERS | Users | `users.view` | Can view user list | ✅ | ❌ | |
| 24 | USERS | Users | `users.create` | Can create new users | ✅ | ❌ | |
| 25 | USERS | Users | `users.edit` | Can edit user details | ✅ | ❌ | |
| 26 | USERS | Users | `users.toggle_status` | Can activate / deactivate users | ✅ | ❌ | |
| 27 | USERS | Users | `users.reset_password` | Can reset user passwords | ✅ | ❌ | |
| 28 | USERS | Users | `users.delete` | Can delete users | ✅ | ❌ | |
| 29 | REPORTS | Reports | `reports.view` | Can view reports & statistics | ✅ | ❌ | |
| 30 | REPORTS | Reports | `reports.export` | Can export reports | ✅ | ❌ | |
| 31 | AUDIT LOG | Log | `audit.view` | Can view audit log entries | ✅ | ❌ | |
| 32 | AUDIT LOG | Log | `audit.clear` | Can clear audit log entries | ✅ | ❌ | |
| 33 | MAIL | Configuration | `mail.view` | Can view mail configuration | ✅ | ❌ | |
| 34 | MAIL | Configuration | `mail.edit` | Can save / test mail configuration | ✅ | ❌ | |
| 35 | ROLES | Permissions | `roles.view` | Can view roles & permissions page | ✅ | ❌ | lock |
| 36 | ROLES | Permissions | `roles.edit` | Can edit role permissions | ✅ | ❌ | **lock + lock_staff** |
| 37 | DASHBOARD | Admin | `admin.dashboard` | Can view admin dashboard | ✅ | ❌ | |
| 38 | MEETING ROOMS | Booking | `book.room.{id}` | Can book {room_name} (kila chumba) | ✅ | ✅* | dynamic, default ON |

> `*` admin anaweza kuzima kwa staff pekee (chumba kipya kinaanzia ON kwa wote).
> Ruhusa hizi za vyumba huongezewa/kufutwa **kiotomatiki** chumba kilipounganishwa/kufutwa
> (`permissions_sync_rooms()`).

---

## 4. Ramani ya Kurasa (Page Inventory)

### 4.1 Umma / Usalama (hazihitaji kuingia, isipokuwa zilizoonyeshwa)

| Page | Kazi | Ruhusa / Kizuizi |
|------|------|------------------|
| `login.php` | Kuingia (email + password) | `login_attempts`: majaribio 5 = block dk 15 |
| `register.php` | Kusajili akaunti mpya — email `@nishati.go.tz` | Hutoa status `pending` + code ya tarakimu 6 |
| `verify_email.php` | Kuweka code ya thibitisho (hatua ya 2) | Code: dk 10, max majaribio 5, resend dk 60 / max 5 kwa saa 1 |
| `forgot_password.php` | Kuomba link ya kubadilisha password | Token ya mara moja, SHA-256 hash, saa 1, max 3 kwa saa 1 |
| `reset_password.php` | Kubadilisha password kwa token | Password policy (8+, kubwa/ndogo, namba, herufi maalum) |
| `logout.php` | Kutoka (session + cookie zinaondolewa) | `csrf_url()` |
| `session_ping.php` | ICMP ya session wakati wa "Stay signed in" | Kinasasisha activity time server-side |

### 4.2 Kwa mtumiaji yeyote aliye logged-in (staff/admin)

| Page | Kazi | Permissions |
|------|------|-------------|
| `index.php` — **Home** | Orodha ya vyumba vilivyo available + **fomu ya booking**: `meeting_title`, booking_type (**Single** / **Continuous** — siku za wiki Mon–Fri + tarehe ya mwisho), tarehe (siyo iliyopita), muda wa kuanza/kumalizia, Accessories (**None/TV/Projector** + uchaguzi wa projector kutoka inventory). Ukaguzi wa mgongano wa muda + ukaguzi wa projector busy (live). | `rooms.view`, `bookings.create`, `book.room.{id}` |
| `my_bookings.php` — **My Bookings** | Order zangu: **Cancel** (siku moja / mfululizo wote kwa continuous), **Postpone** (siku moja / mfululizo — tarehe+muda mpya, reason hiari ≤255 herufi), **Delete** (postponed/cancelled/imekwisha tu, kwa confirm), **Report Issue** (uchujuzi wa chumba → email kwa admin). Hali + reason zinaonekana. | `bookings.view_own`, `bookings.cancel_own`, `bookings.postpone_own`, `bookings.delete_own`, `bookings.report_issue` |
| `ratiba.php` — **Schedule** | Kalenda ya **mwezi mzima** — chumba kimoja au vyumba vyote. Confirmed = kijani, Postponed = kuchungwa (inaonekana tarehe mpya), Cancelled haijaonyeshi. Leo = nyeupe. | `schedule.view` (+ `schedule.view_all`) |
| `profile.php` — **My Profile** | Taarifa zangu (edit), **Change Password**, na **My Activity** (matendo yangu kwenye audit log). | `profile.view`, `profile.edit`, `profile.change_password` |
| `projector_availability.php` | **JSON API** (siyo ukurasa): orodha ya projectors zilizo available/busy kwa tarehe+muda uliochaguliwa — hutumiwa na fomu ya booking. | login pekee |

Navbar kwa wote: **Home · My Bookings · Schedule (ikiwa na `schedule.view`) · My Profile · Admin (ikiwa na ruhusa yoyote ya admin-area) · Log Out · dark/light toggle.**

### 4.3 Admin hub — `admin.php` (Admin Panel)

Kadi ya Welcome + stats 4 (**Bookings today · Rooms in use · Total rooms · Open issues**) +
**hub boxes** (kila moja huonekana kwa permission):

| Box | Link | Permission |
|-----|------|-----------|
| Room Control Panel | `room_control_panel.php` | `admin.dashboard` |
| User Management | `admin_users.php` | `users.view` |
| Projector Inventory | `admin_projectors.php` | `projectors.view` |
| Reports & Stats | `admin_reports.php` | `reports.view` |
| Mail Configuration | `admin_mail_config.php` | `mail.view` |
| Audit Log | `admin_audit_log.php` | `audit.view` |
| Roles & Permissions | `admin_roles.php` | `roles.view` |

### 4.4 Admin — kina kila page

| Page | Kazi (features) | Permissions |
|------|-----------------|-------------|
| `room_control_panel.php` — **Room Control Panel** | **Reported Issues** (resolve/delete), **Add New Room** (jina, capacity, location), **All Rooms** (edit, activate/deactivate `available/maintenance`, delete), **All Bookings (50 recent)** (cancel/postpone/delete **yoyote**). | `admin.dashboard`, `rooms.manage`, `rooms.toggle_status`, `bookings.manage_all`, `issues.resolve`, `issues.delete` |
| `admin_users.php` — **User Management** | Orodha ya watumiaji; **Add user** (email `@nishati.go.tz`, role admin/staff — akaunti `active` mara moja), **toggle status**, **Delete**, kiungo kwenda reset password. | `users.view`, `users.create`, `users.toggle_status`, `users.delete` |
| `edit_user.php` | Kubadilisha taarifa za mtumiaji (jina, email, role). Kinga: admin wa mwisho hawezi kushushwa/kufutwa. | `users.edit` |
| `reset_user_password.php` | Kuweka password mpya kwa mtumiaji mwingine (policy ile ile; reset tokens za mtumiaji zinafutwa). | `users.reset_password` |
| `admin_projectors.php` — **Projector Inventory** | Orodha ya projectors; add/edit/delete, activate/deactivate (`active/inactive`). | `projectors.view`, `projectors.manage` |
| `admin_reports.php` — **Reports & Statistics** | Kadi 9: Total Bookings, Confirmed, Postponed, Cancelled, This Month, **Utilization % (month)**, Active Rooms, Active Users, Active Projectors. Vitabu: **Room Usage**, **Bookings by Staff**, **Projector Usage**, **Monthly Trend (last 12 months)**. | `reports.view` (+ `reports.export` kwa export) |
| `export.php` | Kupakua ripoti (CSV/Excel). | `reports.export` |
| `admin_audit_log.php` — **Audit Log** | Kila kitendo (login, booking, admin actions, ACCESS_DENIED, CSRF_BLOCKED n.k.); filters (action/user), pagination, **Export CSV**, **Clear Log**. | `audit.view`, `audit.clear` |
| `admin_mail_config.php` — **Mail Configuration** | SMTP host/port/user/from + enabled on/off + **Send test email**. Hifadhi kwenye `mail_settings`. | `mail.view`, `mail.edit` |
| `admin_roles.php` — **Roles & Permissions** | **Matrix ya role × activity** (admin column LOCKED read-only; `roles.edit` haipewi staff — `lock_staff`). Tick → **Save Changes** (hutumika mara moja, server-side). | `roles.view`, `roles.edit` |

---

## 5. Workflows Kuu

### 5.1 Booking (index.php)
1. Mtumiaji anachagua chumba → anaaza fomu (hufunikwa kwa chumba kimoja).
2. Aina: **Single** (tarehe moja) au **Continuous** (siku za wiki Mon–Fri kuanzia tarehe → `booking_end_date`; kila siku hupata `series_id` moja).
3. Accessories: None / TV / **Projector** — ukiwa chagua projector, `projector_availability.php` hulisha orodha ya zilizo busy/free.
4. Server hukagua: mgongano wa muda kwenye chumba (ukaguzi wa start<end, tarehe siyo iliyopita), availability ya projector, `bookings.create` + `book.room.{id}`.
5. Mafanikio → hali `confirmed`, email ya taarifa kwa mtumiaji, audit log `BOOKING_CREATED`.

### 5.2 Postpone / Cancel / Delete (my_bookings.php)
- **Postpone**: hali → `postponed` + `postponed_date/start/end` (+ reason). Muda wa zamani huachia chumba huru. Siku moja au mfululizo. Inaonekana kwenye ratiba tarehe **mpya**.
- **Cancel**: hali → `cancelled` (siku moja au mfululizo wote kwa continuous). Confirm dialog inayotofautiana ("Cancel This Day?" vs "Cancel Entire Series?").
- **Delete**: kwa bookings `postponed`/`cancelled`/imekwisha tu; `bookings.delete_own` (staff mwenyewe) au `bookings.manage_all` (admin).
- Admin anaweza kuposponda/kufuta booking **yoyote** kutoka Room Control Panel.

### 5.3 Report Issue
Kutoka booking hai → ujumbe wa tatizo → `room_issues.status=open` + email kwa admin.
Admin: **Resolve** (`issues.resolve`, hali → `resolved`) au **Delete** (`issues.delete`, mtu aweza kufuta iliyorekebishwa).

### 5.4 Users lifecycle
Register (`pending`) → email code (dk 10, max 5 majaribio) → `active`.
Admin: add (moja kwa moja `active`), edit, toggle active/inactive, reset password, delete.
Kinga: admin wa mwisho ACTIVE hushushwa/kufutwa = **blocked** (`sole_admin_demote_blocked` / `is_last_active_admin`).

### 5.5 Email za kumbusho (Cron)
`cron/send_reminders.php` (CLI pekee — browser = 403): order za **kesho** saa **18:00**;
hakuna marudio (`reminder_log` UNIQUE `booking_id + for_date`); `cancelled` na watumiaji wasiohai havipewi.

Windows Task Scheduler: create a daily trigger for **18:00**; set **Program/script**
to the full path to `php.exe` and **Arguments** to
`"<project-path>\cron\send_reminders.php"` (replace `<project-path>` with the
actual folder where this project is installed).
Linux cron: `0 18 * * * php /path/to/project/cron/send_reminders.php`

### 5.6 Roles matrix (admin_roles.php)
Admin aingia → admin.php → Roles & Permissions → tick/kondoa → **Save Changes**
→ `role_permissions` inasasishwa + audit `PERMISSIONS_UPDATED` + flash.
Mabadiliko yanatumika **mara moja** (page load inasoma DB; hakuna logout inayohitajika).

---

## 6. Hali za Data (Enums)

| Jedwali | Hali |
|---------|------|
| `users.status` | `pending` · `active` · `inactive` |
| `users.role` | `admin` · `staff` |
| `rooms.status` | `available` · `maintenance` |
| `bookings.status` | `confirmed` · `postponed` · `cancelled` |
| `bookings.Accessories` | `none` · `tv` · `projector` |
| `projectors.status` | `active` · `inactive` |
| `room_issues.status` | `open` · `resolved` |

---

## 7. Database

**Jedwali 13:** `users`, `email_verifications`, `rooms`, `bookings`, `password_resets`,
`room_issues`, `projectors`, `audit_log`, `reminder_log`, `login_attempts`,
`mail_settings`, `permissions`, `role_permissions`.

**Faili za SQL:**
- `database/schema.sql` — install safi (structure + data ya mfano; jedwali ZOTE 13)
- `database/full.sql` — dump nzima (structure + data) kwa kuhamisha database
- `database/updates/update.sql` — kusasisha DB iliyopo bila kupoteza data
- `database/updates/update_roles.sql` — kuongeza `permissions` + `role_permissions` kwa installi za zamani
- `database/updates/update_mail.sql` — kuongeza `mail_settings`

**Data ya mfano:** admin `admin@ofisi.co.tz` / `admin123`; vyumba 3 (Meeting Room A,
Meeting Room B, Training Hall); projectors 3 (Epson EB-X06, Ben TH585, Canon LV-X300).

---

## 8. Usimikaji (Install)

**Install mpya:**
1. Weka folder ya mradi ndani ya `htdocs` (XAMPP) au `www` (WAMP).
2. phpMyAdmin → import `database/schema.sql` (au `database/full.sql` kurejesha dump nzima).
3. Sanidi `includes/config.php` (jina la DB, mtumiaji, password).
4. Fungua `http://localhost/<project-folder>/login.php`, ukibadilisha `<project-folder>`
   kwa jina halisi la folder ndani ya web root.

**Update ya mfumo uliosimikwa:**
1. Badilisha faili mpya (pamoja na `includes/security.php`, `includes/permissions.php`,
   `includes/navbar.php`, folder `vendor/phpmailer`).
2. Endesha `database/updates/update.sql` (+ `database/updates/update_roles.sql` /
   `database/updates/update_mail.sql` kama hazijaendeshwa) — **siyo** `database/schema.sql`
   (ila usifute data).

**Email (SMTP):**
1. `includes/mail_config.php` → weka SMTP.
2. Password ya SMTP = **environment variable** `MRS_SMTP_PASSWORD` (inapendekezwa).
3. `'enabled' => true` (auto-husha ikiwa password haipo).
4. Njia mbadala: Admin → **Mail Configuration** (ukurasa wa mfumo).

> ⚠️ **USALAMA WA PASSWORD:** Usiwahi kuandika App Password kwenye faili itakayoshirikiwa
> (Git, backup, email). Ikiwa imeonekana mahali popote, **badilisha mara moja**:
> Google Account → Security → 2-Step Verification → App Passwords.

---

## 9. Ulinzi wa Usalama (Muhtasari)

Kwa undani zaidi: **`SECURITY_REPORT.md`**.

- **CSRF token** kwenye kila fomu na kitendo (POST na GET) — msingi 403 + audit `CSRF_BLOCKED`.
- **Security headers**: X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
  X-XSS-Protection, Permissions-Policy, COOP, HSTS (HTTPS).
- **Brute force**: `login_attempts` — majaribio 5 / saa 24 = block **dakika 15**
  (kwa email au IP); audit `LOGIN_FAILED`/`LOGIN_LOCKED`; password reset max 3 kwa saa 1.
- **User enumeration**: ujumbe mmoja tu login/reset ("Invalid email or password.").
- **Session salama**: strict mode, httponly, SameSite=Lax; `session_regenerate_id()` baada ya login;
  idle **dakika 10** (popup ya onyo dakika 9 + ping) au **masaa 8** jumla; logout huondoa cookie pia;
  **role husomwa kutoka DB kila mara** (admin aliyeshushwa hapotezi access baada ya login mpya).
- **Password policy** (8+, kubwa/ndogo, namba, herufi maalum, dictionary check) kwenye register,
  admin reset, na self change.
- **Thibitisho wa email**: code 6 tarakimu, **hash** DB (siyo plaintext), dk 10, max 5 majaribio,
  resend dk 60 / max 5 kwa saa 1, mara moja tu; email imefichwa `mt***@domain`.
- **Token za reset**: SHA-256 hash, mara moja, saa 1; zinafutwa pale password inapobadilika.
- **RBAC**: `has_permission()` + `require_permission()` (403/redirect + audit `ACCESS_DENIED`);
  admin full-access daima; `roles.edit` = `lock_staff` (staff haiwezi kujipa ruhusa).
- **Kinga za admin**: admin wa mwisho ACTIVE hushushwa/kufutwa = blocked.
- Password bcrypt + auto-rehash; prepared statements kila mahali; `htmlspecialchars()` output;
  cron CLI peียว.
- **Audit log** ya kila kitendo: `USER_REGISTERED`, `LOGIN_*`, `EMAIL_VERIFY_*`,
  `BOOKING_*`, `ROOM_*`, `USER_*`, `PERMISSIONS_UPDATED`, `ACCESS_DENIED`, `CSRF_BLOCKED`, n.k.

---

## 10. Muundo wa Faili

```
mrs/
├── index.php                  # Home: vyumba + fomu ya booking
├── my_bookings.php            # Order zangu: cancel/postpone/delete/report issue
├── ratiba.php                 # Kalenda ya ratiba (mwezi mmoja)
├── profile.php                # Wasifu + change password + My Activity
├── projector_availability.php # JSON API: projectors free/busy
├── login.php / register.php / logout.php
├── verify_email.php           # Kuweka code ya thibitisho
├── forgot_password.php / reset_password.php
├── session_ping.php           # Ping ya session ("Stay signed in")
├── admin.php                  # Admin hub (stats + boxes)
├── room_control_panel.php     # Rooms, bookings zote, issues
├── admin_users.php / edit_user.php / reset_user_password.php
├── admin_projectors.php
├── admin_reports.php / export.php
├── admin_audit_log.php
├── admin_mail_config.php
├── admin_roles.php            # Roles & permissions matrix
├── cron/send_reminders.php    # Kumbusho 18:00 (CLI pekee)
├── includes/
│   ├── config.php             # DB connection + mimitiko
│   ├── auth.php               # check_login + role kutoka DB
│   ├── permissions.php        # REGISTRY + has_permission/require_permission ★
│   ├── security.php           # CSRF, headers, login limit, session, password policy
│   ├── booking_rules.php      # Kanuni za booking/mgongano/projector busy
│   ├── verification.php       # Code ya email thibitisho
│   ├── mail_config.php / mailer.php
│   └── navbar.php             # Nav + session timeout JS + MRS_CSRF
├── assets/css/style.css, assets/js/ (theme.js, session_timeout.js, n.k.)
├── vendor/phpmailer/
├── database/schema.sql, database/full.sql, database/updates/*.sql
└── README.md / SECURITY_REPORT.md
```

---

## 11. Muhtasari wa Vipengele (kwa User Manual)

- Ukaguzi wa **mgongano wa muda**: chumba hakiwezi kupewa booking mbili zinazoingiliana.
- **Single** na **continuous** bookings (series); cancel/postpone kwa siku moja au mfululizo.
- Booking inaonyesha hali 3: **Confirmed** (kijani), **Postponed** (kuchungwa, tarehe mpya + reason),
  **Cancelled**; order iliyofutwa/imekwisha tu inafutwa.
- **Kalenda ya ratiba** ya mwezi mzima kwa chumba kimoja au vyumba vyote.
- **Projector inventory** + kuashiria projector kwenye booking (availability live).
- **Report issue** → admin aresolve/delete.
- **Email**: thibitisho ya usajili, taarifa ya booking, kumbusho la kesho (cron 18:00).
- **Reports & stats** + export; **audit log** kamili + CSV + clear.
- **Roles & permissions matrix** (admin full access, staff kwa ruhusa, lock za usalama).
- **Dark mode**, icons SVG, design responsive.
- Session timeout yenye popup ya onyo (dk 9) na chaguo "Stay signed in".

---

## 12. Madokezo kwa Mtengenezaji wa User Manual & User Matrix

1. **User Matrix** — tumia Jedwali la Sehemu 3 (§3.1) kama msingi; onyesha pia `book.room.{id}`
   (§3, maelezo) na utafsiri "✅/❌" kuwa Yes/No kwa kila role. Admin = Always Yes (locked).
2. **User Manual** — fuata muundo wa Sehemu 4 (§4.1–§4.4): kila ukurasa = lengo, hatua za matumizi,
   permissions zinazohitajika, na outcomes (email/audit/flash). Sehemu 5 (§5) ni workflows za hatua kwa hatua.
3. Hali zote za data (§6) na kanuni za usalama (§9) ni sehemu ya manual (maelezo ya "what happens if…").
4. Chanzo cha ukweli cha code: `includes/permissions.php` (registry), `includes/navbar.php`
   (menu visibility), `admin.php` (hub boxes), `SECURITY_REPORT.md` (usalama).
