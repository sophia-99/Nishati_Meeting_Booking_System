# MRS — General Security Report

Tarehe: 29/09/2026 · Chanzo: project root (PHP + MySQL/MariaDB, session-based auth)
Lengo: mpango kamili wa usalama wa mfumo huu ili uweze kujenga/upya ulinzi huo ule ule kwenye mfumo mpya.

**Namba zote kwenye (…) ni `faili:mstari` kwenye MRS ya sasa — zibadilishe kulingana na mfumo mpya.**

---

## 1. Muhtasari wa tabaka (Security Layers)

| # | Tabaka | Hali | Mahali pa MRS |
|---|--------|------|----------------|
| 1 | HTTP security headers | Imetekelezwa | `includes/security.php:19-48` |
| 2 | Client IP thabiti (trust proxy) | Imetekelezwa | `includes/security.php:53-74` |
| 3 | CSRF (token kwa kila POST) | Imetekelezwa (40 call sites) | `includes/security.php:83-152` |
| 4 | Session hardening (cookie, rotate, idle+absolute) | Imetekelezwa | `includes/security.php:388-470` |
| 5 | Login lockout (mjaribio) | Imetekelezwa | `includes/security.php:159-196` |
| 6 | Password policy + hashing | Imetekelezwa | `includes/security.php:300-320`, `auth.php` |
| 7 | AuthN flow (login/2FA-lite/OTP reset) | Imetekelezwa | `login.php`, `includes/verification.php` |
| 8 | AuthZ (permission registry 43 + role_permission 86) | Imetekelezwa | `includes/permissions.php` |
| 9 | Audit trail (51 actions) | Imetekelezwa | `includes/audit.php` |
| 10 | Input/SQL (prepared statements) + output escaping | Imetekelezwa | kwenye kila controller |
| 11 | Filesystem/infra (`.htaccess`, includes guard) | Imetekelezwa | `.htaccess` |
| 12 | Encrypt email OTP (AES-256-GCM) | Imetekelezwa | `includes/mail_crypto.php` |
| 13 | Client-side (double-submit guard, session popup) | Imetekelezwa | `assets/js/ui.js`, `session_timeout.js` |

Hakuna CSP, hakuna 2FA ya kweli, hakuna rate-limit ya maombi — ona Sehemu 8 (Gaps).

---

## 2. Ramani ya faili (Security-relevant files)

```
includes/security.php     ← kiini: headers, IP, CSRF, lockout, password, session, 403
includes/auth.php         ← check_login(), check_admin($perm), status gating
includes/permissions.php  ← registry (43), has_permission/require_permission, room perms, last-admin guard
includes/audit.php        ← audit_log(action, detail) + client_ip()
includes/verification.php ← OTP: send/verify, TTL, cooldown, attempt caps
includes/mail_crypto.php  ← OTP luge: AES-256-CBC + HMAC (encrypt-then-MAC)
includes/db.php           ← mysqli connection (prepared statements used throughout)
includes/navbar.php       ← session config → JS (MRS_SESSION), logout link
session_ping.php          ← idle timer ping (JSON), 401/302 expired
logout.php                ← session destroy + audit + `?expired=` reason
login.php / register.php / forgot_password.php / reset_password.php
.cron, cron/              ← session GC + booking expiry (CLI only)
.htaccess                 ← block sql/md/log/bak/… , deny includes/, -Indexes
database/schema.sql       ← schema: users, permissions, role_permissions, login_attempts, audit_log, …
```

---

## 3. Kipengele kwa kipengele (undani + kanuni za kuhama)

### 3.1 HTTP security headers — `security.php:19-48`
Inafanya nini: `sec_headers()` hupiga kikogo cha kila majibu:
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `X-XSS-Protection: 0`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`,
`Cache-Control: no-store, no-cache, must-revalidate` kwenye kurasa za auth.
Inavyofanya kazi: hudumiwa moja kwa moja kutoka `includes/db.php`/bootstrap (kila response).
**Kuhamisha:** piga function moja kwenye boot ya mfumo mpya (middleware). Usisahau `no-store` kwenye login/reset pages.

### 3.2 Trust proxy / client IP — `security.php:53-74`
Kanuni: `X-Forwarded-For` inatumika **tu** kama remote addr ni `127.0.0.1|::1|10.*|192.168.*|172.16-31.*` (private ranges). Vinginevyo `REMOTE_ADDR`. Hii inazuia spoofing ya IP kwa lockout/audit.
**Kuhamisha:** hii ndiyo kanuni muhimu kwa IP-based controls; tembelea headers **hapa hapa** (login lockout, audit, session).
Umuhimu: lockout na audit vinategemea IP sahihi.

### 3.3 CSRF — `security.php:83-152` (40 call sites)
- Token: `hash_equals` ya random 64-char hex; disa wrapper (`csrf_token()`, `csrf_field()`).
- `csrf_verify()` huitwa kabla ya kila POST/idhini (40 mahali). Ushindani wa `token_verify` → ukurasa 403.
- Ukurasa wa 403 (line 123+) unatumia muundo wa mfumo ule ule wa login (crest, `alert-error`, `theme.js`), ujumbe wa Kiingereza na **link ya kurudi**.
- Front-end: kila `fetch`/form POST hupokea token kupitia `window.MRS_CSRF`/form field; `assets/js/ui.js` double-submit guard (~410+).
- **MAKINI:** `htmlspecialchars()` ndani ya `<script>` haifanyi entity-decode → CSS token ilikuwa `&amp;csrf_token` = 403. **Kanuni: daima tumia `json_encode($x, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)` ndani ya `<script>`.**
- **Kuhamisha:** middleware-ya-wote-WAF-ya-duniani: kila maombi ya POST/PUT/DELETE (isipokuwa endpoints za login/register/OTP zilizo-thibitishwa) lazima athibitishe token ya session-scopetya; token = `hash_equals` (si `==`).

### 3.4 Session hardening — `security.php:388-470`
Kanuni na namba:
| Kipengele | Thamani | Mstari |
|---|---|---|
| Session name | `MRSSESSID` (si thamani ya PHP ya kawaida) | ~422 |
| Cookie params | httponly=true, samesite=Lax, secure kwa https | 413 |
| `use_strict_mode`, `use_only_cookies` | true | 407-408 |
| Idle limit | `SESSION_IDLE_LIMIT = 600s` (dk 10) | 441 |
| Idle warning | 540s (popup ya dk 45 kabla) | session_timeout.js |
| Absolute limit | 8h (`SESSION_ABSOLUTE_LIMIT`) | 441+ |
| Regenerate ID | kila `last_regen` > 30min + kila login | 429, 431 |
| `check_login()` guard | status=active, role kwa kila request, `last_activity`, `session_guard` | auth.php |
Mbinu: kila request inasasisha `last_activity`; `session_guard`/ping hubadilisha jukwaa/UA → logout.
**Kuhamisha:** piga guard moja kwenye middleware (si kwenye kila ukurasa); tumia `session_regenerate_id(true)` kila login; `hash_equals` kwa identifiers.

### 3.5 Session control (UI) — `session_ping.php` + `assets/js/session_timeout.js`
- `session_ping.php`: JSON `{ok, idle_limit:600, warn_at:540, remaining, time}`; bila session → 401; imeisha → 302 `login?expired=1`.
- JS: activity events (mousemove/mousedown/keydown/wheel/touchstart/scroll/click) → ping ≥5s; popup `#mrsIdleModal` na countdown; "Stay signed in" / "Sign out now"; overlay haitolei kwa bonyezi la nje; countdown=0 → logout.
- Navbar (`navbar.php:132-148`) hutoa `window.MRS_SESSION {idleLimit, warnAt, pingUrl, logoutUrl}` kwa `json_encode(..., JSON_HEX_*)`.
- `logout.php`: sababu ya kuondoka (`expired`) huhifadhiwa kwenye audit.
**Kuhamisha:** hesabu ya muda iwe server-side (kumbuka: JS si ukweli); JS ni onyo tu.

### 3.6 Login lockout — `security.php:159-196`
Kanuni: `LOGIN_MAX_ATTEMPTS = 5` ndani ya `LOGIN_LOCKOUT_MINUTES = 15` (dirisha la siku 1); hesabu ndani ya DB `login_attempts` (fail + reset kwa login salama). Ombi la majaribio zaidi → ujumbe wa muda uliosalia.
**Kuhamisha:** cooldown kwa (IP + username) na pia kwa username pekee — unaweza kuhamisha kanuni ile ile ile kwa `f(x)` ya majaribio.

### 3.7 Password policy — `security.php:300-320`
- `password_hash(..., PASSWORD_DEFAULT)` (bcrypt) — hakuna juu ya DB.
- Min length 8+; lazima herufi kubwa **na** ndogo (aina: `[A-Za-z]` + idadi + alama).
- Check fupi tu: `[A-Za-z]` (line 307) + idadi ya digit — **JINA LA README** linasema "uppercase required"; **sema: kipimo kikubwa = min-length + mixed-case + digit** — hakikisheni katika mfumo mpya.
- **Kuhamisha:** `password_verify()` pekee; usihifadhi raw; ukurasa wa reset unapaswa kuregenrate session.
- Password reset: token ya random + hash kwenye DB + TTL (Jina la README: dakika 10/24h), `password_resets` table.

### 3.8 AuthN flow (login/OTP/reset) — `login.php`, `register.php`, `forgot_password.php`, `includes/verification.php`
- Register: email verification (OTP), isipokuwa admin ame-create acct.
- **OTP constants (`verification.php:38-44`):** `VERIFY_TTL_MINUTES=10`, `VERIFY_MAX_ATTEMPTS=5`, `VERIFY_COOLDOWN_SECONDS=60`, `VERIFY_MAX_SENDS_PER_HOUR=5`.
- OTP hash-limeo kwenye `users`/`email_verifications` (si plaintext).
- **`mail_crypto.php:48-70`:** OTP/links zinafungwa `aes-256-gcm` (AEAD + tag; `base64(iv || tag || ciphertext)`), key = `sha256` ya auto-generated mail key (`mail_key_bin()`, line 44) → DB leak haionyeshi token.
- `reset_user_password.php` (admin-initiated) ina `require_permission('users.edit')`.
**Kuhamisha:** rate-limit per-IP kwa forgot-password (hakuna hapa! ona Gaps), hash token kabla ya kuweka DB, token moja + ttl.

### 3.9 AuthZ — `includes/permissions.php`
- Registry: **43 permissions** (`role_permissions`=86 = 2 roles × ~43).
- `has_permission($perm)` → session-yale uliyofaa + db yako; `require_permission()` → **403 page** (au redirect login kama hayapo).
- Room-level: `book.room.N` per-room permissions.
- `is_last_active_admin()` — kuzuia admin wa mwisho aondoe yenyewe.
- Roles: admin vs staff (staff hupata badge "Staff Member" kwenye navbar).
**Kuhamisha:** kagua nzima katika middleware (si kwenye view); tumia allowlist, si blocklist; fungua dashboard/landing kama 403 iliyotengenezwa vizuri kunaonekana kama login page (test imekagua hili).

### 3.10 Audit — `includes/audit.php` (51 actions)
`audit_log(action, detail)` → `audit_log` table + `client_ip()`.
Muhimu: login success/fail + logout + `session_expired`, permission change, user create/delete, room/reservation CRUD, OTP send/verify.
**Kuhamisha:** hifadhi: wakati (UTC), user_id, ip, action, entity_id, detail; **andika kabla ya commit** kwa operations muhimu; jaribu `SELECT COUNT(*)` + actions za kipimo (51) kabla/serial.

### 3.11 SQL/authorization + output
- Prepared statements mahali popote (mysqli); hakuna concatenation ya input kwenye SQL (ingawa kumbuka: `IN (?)` + `implode` mahali kadhaa).
- XKT: huduma za `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')`; JS ndani ya `<script>` via `json_encode(JSON_HEX_*)`.
- `register.php`/forms hupendekeza `filter_input` kwa email.
**Kuhamisha:** ORM/parameter binding **daima**; escape kwa charset sahihi; ongeza CSP kuongeza ulinzi wa ziada.

### 3.12 Infra — `.htaccess`
```
deny .sql .md .log .bak .old .orig .ini .env .sh .git
Options -Indexes
RewriteRule ^includes/ - [F,L]
```
+ kila faili ya `includes/*` ina direct-access guard (`defined('MRS') || http_response_code(403)`).
`cron/` = CLI-only (session GC + booking expiry) — kumbuka katika mfumo mpya: usiruhusu cron iwepo kwenye www yenyewe.
**Kuhamisha:** porting ya `.htaccess` → Nginx `location` block / app middleware; public dir lazima iwe na md/ini/log zilizozuiwa.

### 3.13 Client-side safety — `assets/js/ui.js`
- Double-submit guard (line ~410+): kubonyeza mara mbili haifanyi ombi mara mbili.
- CSRF token ndani ya kila form (kama ilivyotajwa).
- Datepickers: `From Date` (startCal) = si hifadhi + lazima weekday, `To Date` = huru (`isAnyDay: true`) — kwa ajili ya booking rule (rusu).
- **Kuhamisha:** JS guard = UX tu; tibitisha upya server.

---

## 4. Mtiririko wa mtumiaji (kwa porting)

1. **Login:** `login.php` → `csrf_verify` → `check_password` → lockout check (IP + user) → `session_regenerate_id(true)` → `check_login()` future = `$_SESSION['user_id']` + role/permission snapshot → audit `login_success` → redirect dashboard.
2. **Idle:** JS ping (`session_ping.php`) ≥5s ↔ server updated `last_activity`; >600s → logout + 302 `login?expired=1`.
3. **Register:** user creates → OTP (10dk, 5 jaribio, cooldown 60s, max 5/sa) → verify → activate.
4. **Forgot password:** email/token (hashed, TTL) → reset → token invalidated + session cleanup + audit.
5. **Page load:** `sec_headers()` → `check_login()` (auth) → `require_permission(perm)` (authz) → controller (prepared SQL) → view (escape).
6. **POST:** `csrf_verify()` → owner/admin check → `permissions_check` → validate → prepared write → `audit_log()` → redirect (PRG).
7. **Cron:** CLI-only, session GC + expired booking state machine (race guarded by conditional UPDATE).

---

## 5. Thibitisha/verify (testi zilizopo kwenye MRS — kisha zibadilishe)

| Testi | Inachukua |
|---|---|
| `sprint1-4_test.php` | headers, session, CSRF, audit, permissions, login lockout (37+43) |
| `session_timeout_test.php` | idle 600s, ping, expired, logoutUrl (39) |
| `roles_http_test.php` / `badge_role_test.php` | role display + 403 (12 + 10) |
| `todate_calendar_test.php` | To Date huru, From restricted (20, headless Edge) |
| `navbar_brand_test.php` | navbar branding |

Zote zina `PHP` CLI + server port (18095). Katika mfumo mpya: andika testi zinazolingana kila kipengele (hasa CSRF + lockout + session expiry + permission 403 + OTP caps).

---

## 6. Orodha ya ukaguzi (Porting order — fuata mpangilio huu)

1. **Boot/middleware:** `sec_headers()` + trusted-proxy `client_ip()` + session hardening (name, strict mode, cookie flags, regen).
2. **AuthN:** password hash/verify + login + lockout + status gating + session-guard (UA/platform).
3. **CSRF:** token per session, `hash_equals`, verify on every state-changing request; JSON-encode lile script config.
4. **AuthZ:** permission table (allowlist) + role→permission mapping + room-level perms + `require_permission` + 403 page (yenye muundo wa mfumo).
5. **Session UX:** idle timer server-side + ping + countdown popup (si lazima sawa kwa sawa, lakini kweli = server).
6. **Flows salama:** register+OTP (TTL/caps), password reset (hashed token + TTL), admin reset (`users.edit`).
7. **Input/SQL/output:** prepared statements, escape/`json_encode(JSON_HEX_*)`, muda/role validation server-side.
8. **Audit:** `audit_log` schema + kila kitendo muhimu; nenda lui per permission change.
9. **Ops/infra:** block `.sql/.md/.log/…`, `-Indexes`, deny internals, cron CLI-only, disk backups kwa DB + code.
10. **Crypto (optional):** encrypt sensitive reset/OTP columns kama DB leak isionyeshe token.
11. **Uthibitisho:** testi za CSRF 403, lockout, session expiry, 403 authz, OTP caps — + manual pentest orodha ndogo (naona sehemu 8).

---

## 7. Gaps (hakuna — na mapendekezo)

| Gap | Hatari | Pendekezo |
|---|---|---|
| **Hakuna CSP** | XSS ya ziada haijazuiwa | Tengeneza strict CSP (script-src 'self'; frame-ancestors 'self'; object-src 'none') |
| **Hakuna 2FA/TOTP** | Credential theft | TOTP kwa admin + `password_reset` sessions |
| **Login lockout = IP+user pekee** | Account lockout DoS kwa user | Add per-IP failure counter + captcha + generic error messages |
| **DB root = password tupu** (XAMPP default) | Uharibifu kamini | DB user maalum (haki za read/write tu), password nguvu, isipokuwa localhost |
| **`SESSION_COOKIE_SECURE`** | Cookie ya kwanza ikiwa HTTP = intercept | `Secure=true` kwenye prod (HTTPS); localhost test = off |
| **Email/OTP reset token**: honeypot/rate-limit ya forgot-password per-IP | Spam/kupuuza user | Rate-limit per-IP + hCaptcha kwenye forgot-password |
| **Audit haijui DB transaction** | Uandishi wa audit unaweza kuwa approved huku write ikishindikana | Andika audit baada ya commit ya DB (au transaction yenyewe) |
| **Login error message generic + "username not found"** | User enumeration | Sema "invalid credentials" pekee kwa login + reset |
| **File upload havetengenezwi?** | — | Thibitisha hakuna upload endpoint; kama kuna: type/size/extension + rename + store nje ya www |
| **`cache` ya audit** | `audit_log` ina 155 rows (sawa), lakini kuna uhakika wa backpressure kubwa | Alerts/retention policy kwa `audit_log` |
| **Test DB ya prod data?** | Leak ya user data | Separete prod/test; isipokuwa na ipo kwenye repo (hakuna hapa) |

---

## 8. Kiambatisho A — Kanuni za namba (Session/PW/OTP/Lockout)

```
SESSION_IDLE_LIMIT    = 600s  (10 dk)      security.php:441
SESSION_IDLE_WARNING  = 540s  (popup 45s)  session_timeout.js
SESSION_ABSOLUTE      = 8h                  security.php:441+
SESSION_NAME          = 'MRSSESSID'         ~422
LOGIN_MAX_ATTEMPTS    = 5                   security.php:161
LOGIN_LOCKOUT         = 15 dk, dirisha 24h security.php:159-163
PASSWORD_MIN_LENGTH   = 8, +mixed, +digit   security.php:300-320
VERIFY_TTL_MINUTES    = 10                  verification.php:38
VERIFY_MAX_ATTEMPTS   = 5                   verification.php:40
VERIFY_COOLDOWN       = 60s                 verification.php:42
VERIFY_MAX_SENDS/HR   = 5                   verification.php:44
```

## 9. Kiambatisho B — Orodha ya audit actions (51)
Mfumo huandika: `login_success`, `login_failed`, `logout`, `session_expired`, `permission_granted/revoked`, `user_created/deleted`, `role_changed`, `room_created/updated`, `reservation_created/cancelled`, `reset_token_issued`, `otp_sent/verified`, … + `ip` + `time_utc` kwa kila kimoja.

## 10. Kiambatisho C — Scope ya security review (mfumo mzima)

- **Inashughulikiwa:** HTTP headers, trusted-proxy IP, CSRF, session lifecycle + UI timeout, login lockout, password policy, OTP flows, permission system, audit, SQL injection, XSS/output escaping, file/infra (.htaccess), encryption ya OTP.
- **Haijajadiliwa kwa undani:** WAF/IPS, DDoS, HTTPS termination, DB backup encryption, penetration testing, dependency (composer/npm) audit, GDPR retention — ongeza kwenye mfumo mpya.

---

*Ripoti hii imejengwa kutoka kwenye inventory + uthibitisho wa moja kwa moja (`security.php`, `permissions.php`, `audit.php`, `.htaccess`, DB counts: permissions=43, role_permissions=86, audit_log=155). Ili kuhakiki upya: `sprint1-4_test.php` + `session_timeout_test.php`.*
