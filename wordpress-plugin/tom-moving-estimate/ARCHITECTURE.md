# Tom Moving — Site & Estimate System Architecture

_Last updated: 2026-10-05. Reflects plugin version `1.2.0-rc19`._

## 1. Overview

`tommoving.ca` is a WordPress site (Elementor + Hello Elementor theme, Polylang
for English/French) hosted on **GoDaddy Managed WordPress**, fronted by
**Cloudflare** (CDN + edge cache). The site's lead-generation core is a
custom-built plugin, **Tom Moving Estimate** (`wp-content/plugins/tom-moving-estimate/`),
which replaces a traditional contact form with a multi-step estimate tool:
contact info → photos or a guided video walkthrough → private upload to
Cloudflare R2 → staff review in wp-admin.

The plugin also contains two more advanced, partially-built subsystems:
a **live video call** integration with a separate companion web app, and an
**AI-generated inventory report** feature that is currently scaffolded but
not wired to a live AI provider (see §9 and §10).

## 2. Hosting & Infrastructure

| Layer | Detail |
|---|---|
| Hosting | GoDaddy Managed WordPress ("wpaas" platform) |
| Domain/DNS | Managed at GoDaddy (`dcc.godaddy.com`) |
| CDN / edge cache | Cloudflare (proxied) — see §11 for caching gotchas |
| File access | SFTP at `453916.us19.ssh.myftpupload.com:22`, document root `html/` |
| Database | MySQL, accessible via phpMyAdmin from the GoDaddy hosting dashboard |
| WordPress | v7.1.2 (at time of writing) |
| PHP | 8.2.33 |
| Theme | Hello Elementor + Elementor Pro (page building) |
| Multilingual | Polylang + Connect Polylang for Elementor |
| SEO | Yoast SEO (Premium) |
| Object storage | Cloudflare R2 (S3-compatible) — buckets `tom-moving-estimate-production` / `-staging` |
| Outbound email | Brevo (SMTP API) via the **WP Mail SMTP** plugin — see §8 |

**Staging vs. production** is detected at runtime by hostname:
`TME_Plugin::is_staging()` returns true when the site's host contains
`myftpupload.com` (GoDaddy's default temp domain), which also selects the
R2 staging bucket automatically.

## 3. Plugin File Layout

```
wp-content/plugins/tom-moving-estimate/
├── tom-moving-estimate.php       # Bootstrap: constants, TME_Plugin class, activation/upgrade
├── includes/
│   ├── class-tme-public.php      # Shortcode, REST API, front-end form logic, email notifications
│   ├── class-tme-admin.php       # wp-admin UI: leads list, detail view, settings, exports
│   ├── class-tme-db.php          # DB schema (dbDelta) + CRUD for the sessions table
│   ├── class-tme-r2.php          # Cloudflare R2 client (presigned URLs, HEAD, DELETE)
│   ├── class-tme-retention.php   # Scheduled deletion of media after N days + warning email
│   ├── class-tme-secrets.php     # Symmetric encryption for stored API keys/tokens
│   ├── class-tme-live-call.php   # Integration with the separate "Sites" live-call web app
│   ├── class-tme-ai-report.php   # AI report JSON schema: decode/sanitize/recalculate
│   ├── class-tme-ai-export.php   # Renders AI report as printable HTML / CSV / JSON
│   └── class-tme-lead-report.php # Plain-text lead summary (used for the "email lead" admin action)
├── assets/
│   ├── css/public.css            # Front-end form styling
│   ├── css/admin.css
│   ├── js/public.js              # Front-end form behavior (vanilla JS, no framework)
│   ├── js/admin.js
│   ├── js/ai-report.js
│   └── data/phase2-ai-report-example-v1.json  # Synthetic AI report fixture (staging only)
├── readme.txt
└── uninstall.php
```

All classes are `final`, use `private`/`public static` methods almost
exclusively (no instantiation except `TME_R2`), and are loaded unconditionally
via `require_once` from the bootstrap file — there is no autoloader.

## 4. Database Schema

Single custom table: `{$wpdb->prefix}tme_sessions` (e.g. `wp_rycrg9j6ky_tme_sessions`).
One row = one estimate request, which evolves in place as the visitor
progresses through the form (see §5's step model) rather than creating
multiple rows.

Key columns:

| Column | Notes |
|---|---|
| `public_token` | 64-char random token, used in the public resume URL (`?session=...`) and REST routes. Unique key. |
| `client_name`, `email`, `phone`, `current_address`, `estimated_size` | Required fields |
| `move_date` | **Nullable** `DATE` — optional field (see §12, dbDelta gotcha) |
| `destination_address` | `varchar(255) NOT NULL` but may be stored as `''` — optional field, validated as "not required" in PHP rather than at the DB level |
| `submission_type` | `info` \| `photos` \| `video` \| `live` |
| `submitted_at` | Set once the chosen submission is actually completed (null while mid-flow) |
| `photos` / `pending_photos` | JSON arrays (photo metadata + R2 keys) |
| `video_key`, `pending_video_key`, `video_uploaded_at`, `video_expires_at` | Video upload + retention tracking |
| `media_expires_at`, `media_deleted_at`, `status` | Retention sweep bookkeeping |
| `live_call_id`, `live_rep`, `live_started_at` | Populated when a submission originates from the live-call feature (§9) |
| `ai_status`, `ai_report_original`, `ai_report_current`, `ai_requested_at`, `ai_reviewed_at`, ... | AI report workflow state (§10) |
| `consent_version`, `consent_at` | Consent audit trail |

Schema is created/updated via WordPress's `dbDelta()`, gated by comparing
the stored option `tme_db_version` against `TME_VERSION` — see §12 for a
real limitation we hit with this mechanism.

## 5. Front-End Form Flow (`class-tme-public.php` + `public.js`)

Rendered via the `[tom_moving_estimate]` shortcode, placed on two pages:
`/estimate/` (English) and `/fr/estimation/` (French) — same shortcode,
language auto-detected (see §7).

**Step 1 — Contact info** (progressive disclosure): only Full name, Email,
Phone, Move date are shown initially. Move date and Destination address are
optional. Clicking "Continue" the first time just reveals the remaining
fields (Current address, Home size incl. "Other", Special items, consent) —
no network call yet. Clicking it again actually submits via
`POST /wp-json/tme/v1/sessions`, creating the DB row.

**Step 2 — Choice**: "Upload photos" or "Start video walkthrough" →
`POST .../sessions/{token}/submission-type`.

**Step 3a — Photos**: client-side selection, each photo gets a presigned R2
PUT URL (`POST .../photo-upload-url`), uploaded directly browser→R2 (never
touches the WordPress server), then finalized via
`POST .../complete-photos`.

**Step 3b — Video**: in-browser `MediaRecorder` capture (or file picker
fallback), same presign → direct-to-R2 upload → finalize pattern via
`upload-url` and `complete` endpoints.

All REST routes are registered under namespace `tme/v1` in
`TME_Public::register_routes()`. Public, unauthenticated (`permission_callback
=> '__return_true'`), protected instead by per-action rate limiting
(`rate_limited()`, using transients keyed by a hash of the visitor's IP).

Client-side required-field handling: because hidden form sections use the
HTML `hidden` attribute, and Chromium still counts `required` fields inside
a hidden ancestor as invalid (blocking `reportValidity()` without any visible
error), the JS explicitly toggles the `required` attribute on/off for fields
inside the "show more" section rather than relying on CSS visibility alone.

## 6. Media Storage (Cloudflare R2)

`class-tme-r2.php` wraps R2's S3-compatible API using AWS SigV4 presigned
URLs (`presign()`), generated per-request with no SDK dependency. Credentials
(`access_key`, `secret_key`) are stored encrypted (§8.1) in plugin settings
and only decrypted in memory when needed. The browser uploads directly to R2
using these presigned PUT URLs — the WordPress server never proxies the
file bytes.

Retention (`class-tme-retention.php`) runs on a daily WP-Cron sweep
(`tme_retention_sweep`): finds sessions past `retention_days` (default 30)
and deletes the R2 objects + clears DB references, with a `warning_days`
(default 3) heads-up email beforehand. Deletion can also be triggered early
from the admin UI.

## 7. Bilingual Support

There is no translation framework (no `.po`/`.mo`, no `__()` calls in the
front-end template) — all EN/FR strings are hardcoded inline in
`class-tme-public.php` as `$is_fr ? '...' : '...'` ternaries, including a
dedicated `i18n_strings()` array that's localized into the page via
`wp_localize_script()` for the JS layer to consume (`TMEPublic.i18n`).

Language detection priority (`detect_language()`):
1. Explicit `lang` shortcode attribute
2. Polylang's `pll_current_language()`
3. Fallback: does the current URL path contain `/fr/`?

This was rebuilt from scratch in Oct 2026 after a prior refactor
(merging in the R2/photo/video features) silently dropped the bilingual
logic that used to exist in an earlier "quick start" version of the
shortcode — see §12 for the lesson learned there.

## 8. Email Notifications

Two independent notification tracks, both triggered from
`class-tme-public.php`'s REST handlers:

### 8.1 Admin "step" emails (synchronous, immediate)
`send_admin_step_notification($session, $step, ...)` fires at three points,
each with a distinct subject line for easy inbox triage:
- **Step 1** — contact info submitted (`create_session`)
- **Step 2** — chose photos or video, not yet uploaded (`choose_submission_type`)
- **Step 3** — upload completed (`complete_upload` / `complete_photos`)

Subject format: `Site form lead {name} - Step {n}`.

### 8.2 Customer follow-up email (debounced, delayed)
A single customer-facing email, sent **15 minutes after the visitor's last
action**, reflecting wherever they ended up (abandoned at info-only,
started-but-didn't-finish media, or fully completed). Implemented via
WP-Cron: `schedule_customer_email()` cancels any previously-scheduled event
for that session (`wp_unschedule_event`) and reschedules a new one
(`wp_schedule_single_event`), so rapid step progression collapses into
exactly one email rather than spamming the customer. The cron callback
`send_customer_progress_email()` re-reads the session fresh at fire-time to
compose the right message.

### 8.3 Delivery transport
Originally relied on PHP's built-in `mail()` via `wp_mail()`, which turned
out to have **silent delivery failures** (no error, no bounce — just never
arrived) because no SMTP authentication (SPF/DKIM) was configured. Fixed by
installing the **WP Mail SMTP** plugin connected to **Brevo**:
- Brevo API key stored in WP Mail SMTP's own settings (not in this plugin's
  option table)
- Brevo's IP-allowlist security feature initially blocked the server's
  outbound IP (`72.167.85.177`) — had to be added manually in Brevo's
  dashboard under Security → Authorised IPs
- DKIM TXT record added to `tommoving.ca`'s DNS (GoDaddy) for deliverability/
  spam-avoidance, verified green in Brevo

There is also an older, separate "lead report" email path
(`class-tme-lead-report.php` + `TME_Admin::email_lead_report()`) that lets
staff manually email a formatted summary of one lead from the admin detail
screen — unrelated to the automatic step/follow-up emails above.

## 9. Live Video Call Feature (`class-tme-live-call.php`)

A rep-initiated live walkthrough call, **delegated to a separate companion
application** referred to in code comments as "the Sites app" — this
plugin does not run the call itself. Flow:

1. Rep starts a call from `wp-admin → Live Call` → plugin calls
   `POST {sites_base_url}/api/calls` (or similar) on the companion app,
   authenticated with a shared-secret Bearer token (`tme_live_call_settings`
   option, secret stored encrypted via `TME_Secrets`).
2. Plugin can text the customer a join link via **Twilio SMS**
   (`twilio_sid`/`twilio_token`/`twilio_from` settings, token encrypted).
3. A WP-Cron sweep (`tme_live_import_sweep`, every 5 minutes) polls the
   companion app for completed calls and imports the recording + captured
   details back into `tme_sessions` as `submission_type = 'live'`, so it
   joins the same review queue and retention policy as photo/video leads.

The companion "Sites app" itself is **not part of this WordPress codebase**
— it's a separate Next.js application (seen locally in development as the
`moveestimate` project, with its own `/api/calls`, `/api/video-sessions`
routes) deployed independently. This plugin is purely a client of its API.

## 10. AI Inventory Report (scaffolded, not yet live)

`class-tme-ai-report.php` and `class-tme-ai-export.php` implement a full
data model and review workflow for an AI-generated moving inventory report
(rooms, items, disassembly requirements, mattress sizes, confidence scores,
a human-review/approval diff trail) with CSV/JSON/printable-HTML export.

**However**, there is currently no code path that calls an external AI
vision/analysis API on uploaded photos or video. The only way a report gets
into a session today is `TME_Admin::load_synthetic_ai_report()`, which loads
a fixed example JSON file (`assets/data/phase2-ai-report-example-v1.json`)
and is explicitly gated to staging only
(`if (!TME_Plugin::is_staging()) { wp_die(...) }`). The `ai_model` field gets
literally set to `'synthetic-example'` in this path. Treat this as a
**built-but-disconnected** feature, not a working AI integration, until an
actual analysis provider is wired into `TME_AI_Report`.

## 11. Caching — Important Operational Note

The site sits behind **two separate cache layers** that must usually both
be cleared to see a front-end change live:
1. **Cloudflare edge cache** (`CF-Cache-Status` header) — was repeatedly
   observed to still serve stale HTML/CSS/JS 30+ minutes after a deploy.
2. **GoDaddy's own gateway cache** (`x-gateway-cache-status` header,
   flushed via the "Flush Cache" link in the wp-admin toolbar's GoDaddy
   Quick Links menu, or the hosting dashboard).

Flushing only one layer is often not enough — both need clearing (wp-admin
Flush Cache **and** the GoDaddy hosting dashboard's cache option) for
changes to reliably appear for real visitors. REST API responses (POST
requests) are not subject to this — only cached GET page/asset requests are
affected, so backend-only changes (e.g. the email notification logic) don't
need a cache flush.

Static asset URLs (`public.js`, `public.css`) are cache-busted via
`?ver={TME_VERSION}` query strings on `wp_enqueue_*` calls — bump
`TME_VERSION` in the bootstrap file on every deploy that changes those
files, which also triggers the `dbDelta()` upgrade path (see §12).

## 12. Known Gotchas / Lessons Learned

- **`dbDelta()` does not reliably alter existing columns' `NULL`/`NOT NULL`
  constraint.** When `move_date` was changed from `NOT NULL` to `NULL` to
  make it optional, bumping `TME_VERSION` correctly triggered
  `TME_DB::install()` (confirmed via the `tme_db_version` option updating),
  but the live column stayed `NOT NULL` — had to be fixed with a direct
  `ALTER TABLE ... MODIFY move_date date NULL;` run manually via phpMyAdmin.
  Any future column constraint change should be verified directly (e.g.
  `DESCRIBE` the table) rather than trusting the version bump alone.
- **A prior "merge" of features silently dropped bilingual support.** The
  shortcode used to have a separate, working `[tom_moving_estimate_quick_start]`
  variant with full French strings; when the plugin was rebuilt to add
  photo/video/R2 support, that shortcode and its translation logic were
  lost entirely without anyone noticing until a user report. No automated
  test or diff review caught it. Worth having *some* smoke test (even
  manual) that loads both `/estimate/` and `/fr/estimation/` after any
  deploy.
- **WP-Cron is request-triggered, not a real timer.** Both the 15-minute
  customer-email debounce and the 5-minute live-call import sweep rely on
  WordPress's default pseudo-cron, which only fires when a visitor hits the
  site. On a low-traffic period this can push "15 minutes" to noticeably
  longer. No real system cron override (`DISABLE_WP_CRON` /
  `ALTERNATE_WP_CRON`) is currently configured in `wp-config.php`.
- **Rate limits exist per IP** on the public REST endpoints (e.g. 10
  session-creates/hour, 20 submission-type changes/hour) via WordPress
  transients — relevant when load-testing or repeatedly debugging from the
  same machine; you will eventually get `429` responses.
- **Bare PHP `mail()` has no deliverability guarantee.** Always check for
  an SMTP plugin before assuming `wp_mail()` success means the email
  arrived — `wp_mail()` returning `true` only means local handoff
  succeeded, not actual delivery.

## 13. Deployment Process

There is no CI/CD, staging-to-production promotion, or version control
repository connected to this plugin's live code — changes are made by
editing/uploading files directly via SFTP to
`wp-content/plugins/tom-moving-estimate/` on the production server. The
convention established during the Oct 2026 work:

1. Download the current live file(s) fresh before editing (don't assume a
   local cache is current).
2. Make the edit, sanity-check PHP tag balance / JS syntax locally (no PHP
   CLI is available on the dev machine — `php -l` is not an option; rely on
   careful review plus `grep -c '<?php'` / `grep -c '?>'` tag-balance
   checks, and `node --check` for JS).
3. **Before overwriting a live file, copy it to `{filename}.{ext}.bak-{prevversion}`**
   alongside itself on the server, so any deploy can be rolled back by hand.
4. Bump `TME_VERSION` in `tom-moving-estimate.php` on any deploy that
   changes enqueued CSS/JS or the DB schema (cache-busting + triggers the
   upgrade path).
5. Upload the new file(s).
6. Verify directly against the live URL with a cache-busting query string
   (`?cb=<timestamp>`) to bypass both cache layers described in §11 and
   confirm the change actually reached the server, independent of whether
   real visitors can see it yet.
7. Remind whoever is testing to flush both cache layers (§11) before
   expecting to see the change without the `?cb=` workaround.

No automated test suite exists for this plugin. Verification during this
project was done with ad hoc Playwright browser scripts and temporary
diagnostic PHP scripts uploaded via SFTP and deleted immediately after use
— a pattern worth formalizing (e.g. a proper `/tests` directory and a
safe, permanent `wp-admin`-gated diagnostics page) if this plugin continues
to grow.
