# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Nastari** — a WhatsApp HR self-service chatbot for KPN Corporation, built on Laravel 12 / PHP 8.2. It has two independent entry points that share the same service layer and database:

1. **WhatsApp bot** (`routes/api.php` → `WebhookController`) — employees chat with Nastari over the WhatsApp Cloud API to read their HR data, generate letters, approve leave, ask policy questions, and vent. No auth; identity is the sender's phone number.
2. **Admin web panel** (`routes/web.php`) — HC staff log in via Darwinbox SSO to see dashboards, manage helpdesk tickets, the policy knowledge base, and role scopes. Blade + Bootstrap 5.

All user-facing copy is Indonesian (with English variants for visa letters). Keep new bot/UI strings in Indonesian and match the existing emoji-heavy, informal tone.

# Project Rules

## Security

Never access, read, print, modify, or expose:

- .env
- .env.*
- *.pem
- *.key
- credentials.json
- secrets.json
- ~/.ssh
- ~/.aws
- ~/.config

Never run commands intended to discover secrets or credentials.

If a task requires a secret, stop and ask me first.

Do not expose secrets in:
- chat responses
- logs
- generated files
- commits

## Commands

```bash
composer setup                       # install, .env, key:generate, migrate, npm install+build
composer dev                         # serve + queue:listen + pail + vite, all concurrently
composer test                        # config:clear then artisan test
php artisan test --filter=NameOfTest # single test
./vendor/bin/pint                    # formatter (dev dep; no composer script wired up)

php artisan migrate                       # default 'mysql' connection only — see "Two databases"
php artisan chat:analyze-weekly --force   # Gemini weekly chat analysis; scheduled Sat 06:00
php artisan nastari:ingest-logs           # logs -> nastari_events; scheduled every 10 min
php artisan nastari:ingest-logs --fresh   # rebuild the log-derived events (keeps live rows)
php artisan nastari:scan-logs             # laravel*.log ERROR+ -> nastari_log_entries; every 10 min
php artisan nastari:scan-logs --fresh     # re-read every log file from byte 0
php artisan pail                          # tail logs

php artisan nastari:sync-employees --dry-run   # preview the daily usernastari <- employees reconciliation
php artisan nastari:sync-employees --force     # run it, ignoring the 20h cooldown; scheduled daily 02:00
php artisan nastari:optimize-letterheads       # one-off: downscale oversized kop surat (see "Letter generation")
php artisan nastari:prune-letters --dry-run    # delete generated PDFs past retention; scheduled Sun 03:00
```

`GET /tasks/employee-sync?token=<TASK_RUNNER_TOKEN>` runs the same sync over HTTP, for
deployments where cron does not run `schedule:run`. Rate limited to one call per hour;
returns 404 when the token is unset.

Tests cover the business rules that would cause real damage if broken: the
active/inactive access policy (`tests/Unit/EmployeeAccessPolicyTest.php`), the letterhead
memory guard (`tests/Feature/LetterheadResolverTest.php`), the employee sync's idempotency
and non-destructive field rules (`tests/Feature/EmployeeSyncTest.php`), and activity-log
classification plus credential redaction (`tests/Feature/NastariActivityLoggerTest.php`),
the dashboard metrics that would otherwise lie without looking broken — conditional
aggregates, the active/inactive split, pagination clamping, BU scope and Ruang status
(`tests/Feature/DashboardMetricsTest.php`) — and the log scanner's redaction, incremental
reads and rotation handling (`tests/Feature/LogScannerTest.php`), and the Pandu
question/answer history — log recovery, hanging questions, idempotency and exact-match
ticket linking (`tests/Feature/PanduQaTest.php`).
106 tests, run with `php artisan test`. Note `composer install --no-dev` leaves PHPUnit
absent; run a plain `composer install` first. `EmployeeSyncTest` and `DashboardMetricsTest`
point both the default and `kpncorp` connections at one temporary SQLite file, because a
per-connection `:memory:` database is not shared. Everything else is still uncovered —
exercise the webhook or admin routes for those.

`npm run build` is effectively unnecessary: the Vite/Tailwind pipeline is stock skeleton and only `welcome.blade.php` (dead) calls `@vite`. The real admin UI (`resources/views/layouts/admin.blade.php`) pulls Bootstrap 5, jQuery, and Select2 from CDN. Build admin UI with Bootstrap classes and jQuery, not Tailwind.

## Architecture

### Conversation engine (the WhatsApp side)

`WebhookController::handle()` is the single entry point for every inbound message:

1. Dedupe on `message.id` via a 60s cache key, log a `WA_INCOMING` line, mark read.
2. Look up `UserNastari` by `whatsapp_number`. Unknown numbers trigger a live Darwinbox lookup (`getEmployeeByPhone`) and are either onboarded via `syncUserNastari()` or rejected with instructions to fix their number in Darwinbox.
3. Read the **conversation state**, then dispatch: interactive payloads → `handleInteractive()` (a large `switch` on button/list IDs); text while in a non-idle state → `handleStatefulTextReply()`; anything else → main menu.

**State is a cache entry, not a DB row.** `DarwinboxService::getUserState()/saveUserState()` read and write `Cache::get('user_state_' . $cleanPhone)` with a 24h TTL, shape `['state' => string, 'data' => array]`. `saveUserState` merges `data` unless the new state is `idle`, which clears it. Because state lives in the cache store (`CACHE_STORE=database` in prod), flushing the cache drops every in-flight conversation. Adding a multi-step flow means adding a `waiting_for_*` state string handled in `handleStatefulTextReply()` and a menu ID handled in `handleInteractive()`.

Phone numbers are normalized everywhere by `DarwinboxService::cleanMobileNumber()` (strips non-digits, `620…`→`62…`, leading `0`→`62`). Always route lookups through it — the DB stores the cleaned form.

### Services

- **`DarwinboxService`** (1300+ lines, the core) — every Darwinbox HR API call, all WhatsApp message *formatting* (`format*` methods returning ready-to-send Indonesian text), user state, and FPDF letter generation. Each API family has its own key, resolved by `getApiKey()`.
- **`WhatsAppSenderService`** — every outbound WhatsApp Cloud API call: text, reply buttons, list menus, media upload/download, and the leave-approval Flow. Menu structures live here as `sendMainMenu` / `sendSubMenu*` methods, and the IDs they emit are what `handleInteractive()` switches on — the two files must be edited together.
- **`HearService`** — "Pandu", the policy helpdesk. Delegates retrieval to an **external Python RAG service hardcoded at `http://127.0.0.1:5003`** (`POST /session`, `POST /ask` with `business_unit` scoping). When the RAG returns `not_found`, it offers to escalate: creates a `Hear` ticket with a magic-link token, resolves the responsible HCO from `hco_mappings` (falling back to a hardcoded address), and emails them via PHPMailer with hardcoded SMTP settings — not Laravel's `Mail` facade or the `MAIL_*` config.
- **`RuangService`** — "Ruang", an empathetic-listener chat. Calls Gemini with a persona
  system-instruction, trims history to the last 3 messages for cost, persists to
  `daily_chat_logs` (now with `last_status` / `error_count`, and a full `timestamp` per
  message — it previously wrote only a bare clock time while the admin reader sorted on
  `timestamp`, so transcript ordering was arbitrary).

  **Ruang → Pandu hand-off.** The persona already classifies policy questions and points
  the employee at Pandu; it now also ends such a reply with a `[[PANDU]]` marker, stripped
  before sending. That marker (with a keyword sweep as fallback) offers a
  `ruang_to_pandu` button, and the question is parked in the conversation state so tapping
  it forwards the question verbatim — the employee never retypes it or walks back through
  the menus. Buttons use explicit ids via `sendRuangHandoffButtons()`, **not**
  `sendReplyButton()`, which numbers them positionally as `btn_0`/`btn_1`; `handleInteractive()`
  dispatches on the id, so a positional id changes meaning the moment a button is added.
  Inactive employees are never offered the button, since Pandu is refused to them anyway.
  If the parked question has expired from the 24h cache, it falls back to the normal Pandu
  greeting rather than failing.
- **`ManagerAssistantService`** — "Nastari AI" for managers. Parses natural-language queries with Gemini into an intent plus employee name, resolves the name against the manager's team, asks for clarification on ambiguity, then calls the matching `DarwinboxService` method.
- **`DataRestrictionService`** — filters plain arrays (API results, not Eloquent) down to employees the logged-in admin may see.

`AnalyticsService` and `ChatController::storeChat` operate on JSON files under `storage/app/data-ruang/{year}/{month}/` and are not reached by the current flows.

### Product analytics and activity logging

`nastari_events` is the event store, and rows reach it two ways. The `origin` column says
which, and **this is what stops double counting** — get it wrong and every number doubles.

- **`origin = 'live'`** — `NastariActivityLogger` writes in-request. This is the source of
  truth going forward. Rows are buffered and flushed once on `register_shutdown_function`,
  after WhatsApp already has its 200, so logging never delays a reply. Every write is
  wrapped: a logging fault degrades to a `Log::warning`, never to a broken conversation.
- **`origin = 'ingest'`** — `NastariEventIngestor` parses `storage/logs/laravel*.log` for
  history that predates live logging. It **refuses to parse any date on or after the first
  live event** (`liveCutoverDate()`), because the log still contains a `WA_INCOMING` line
  for messages that already have a live row. Idempotent via a unique `sha1` fingerprint of
  the source line. `--fresh` deletes only `origin = 'ingest'` rows; live rows have no log
  line to be rebuilt from.

A third source exists and is deliberately **not** part of the metrics:
`NastariLogScanner` (`nastari:scan-logs`, every 10 minutes) copies ERROR-and-above entries
from `storage/logs/laravel*.log` — the undated `laravel.log` included — into
`nastari_log_entries`, and the dashboard shows them in their own `#log-aplikasi` block. Read
this before touching it:

- **It exists because three classes of failure can never reach `nastari_events`.** PHP fatal
  errors (the process dies before the shutdown flush — which is exactly why the letterhead
  OOM left no trace but a log line), exceptions in the admin panel, and any `Log::error`
  without a structured counterpart. Without this, the most severe failures were the ones
  missing from the dashboard.
- **Its numbers are never summed with the structured error totals**, and the UI says so.
  Some errors legitimately exist in both places; adding them would count one incident twice.
  That is also why it is a separate table rather than more rows in `nastari_events`.
- **Every message is redacted before it is stored**, not before it is displayed. Darwinbox
  URLs carry `api_key` in the query string and cURL errors print the whole URL, so
  `api_key`/`token`/`password`/`Authorization`/`Bearer` values and any 32+ character
  alphanumeric blob become `[disensor]`; phone numbers and email local parts are masked.
  `LogScannerTest` asserts the secrets never land in the database.
- **Absolute paths are normalised to project-relative form** — and note the reason: the log
  files here contain lines written by *production* (`/home/<account>/chatbot-nastari/…`), so
  stripping the local `base_path()` prefix is not enough. Both the `file` column and the
  message body are cleaned.
- **Scanning is incremental.** `nastari_log_scans` keeps a byte offset per file, so each run
  reads only what was appended; a file smaller than its stored offset is treated as rotated
  and re-read from 0. Re-reading a 200 MB log every ten minutes would make logging the
  bottleneck it is supposed to measure. Entries are pruned after
  `nastari.logging.files.retention_days` (30), longer than the log files themselves survive.

Compatibility is deliberate and load-bearing: live rows keep the legacy `source` /
`event_type` / `feature_key` vocabulary that `NastariAnalyticsService` already queries, so
no existing metric changed meaning. The richer taxonomy is carried
additively in `action_key` (`employee.access.leave_balance`, `external_service.timeout`,
`employee.not_found`, …). `NastariActivityLogger::CLASSIFICATION` is the mapping, ordered
most-specific-first — `employee.access.denied` must stay above `employee.access`.

- **`app/Support/NastariFeatures.php` is the single feature registry.** Interactive payload
  id → slug, label, kind (`action` vs `navigation`), and whether an inactive employee may
  use it. Feature attribution, the menu the user sees, and the authorisation check all read
  it, so they cannot drift. Adding a menu item means adding it here too, or it logs as
  unattributed and is refused to inactive employees by default.
- Outbound HTTP is instrumented **globally** in `AppServiceProvider` via
  `Http::globalOptions()` with Guzzle's `on_stats`, not at the call sites — there are 20+
  `Http::` calls across four services and none set a timeout. Timeouts now default to
  20s/5s connect. `WhatsAppSenderService::uploadMediaToWhatsApp` uses raw cURL, so it is
  instrumented at its own call site instead.
- At ingest, `phone` is resolved through `usernastari` to `employee_id` + `business_unit`.
  **This is what makes behavioural data BU-filterable.** Pre-registered rows are included
  on purpose: they only improve the hit rate.
- `NastariDashboardController` → `/admin/dashboard` is **the only page that reports any of
  this**. It replaced both the old dashboard and the separate nine-tab `/admin/analytics`
  page; `admin.analytics.*` still exist as route names but only redirect, so old bookmarks
  do not 404. The superseded files are kept under `storage/app/superseded-2026-09-10/`
  (there is no git history to recover them from) and can be deleted once nobody misses them.
- The page is one scroll with eight anchored sections, in the order of the monitoring
  requirements: `#pengguna`, `#sinkronisasi`, `#jam-sibuk`, `#nomor-asing`, `#ruang`,
  `#unit-bisnis`, `#kesehatan`, `#fitur` — plus `#pandu` (its own section: summary, daily
  trend, repeated questions, and the full question/answer history), `#log-aplikasi`
  (log-file diagnostics, not metrics) and the weekly Ruang insight carried over from the
  old dashboard. Each section's
  markup lives in its own partial under `resources/views/admin/dashboard/partials/`.
- **Data is cached in six independent groups, not one payload.** Core aggregates key on
  (window, BU); the Ruang table also on page and status; the Pandu Q&A table also on page,
  outcome filter and search; the per-employee table also on page, search and role scope;
  unknown numbers and log entries only on the window (and the log level), because neither
  an unidentified number nor a PHP fatal has a BU. Merging them
  would make paging one table rebuild the whole page. TTL 300s. A cold load is ~55 queries;
  the app DB answers in ~45 ms each, so cold is ~3s (~5s including the first connection
  handshake) and warm ~0.7s.
- Query count is the thing to watch here, not query complexity: **latency dominates**.
  Several aggregates are deliberately written as one conditional aggregate rather than
  several `COUNT`s over the same filter (`overview`, `registrationCoverage`, `byHour`,
  `byDayOfWeek`, `unknownNumbers`, `accessDenials`, `ruangConversations`). Splitting them
  back up would be correct and 12 round trips slower. `DashboardMetricsTest` pins the
  numbers so that refactor cannot change a metric silently.

Things that will bite you here:

- **Never sum across the log-rotation boundary.** `laravel-2026-08-22.log` was deleted
  mid-session while this was being built; those events are gone. `nastari_events.event_date`
  MIN is the real start of history.
- **Service, endpoint, error_type and duration only exist on `origin = 'live'` rows.** The
  Error & Layanan section says so explicitly rather than presenting a partial breakdown as
  complete.
- **A funnel exists only for letters.** Every other feature answers immediately, so
  "opened" and "completed" are the same event. `letterFunnel()` deliberately returns
  `completion_pct => null` and sets `source_mismatch` when completions exceed observed
  starts, rather than showing a >100% funnel. It is currently unused by the dashboard —
  along with `frequencySegments()`, `insights()`, `dataGaps()`, `errors()`, `pandu()` and
  `ruang()`,
  which the removed analytics tabs were the only callers of. Kept because they are correct
  and cheap to re-surface; do not assume anything reads them today.
- **`pandu_qa` is where Pandu's content lives now**, and `hears` still holds escalations
  only. Read this before changing anything Pandu-shaped:

  - The **answer text used to be thrown away**. It was sent to WhatsApp and never
    persisted, so the only Pandu trace in the database was its failures. `HearService`
    now writes every exchange to `pandu_qa` (wrapped in try/catch — losing history must
    never cost the employee their answer), and metrics still come from the
    `employee.chat.pandu` events, not from this table.
  - **History was recovered from the log files.** `PANDU_DEBUG: Respon Python:` carries the
    whole RAG response, so `NastariEventIngestor` reconstructs question + answer + outcome
    for pre-cutover days into `origin = 'ingest'` rows (36 recovered, 29 with answer text).
    `--fresh` rebuilds only those, never the live ones. A question whose response line
    never arrived is recorded as `no_response` rather than dropped — a hanging question is
    exactly what someone needs to see.
  - **Escalation tickets are linked only on an exact phone + question match.** Of 37
    tickets, 4 matched; 13 predate the surviving logs entirely. A fuzzy match would attach
    an HCO answer to the wrong question, which is worse than no link. Going forward
    `HearService::linkTicketToQa()` links them at the moment the ticket is created.
  - The RAG payload line carries the business unit the search was scoped to, and the
    identity lookup used to overwrite it with null for unknown numbers. The Q&A row keeps
    the payload value as a fallback, which is why all 36 recovered rows have a BU.
- Ruang transcripts are gated on a `Super Admin` role check in
  `NastariDashboardController::canSeeConversations()`, not just `auth`, and the JSON
  endpoint refuses on its own — hiding the button is not the control. Phone numbers are masked
  everywhere via `maskPhone()`; NIKs are never written into `nastari_events.payload`, and
  `NastariActivityLogger` drops any payload key matching password/token/secret/api_key/
  authorization/credential/cookie/session_id.
- Headcount must exclude soft-deleted rows: `kpncorp.employees` currently has 8,915 rows
  but only 6,313 live ones.
- `NastariDashboardController::safeDate()` needs its round-trip check: `createFromFormat`
  rolls out-of-range parts over, so `2026-13-45` used to parse cleanly into a future window.
  `?bu=` is likewise validated against the real BU list, or a typo renders an empty page
  that reads as "no activity".
- **Aggregates on this page are corporate-wide**, exactly as the analytics page already was
  for every authenticated admin. Only the per-employee table — the one place showing a name
  next to a NIK — is narrowed to the role's `scope_bu`, and the two widgets carried over
  from the old dashboard (HC System Desk count, weekly Ruang insight) keep their original
  `scoped()` queries. Do not "make it consistent" by widening those two.

### Employee status: active vs inactive

The rule, applied everywhere:

```
employees.deleted_at IS NULL      -> usernastari.employment_status = 'active'
employees.deleted_at IS NOT NULL  -> 'inactive'
no matching employees row         -> 'unknown'
```

`EmployeeSyncService` (daily, `nastari:sync-employees`) maintains it. **`unknown` counts as
permitted** — fail-open, because locking a real employee out because a sync failed is worse
than briefly letting a leaver read their own data, and every refusal is recorded as
`employee.access.denied` so false positives surface the same day.

An inactive employee may use **only** `personal_data` and `ruang_placeholder`.
`EmployeeAccessPolicy` enforces this in `WebhookController::handleInteractive()` **and** on
the conversation state in `handle()`. Both are required: a WhatsApp interactive reply is
just a payload id and an old menu message can be re-tapped, and the letter and Pandu flows
continue through plain text. Hiding menu rows is presentation, never authorisation.

**Two populations live in `usernastari`** and must not be conflated:

- `registration_status = 'registered'` (~830) — has actually used the bot. The bot resolves
  identity from these rows **only**.
- `registration_status = 'pre_registered'` (~4,145) — seeded from HRIS by the daily sync so
  the dashboard can show coverage. Never used to identify a caller, because HRIS phone
  numbers are 77% populated and dirty. First contact still goes through the authoritative
  Darwinbox lookup, which promotes the row via `syncUserNastari()`.

Anything reporting adoption must use `UserNastari::registered()`, or "total users" silently
becomes headcount. `NastariDashboardController`, `EmployeeController`, `RuangController`,
`AnalyzeWeeklyChat` and `NastariAnalyticsService` all do.

Sync safety rules, each derived from measured data quality — do not "simplify" them away:

- Only non-empty source values are written. `employees.date_of_joining` is 0% populated and
  `direct_reportees_employee_id` only 14%; a naive mirror would erase the joining date
  printed on every letter and strip the manager menu from 86% of managers.
- `whatsapp_number` is never overwritten once set. It is the bot's only identity key.
- `full_name`, `company_email_id` and `unit_name` are **not** synced: HRIS is the worse
  source ("Djuaman Lie" → "Djuaman", `…@kpn-corp.com_terminate`, and an internal code
  appended to the unit name).
- `contribution_level` **is** synced, and matters more than it looks: it selects the
  letterhead file, and the HRIS spelling is the one that matches the image on disk.
- Manager names only move when their id moves, keeping the stored `Name (NIK)` shape.
- A phone shared by more than one active employee (3 exist) is never pre-registered, and
  non-numeric ids are excluded (`employees` holds a `DBOX` service account with a phone).

### Two databases

`config/database.php` defines two MySQL connections:

- **`mysql`** (default) — this app's own tables: `usernastari`, `hears`, `call_centers`, `letter_logs`, `kb_documents`, `daily_chat_logs`, `roles`, `role_user`, `hco_mappings`, plus Laravel's cache/jobs/sessions/users. Migrations target this one.
- **`kpncorp`** (`KPNCORP_DB_*`) — a read-only view of the corporate HR database. `Employee`, `Company`, and `Location` set `protected $connection = 'kpncorp'`. There are no migrations for it; do not try to migrate or seed it.

Note the two overlapping employee representations: `Employee` (kpncorp, the HR master) and `UserNastari` (local, the WhatsApp-registered subset synced from the Darwinbox API). They are joined on `employee_id` (NIK), never on `id`.

### Role-based data scoping

Admin data access is filtered by a per-role organizational scope, applied two ways:

- `ScopedByRole` trait → `Model::scoped()` query scope. Used by `Employee`, `UserNastari`, `Hear`, `KbDocument`, `CallCenter`, `DailyChatLog`.
- `DataRestrictionService::filterArray()` for data that never becomes a query.

Both resolve the current user's roles the same way, and several quirks matter:

- **`role_user.user_id` holds the employee NIK (`users.employee_id`), not `users.id`.** The migration declares it as a plain string for this reason.
- A user with **no** role gets `whereRaw('1 = 0')` — an empty result set, not an error. A role named `Super Admin` (case-insensitive) bypasses all filtering.
- Scope columns differ per table: `usernastari`, `daily_chat_logs`, and `call_centers` filter on `contribution_level`/`office_area`; everything else on `company_name`/`work_area_code`. A new scoped model using the first shape must be added to that `in_array` check in the trait.
- Scope values (`scope_bu`, `scope_company`, `scope_location`) are stored loosely — JSON array, comma-separated string, or bare string — and normalized by `parseScopeData()`. Matching is `LIKE %value%`.

### Auth

Login is Darwinbox SSO only, inbound: Darwinbox redirects to `/sso/callback?data=<base64(xor(base64(json)))>`, `SsoController` decrypts it (XOR key `666666`), validates the token against Darwinbox `/checkToken`, then `firstOrCreate`s a `User` and logs them in. There is no local login form and no registration.

### Letter generation

`generateSuratKeteranganPdf`, `generateSuratPembuatanVisaPdf`, and `..._EN` build PDFs with
FPDF. The signing officer comes from a hardcoded map in `getSignatoryForEmployee()` keyed on
`group_company`. Every generated letter is recorded in `letter_logs`.

**The letterhead is the memory hazard — read this before touching it.** Two production
requests died with `Allowed memory size of 536870912 bytes exhausted` at `fpdf.php:1366`,
which is `gzuncompress($data)` inside `_parsepngstream()`'s alpha-channel branch. Four kop
surat files are ~30.000 × 3.583 px **RGBA** PNGs: only ~1 MB on disk because they are mostly
white, but FPDF must inflate the whole raster before it can split the alpha channel, which
is a single ~410 MB allocation. The employee got no letter, no error, and — because the
fatal skipped the old post-generation state reset — stayed pinned in the letter state for
the full 24h cache TTL, re-crashing on every reply.

So:

- **`LetterheadResolver` is the only way to reach a letterhead image.** All three templates
  call `DarwinboxService::drawLetterhead()`; do not reintroduce a per-template copy of the
  file lookup. The resolver reads image *headers* only (never pixel data), projects what
  FPDF will need, and returns `null` when it exceeds `config('nastari.letterhead')` budgets
  — 40 MP or 64 MB projected. Over budget means the letter is issued **without** a
  letterhead, logged as `LETTERHEAD_SKIPPED_OVER_BUDGET`. Never fatal.
- It prefers `storage/app/kop-surat-kpn-optimized/`, produced by
  `nastari:optimize-letterheads`. That command is **the only place that decodes a full
  letterhead bitmap** and raises its own memory limit to do it; run it after adding or
  replacing any kop surat file. 1.1 MB / 107 MP PNG → 54 KB / 2480×296 JPEG, and it
  flattens alpha onto white so FPDF never enters the alpha branch again.
- It also sanitises the company name, which comes from the database and is concatenated
  into a filesystem path.
- `WebhookController::deliverLetter()` clears the conversation state **before** generating.
  A PHP fatal skips `catch` and `finally`, so ordering is the only protection that works.
- Letter numbers come from `LetterNumberService` (an atomic per-scope counter in
  `letter_number_sequences`), not `LetterLog::count() + 1`, which handed duplicates to
  concurrent requests and re-used numbers after any row was deleted. The BU→code map lives
  in `config/nastari.php`; it previously said `Plantation` while the database says
  `Plantations`, so every Plantations letter was stamped `CHC`.

After these changes a letter for the worst-case company takes ~335 ms at a ~36 MB process
peak. `memory_limit` was not raised.

## Configuration gotchas

- `.env.example` now documents every key this app needs (`WHATSAPP_*`, `DARWINBOX_*`,
  `GEMINI_*`, `KPNCORP_DB_*`, `NASTARI_*`, `TASK_RUNNER_TOKEN`) with empty values. Keep it
  in step when you add one.
- **`ManagerAssistantService` and `AnalyzeWeeklyChat` still call `env('GEMINI_API_KEY')` at
  runtime**, so `php artisan config:cache` will silently break them — cached config makes
  `env()` return null outside config files. `RuangService` was converted to
  `config('services.gemini.api_key')`; do the same for the other two, and use `config()` in
  all new code.
- `config('services.gemini.api_key')` is the key that resolves. `config('services.gemini.key')`
  reads `GEMINI_KEY`, which `.env` does not define, so it is null — and
  `DarwinboxService::generateBirthdayMessage()` / `generateWorkAnniversaryMessage()` are its
  only consumers and will still fail on auth.
- **Do not run `php artisan cache:clear` in production.** Conversation state lives in the
  cache store with a 24h TTL, so it drops every in-flight conversation, including a parked
  Ruang→Pandu question. `/fix-route` only clears route/config/view caches, which is safe.
- CSRF is disabled for `webhook*` (`bootstrap/app.php`); the WhatsApp webhook has no other request verification beyond the GET verify-token handshake.
- The RAG service URL and the escalation SMTP credentials in `HearService` are hardcoded rather than config-driven.
- Production runs with a split docroot (app at `~/chatbot-nastari`, `index.php` under `~/public_html/chatbot-nastari`). `routes/web.php` exposes an unauthenticated `/fix-route` endpoint that clears route/config/view caches — that is the deployment's cache-clear mechanism.

## Known-broken and dead code

Pre-existing defects, so you don't mistake them for your own:

- **No `login` route exists**, but `auth` middleware redirects there — any unauthenticated hit on `/admin/*` or `/hear/*` throws `RouteNotFoundException` (a 500, recurring in `storage/logs/`). `SsoController`'s four `redirect('/login')` error paths dead-end the same way.
- `RoleController::edit()` redirects to `route('admin.setting.roles.create')`; the real name is `admin.roles.create`.
- Routes `hear.kb.files` (`getKbFiles`) and `hear.kb.sync` (`syncSingleKb`) point at methods that don't exist on `HearController`.
- `hear.kb.add` is registered twice in the same group; the second wins.
- `DashboardController` returns a `dashboard.index` view that doesn't exist and is not routed. `HcoMappingController-Backup.php` and `hco_mapping/index-backup.blade.php` are leftovers. `ImportJsonChats` is an empty stub. `welcome.blade.php` is unused.
- `WebhookController` and `HearService` each carry a large commented-out earlier version of a method directly above the live one — read past them.
