# Tom Moving Estimate — live-call integration

The WordPress plugin ("Tom Moving Estimate") is deployed separately to
tommoving.ca; it is not built from this repo. This folder tracks the plugin-side
changes for the **live walkthrough** integration so they can be reviewed
alongside the Sites-app changes (`app/api/calls/*`, `app/call-token.ts`).

The rep starts a live call from WordPress; the call runs on the Sites app; when
it ends the recording and captured contact details are pulled back into
`wp_tme_sessions` as a new `submission_type = 'live'` request.

## Delivered as

`work/tom-moving-estimate-1.2.0-rc1.zip` — the full plugin (live `1.1.0-rc4` +
the changes below). Install on the GoDaddy **staging** site first.

## Changes over live `1.1.0-rc4`

**New file** — `includes/class-tme-live-call.php` (tracked here in full):

- `Move Estimates → Live Walkthrough` admin screen: start a call, get the rep
  link + a client link, send the client link by SMS (Twilio) / email
  (`wp_mail`) / the rep's own phone (`sms:` / `mailto:`).
- `admin-post` `tme_live_import` — pull a finished call from the Sites API,
  stream the recording into the plugin's own R2 bucket, create the estimate
  request, ack back, redirect the rep to it. This is the target of the Sites
  "Finish in Tom Estimator" button.
- `tme_live_import_sweep` cron (every 5 min) — imports any finished call whose
  tab was closed before the rep clicked through.
- Live-walkthrough settings (Sites base URL, shared secret, Twilio creds),
  secrets stored via the existing `TME_Secrets`.

**Edited files** (small, applied in the zip):

| File | Change |
|------|--------|
| `tom-moving-estimate.php` | version `1.1.0-rc4` → `1.2.0-rc1`; `require_once` + `TME_Live_Call::init()` / `::deactivate()` |
| `includes/class-tme-db.php` | `wp_tme_sessions` gains `live_call_id`, `live_rep`, `live_started_at` + `KEY live_call_id` (via `dbDelta` — the version bump re-runs it) |
| `includes/class-tme-admin.php` | `submission_label()` learns `'live'` → "Live walkthrough"; "New live walkthrough" button on the list header |
| `readme.txt` | stable tag + a "Live walkthrough (1.2.0)" section |

A `'live'` row reuses the existing video review screen (player + annotations +
notes + status) and the 30-day retention path unchanged.

## Settings to configure after install

`Move Estimates → Live Walkthrough` (admin only):

- **Sites app URL** — e.g. `https://moveestimate-tom-moving.temach.chatgpt.site`
- **Shared secret** — must equal `WP_SHARED_SECRET` on the Sites deployment
- **Twilio** SID / auth token / from-number — only needed for the "Text the link" button

## 1.2.0-rc2 / rc3 — staging-testing fixes

Found while staging-testing PR #12 end to end (auth worked, a real call recorded,
uploaded, and imported into a new "Live walkthrough" estimate):

- **rc2** — `TME_Live_Call::shared_secret()` / `base_url()` now `trim()` the
  stored values, and `handle_save_settings()` trims on save too. A secret
  pasted into the settings field with stray whitespace was silently rejected
  by the Sites API's exact-match bearer check ("Not authorized").
- **rc3** — the video review screen's **laser** tool only ever flashed while
  held and was never saved, which made it useless on this screen (there's no
  second viewer to see a live-only pointer during solo review, unlike the
  laser on an actual call). Click/tap with the laser tool now drops a saved
  marker at that moment in the video, same as drawings and notes:
  - `assets/js/admin.js`: `finishPointer()` pushes a `type: 'laser'`
    annotation on release; `draw()` renders saved laser points the same way
    it renders the live one (factored into `drawLaserDot()`); the annotation
    list labels them "Laser point".
  - `includes/class-tme-admin.php`: `sanitize_annotations()` was silently
    dropping any type other than `draw`/`note` — `laser` (x/y/time, no text)
    is now accepted and clamped like the others. Also fixed a pre-existing
    `E_WARNING` (undefined `size` key) in the `draw` branch, found by the new
    test's regression case.

## 1.2.0-rc4 — rc9

Delivered independently to the user across several install/test cycles on
staging (tab-closing UX, richer contact form, English/French calls, editable
Client details). Not written up here in detail; see the `readme.txt`
changelog entries for rc4 through rc9.

## 1.2.0-rc10 — lead report

**New file** — `includes/class-tme-lead-report.php` (tracked here in full):
builds a full plain-text "lead report" for one estimate — client details,
submission/status, live-call info, rep notes, and (when one has been saved) a
readable summary of the AI moving report (summary stats, inventory by room,
disassembly, mattress bags, open questions). Used both for the on-screen
report view and as the email body.

**Edited files:**

| File | Change |
|------|--------|
| `tom-moving-estimate.php` | version `1.2.0-rc9` → `1.2.0-rc10`; `require_once` for the new class |
| `includes/class-tme-admin.php` | `submission_label()` made `public` (reused by the new class); new admin-post actions `tme_view_lead_report` (renders the report + a "Send by email" form, prepopulated with every `tme_manage_estimates` user's email) and `tme_email_lead_report` (validates recipients, `wp_mail()`s the report text); "Create lead report" button added next to the submission badge on the review screen |
| `readme.txt` | stable tag + changelog entry |

## Checks

- **rc10:** `php -l` clean on every file in the plugin. New
  `tests/lead-report-harness.php` (27 checks) covers `TME_Lead_Report::build_text()`
  — core fields, optional sections only appearing when populated, live-call
  vs. photos vs. video submissions, the AI-report summary/rooms/disassembly/
  mattress-bags/open-questions text, and `TME_Admin::parse_emails()`
  (comma/semicolon splitting, trimming, deduping, dropping invalid
  addresses). `tests/live-call-harness.php` and `tests/annotations-harness.php`
  still pass unchanged. Not yet exercised on a real WordPress — please
  smoke-test on staging: open "Create lead report" on a real estimate,
  confirm the text looks right (with and without a saved AI report), and
  send a test email.
- **rc1-rc9:** `php -l` clean on all files; `php tests/live-call-harness.php`
  and `php tests/annotations-harness.php` (from the repo root) passed —
  helper-logic checks (phone → E.164, link message, settings, cron schedule,
  annotation sanitizing).
- API contract matches the Sites `/api/calls*` smoke in `feat/wp-live-call-api`.
- Verified end-to-end on GoDaddy staging through rc9 (DB upgrade, admin
  screens, a real call → import → row + R2 object + retention).
