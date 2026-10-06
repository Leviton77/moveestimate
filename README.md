# vinext-starter

A clean full-stack starter running on
[vinext](https://github.com/cloudflare/vinext), with Cloudflare D1 and R2
support.

## Prerequisites

- Node.js `>=22.13.0`

## Quick Start

```bash
npm install
npm run dev
npm run build
```

This starter does not use `wrangler.jsonc`.

## Included Shape

- edit site code under `app/`
- `.openai/hosting.json` declares the Sites D1 and R2 bindings
- `vite.config.ts` simulates declared bindings for local development
- `db/sessions.ts` and `db/media.ts` are the D1 and R2 access layers; the
  `sessions` and `video_sessions` tables are created on first use

## Hosting and deploy

Since 2026-10-06 the app is **self-hosted in Tom Moving's own Cloudflare
account** (moved off the ChatGPT Sites platform so changes can ship without
ChatGPT): `https://moveestimate.artlinco.workers.dev`, with the signaling
Worker at `https://moveestimate-signaling.artlinco.workers.dev`.

```bash
npx wrangler login     # once per machine
npm run deploy         # vinext build && wrangler deploy (config: wrangler.jsonc)
```

`wrangler.jsonc` holds the bindings (D1 `moveestimate` as `DB`, R2
`moveestimate-media` as `MEDIA`) and the non-secret vars (`SIGNALING_URL`,
`WP_ADMIN_URL`). Secrets are set with `npx wrangler secret put <NAME>`:
`WP_SHARED_SECRET`, `TURN_KEY_ID`, `TURN_API_TOKEN`. Tables are created on
first request (`ensureDatabase()`), so a fresh D1 needs no migration.

### Progressive recording upload

The client's recording is uploaded in numbered pieces every 10 s during the
call (`POST /api/video-sessions/:id/parts?seq=N`), then finished with
`POST /api/video-sessions/:id/complete` (`{ "parts": N }`), which seals the
recording as those pieces (`video_key = "parts:<N>"`, no copy);
`GET /api/calls/:id/recording` streams them back to back as one file (range
requests supported). No size ceiling besides a 2 GB sanity cap — WordPress's
"Maximum video size" is the real limit. Recording runs at ~1.5 Mbps video
(≈11 MB/min, ≈330 MB for 30 min).

### Storage cleanup

A daily cron (`17 7 * * *`, `scheduled()` in `worker/index.ts` →
`db/cleanup.ts`) deletes this app's copy of a recording
`CLEANUP_AFTER_IMPORT_DAYS` (3) days after WordPress imported it — WordPress
keeps its own copy under its own 30-day retention — and anything older than
`CLEANUP_MAX_AGE_DAYS` (45) regardless. Cleaned calls get `media_deleted_at`
and `video_key = NULL`. Test locally with `CLEANUP_AFTER_IMPORT_DAYS=0` in
`.dev.vars` and `GET /cdn-cgi/handler/scheduled`. If the customer closes the tab before that, a
call with no new piece for 5 minutes is assembled from the pieces that arrived
when WordPress next asks (`GET /api/calls?ingested=0`, `GET /api/calls/:id`).
See `db/recording-parts.ts`. The old one-shot `/upload` route stays for call
pages opened before this change.

## Live estimate call

The rep-initiated video walkthrough (`/video-call/:id` for the client,
`/video-call/:id/rep` for the rep) needs a WebRTC signaling channel, which the
Sites runtime can't host. It runs as a **separate Cloudflare Worker** in your
own account — source in [`signaling/`](./signaling), deployed with its own
`wrangler deploy`. See [`signaling/README.md`](./signaling/README.md).

The Sites app then needs three env vars (copy `.dev.vars.example` to `.dev.vars`
for local dev; set them on the deployment for production):

| Variable | Purpose |
| --- | --- |
| `SIGNALING_URL` | `wss://` origin of the signaling Worker. Empty ⇒ the call is disabled and the walkthrough is recorded solo. |
| `TURN_KEY_ID`, `TURN_API_TOKEN` | Cloudflare Realtime TURN key. Without them the call is STUN-only and fails behind strict NAT. Served to the browser as short-lived credentials by `GET /api/turn`. |
| `WP_SHARED_SECRET` | Shared secret with the "Tom Moving Estimate" WordPress plugin. Server-to-server calls (`/api/calls*`) send it as `Authorization: Bearer`; it also signs the rep/client call-link tokens — that token, not a login, is what gates `/video-call/:id` and `/video-call/:id/rep` (see `app/call-token.ts`, `app/wp-auth.ts`). |
| `WP_ADMIN_URL` | **Fallback** base WordPress admin URL for the rep's "Finish in Tom Estimator" link. Each call now records the admin URL of the WordPress it was started from (`wp_admin_url` on `POST /api/calls`); this env var is only used for calls that didn't send one. Set it to whichever WordPress is primary. |

The rep starts a call from WordPress (`Move Estimates → Live Walkthrough`), which
calls `POST /api/calls` and gets back signed rep/client links. When the call
ends, the rep clicks "Finish in Tom Estimator", which pulls the recording and
contact details back into WordPress as a new estimate request. See
[`wordpress-plugin/README.md`](./wordpress-plugin/README.md) for the plugin
side.

## Workspace Auth Headers

OpenAI workspace sites can read the current user's email from
`oai-authenticated-user-email`.

SIWC-authenticated workspace sites may also receive
`oai-authenticated-user-full-name` when the user's SIWC profile has a non-empty
`name` claim. The full-name value is percent-encoded UTF-8 and is accompanied by
`oai-authenticated-user-full-name-encoding: percent-encoded-utf-8`.

Treat the full name as optional and fall back to email when it is absent:

```tsx
import { headers } from "next/headers";

export default async function Home() {
  const requestHeaders = await headers();
  const email = requestHeaders.get("oai-authenticated-user-email");
  const encodedFullName = requestHeaders.get("oai-authenticated-user-full-name");
  const fullName =
    encodedFullName &&
    requestHeaders.get("oai-authenticated-user-full-name-encoding") ===
      "percent-encoded-utf-8"
      ? decodeURIComponent(encodedFullName)
      : null;

  const displayName = fullName ?? email;
  // ...
}
```

## Optional Dispatch-Owned ChatGPT Sign-In

Import the ready-to-use helpers from `app/chatgpt-auth.ts` when the site needs
optional or required ChatGPT sign-in:

- Use `getChatGPTUser()` for optional signed-in UI.
- Use `requireChatGPTUser(returnTo)` for server-rendered pages that should send
  anonymous visitors through Sign in with ChatGPT.
- Use `chatGPTSignInPath(returnTo)` and `chatGPTSignOutPath(returnTo)` for
  browser links or actions.
- Pass a same-origin relative `returnTo` path for the destination after sign-in
  or sign-out. The helper validates and safely encodes it.
- Mark protected pages with `export const dynamic = "force-dynamic"` because
  they depend on per-request identity headers.

Dispatch owns `/signin-with-chatgpt`, `/signout-with-chatgpt`, `/callback`, the
OAuth cookies, and identity header injection. Do not implement app routes for
those reserved paths. Routes that do not import and call the helper remain
anonymous-compatible.

SIWC establishes identity only; it does not prove workspace membership. Use the
Sites hosting platform's access policy controls for workspace-wide restrictions,
or enforce explicit server-side membership or allowlist checks.

Use SIWC for account pages, user-specific dashboards, saved records, and write
actions tied to the current ChatGPT user. Leave public content anonymous.

## Useful Commands

- `npm run dev`: start local development
- `npm run build`: verify the vinext build output
- `npm test`: build the starter and verify its rendered loading skeleton

## Learn More

- [vinext Documentation](https://github.com/cloudflare/vinext)
