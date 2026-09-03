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

## Checks

- `php -l` clean on all files.
- `php tests/live-call-harness.php` (from the repo root) — helper-logic checks
  (phone → E.164, link message, settings, cron schedule). Needs `mbstring` or
  the polyfill the harness declares.
- API contract matches the Sites `/api/calls*` smoke in `feat/wp-live-call-api`.
- **Not yet run on a real WordPress** — verify on GoDaddy staging: DB upgrade,
  the admin screens, a real call → import → row + R2 object + retention.
