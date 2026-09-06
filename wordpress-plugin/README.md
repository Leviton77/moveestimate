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

## 1.2.0-rc11 — two bugs found while testing rc10 on staging

- The rep note auto-added when a live walkthrough is imported ("Imported
  from a live walkthrough on...") printed the stored UTC timestamp as if it
  were already local time — 4 hours off on this site's timezone. Extracted
  into `TME_Live_Call::imported_note()`, which now converts through
  `wp_date()` before formatting. Covered by 3 new checks in
  `tests/live-call-harness.php`.
- Clicking "Send by email" on the lead report could land on WordPress's
  generic "The link you followed has expired" page, losing whatever the rep
  had typed into the recipient field. Root cause not fully confirmed — the
  page's own nonce check (opening "Create lead report") worked fine, so the
  most likely explanation is the report page (a standalone admin-post.php
  response with a form embedded, the first of its kind in this plugin)
  sitting open, or the host's edge cache serving it to a different session
  than it was generated for; either way the nonce embedded in the form no
  longer matched at submit time. `email_lead_report()` now verifies the
  nonce manually instead of via `check_admin_referer()`: on a mismatch it
  redirects back to a fresh copy of the report (new nonce) with the typed
  recipient list preserved and a plain-language banner, instead of dying.
  `view_lead_report()` also now sends an explicit `Cache-Control: no-store`
  header as a second line of defense against the caching theory.

## 1.2.0-rc12 — the real fix for "link expired" on send-by-email

rc11's graceful-redirect change didn't fix it: staging confirmed the email
sent successfully every time, but the *redirect back to the report* then
failed **its own** nonce check (`tme_view_lead_report`) and showed
WordPress's generic error page anyway — a fresh nonce, minted and consumed
one HTTP round-trip apart, failing to verify. The exact mechanism wasn't
pinned down (candidates: a host-level edge cache on this GoDaddy install
serving that admin-post.php response across sessions, or the auth cookie
not being read the same way on that request), and chasing it further would
mean guessing blind against production. Instead the fix removes the
dependency entirely:

- `view_lead_report()` no longer requires a nonce at all. It's a read-only
  view gated purely by the `tme_manage_estimates` capability — the same
  model the plugin already uses for the plain estimate "Review" screen
  (`detail_page()`), which has never taken a nonce either. It's also the
  redirect target after sending, so removing the check there removes the
  entire failure mode.
- The "Create lead report" button link and the post-send redirects
  (`email_lead_report()`) no longer build or need a `tme_view_lead_report`
  nonce.
- `email_lead_report()` itself still requires and verifies its own nonce
  (sending mail is the one state-changing step here, so it's the one step
  that should stay CSRF-protected) — that part of rc11 is unchanged.

## 1.2.0-rc13 — delete a lead, one at a time or in bulk

**New:** `TME_DB::delete()` (now tracked here too, since this is the first
change to touch it) — a plain row delete, `!== false`-checked like the
existing `update()`.

**`includes/class-tme-admin.php`:**

- New admin-post actions `tme_delete_estimate` (single) and
  `tme_bulk_delete_estimates` (many). Both call
  `TME_Retention::delete_media($id, true)` first (best-effort R2 cleanup —
  the existing helper already handles photos vs. video vs. nothing to do)
  and then `TME_DB::delete($id)`. Bulk failures aren't itemized, just
  counted ("3 estimates deleted."); the single-delete path does surface a
  storage error if the R2 cleanup failed but still deletes the record.
- `detail_page()`: a "Delete this estimate" card at the bottom of the
  sidebar, styled and confirm-dialog-wired the same way as the existing
  "Delete now" / "Delete all photos" links (`button-link-delete` +
  `data-tme-delete`, already bound by `assets/js/admin.js` on this page —
  no JS changes needed).
- `list_page()`: the table is now wrapped in its own POST form with a
  per-row checkbox, a header "select all" checkbox, and a "Delete
  selected" button. A small inline `<script>` (not worth a separate
  enqueued file) wires select-all and a confirm dialog that also blocks
  submitting with nothing checked.
- New `TME_Admin::parse_ids()` — sanitizes the posted `session_ids[]`
  checkbox values the same way `parse_emails()` sanitizes the recipient
  field (coerce, drop invalid/zero, dedupe).
- `assets/css/admin.css`: `.tme-check-col` (narrow checkbox column) and
  `.tme-bulk-actions .button-link-delete` (red, matching the existing
  `.tme-side-actions` treatment).

The single-delete and bulk-delete request handlers aren't unit tested —
same reasoning as `save_session()`/`delete_video()` etc. elsewhere in this
plugin: they're thin glue over `TME_DB`/`TME_Retention`/R2, not pure
helpers. `parse_ids()` is a pure helper and does have 3 new checks in
`tests/lead-report-harness.php`.

## 1.2.0-rc14 — "Email from my mail app" no longer eats the Call ready screen

**`includes/class-tme-live-call.php`** — `render_call_ready()`: the
`mailto:` "Email from my mail app" link (and the `sms:` "Text from my
phone" link beside it) now carry `target="_blank" rel="noopener"`.

A rep whose browser hands `mailto:` to a **web** mail client (Gmail,
Outlook.com) had the current tab navigated to that compose page, losing
the Call ready screen — rep link included — with no way back. A native
desktop mail app opens out-of-process and never had this problem, which
is why the button "worked on one machine and not another". Opening the
handoff in a new tab fixes it for both.

| File | Change |
|------|--------|
| `tom-moving-estimate.php` | version `1.2.0-rc13` → `1.2.0-rc14` |

Markup-only change to one admin view; `php -l` clean, no harness change
(nothing pure to test). Retest on staging: on the Call ready screen click
"Email from my mail app" and confirm the Gmail/Outlook compose opens in a
new tab with the Call ready screen still there behind it.

## 1.2.0-rc15 — the customer's link now reads as tommoving.ca

The SMS / email a customer receives carried the raw Sites URL
(`…temach.chatgpt.site/video-call/<uuid>?t=<token>`) — nothing about it
said "Tom Moving", which is a hard sell to click from a text.

**`includes/class-tme-live-call.php`:**

- `handle_start()` now also mints a random 16-hex `slug`, stores
  `tme_live_slug_<slug> → <call_id>` (same 7-day TTL as the call
  transient), and keeps the slug on the call transient.
- New `route_public_link()` on `parse_request`: `GET /call/<slug>` looks
  up the call and `wp_redirect()`s (302, `nocache_headers()`) to the real
  `client_url`. Unknown/expired slug → a bilingual `wp_die()` "link
  expired" page (HTTP 410). No auth: the slug is random (64-bit) and the
  URL it forwards to still carries its own signed token — same
  "possession of the link = access" model the raw link already had. No
  rewrite rule / permalink flush; the handler matches the path itself and
  returns immediately on anything that isn't `call/<hex>`.
- New `client_link()` — returns `home_url('/call/<slug>')`, or the raw
  `client_url` for calls started before this (their transient has no
  `slug`). `handle_send()` (SMS + email body) and `render_call_ready()`
  (the copy field, the `sms:` / `mailto:` buttons) all use it.

The visible link is now e.g. `https://www.tommoving.ca/call/3f9a2b7c1d4e6f80`
(exact host follows the WP Site Address). One invisible 302 hop to the
Sites call page.

**Also:** the Call ready screen gets a small **Customer mobile** field (new
`tme_live_set_phone` admin-post → `handle_set_phone()`), so a rep who
started the call without a number can add one there and get the "Text the
link" / "Text from my phone" options — which only render when the call has
a `client_phone` — without starting over. The edit keeps the call's
original expiry (`START_TTL` minus elapsed since `created_at`), not a
fresh 7 days.

| File | Change |
|------|--------|
| `tom-moving-estimate.php` | version `1.2.0-rc14` → `1.2.0-rc15` |

`route_public_link()` / `client_link()` / `handle_set_phone()` aren't
harness-tested — same reasoning as the other request handlers here (thin
glue over transients + `wp_redirect`). Retest on staging: start a call,
confirm the "Send the customer their link" field shows a `tommoving.ca/call/…`
URL, open it in a private window → lands on the call page; let a call's
transient lapse (or hit `/call/<made-up-hex>`) → the 410 "link expired"
page; start a call with no mobile, add one via the new field on the Call
ready screen → "Text the link" / "Text from my phone" appear.

## Checks

- **rc11:** `php -l` clean on every file. `tests/live-call-harness.php` gained
  3 checks for `imported_note()` (correct local-time conversion, rep-name
  fallback, and a regression guard that the raw UTC string is never printed
  verbatim). The nonce-graceful-failure change in `email_lead_report()` has
  no dedicated harness test — it isn't a small pure helper like the rest of
  what these harnesses cover, so it needs a real staging retest instead:
  open "Create lead report", leave the tab for a bit (or just try sending
  right away), confirm the send works, and if "expired" ever shows again,
  confirm it now lands back on the report with the recipient field intact
  rather than a dead-end page.
- **rc10:** `php -l` clean on every file in the plugin. New
  `tests/lead-report-harness.php` (27 checks) covers `TME_Lead_Report::build_text()`
  — core fields, optional sections only appearing when populated, live-call
  vs. photos vs. video submissions, the AI-report summary/rooms/disassembly/
  mattress-bags/open-questions text, and `TME_Admin::parse_emails()`
  (comma/semicolon splitting, trimming, deduping, dropping invalid
  addresses). `tests/live-call-harness.php` and `tests/annotations-harness.php`
  still pass unchanged.
- **rc1-rc9:** `php -l` clean on all files; `php tests/live-call-harness.php`
  and `php tests/annotations-harness.php` (from the repo root) passed —
  helper-logic checks (phone → E.164, link message, settings, cron schedule,
  annotation sanitizing).
- API contract matches the Sites `/api/calls*` smoke in `feat/wp-live-call-api`.
- Verified end-to-end on GoDaddy staging through rc9 (DB upgrade, admin
  screens, a real call → import → row + R2 object + retention).
