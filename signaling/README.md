# Signaling Worker — live estimate call

A tiny Cloudflare Worker that brokers the WebRTC handshake for the rep ↔ client
live walkthrough call. One Durable Object instance per call id acts as the room.

It is **not** part of the Sites deployment. It runs in your own Cloudflare
account and is deployed on its own.

## Deploy

```bash
cd signaling
npm install
npx wrangler login          # once, to authorise wrangler with your account
npm run deploy
```

> The `dev` and `deploy` scripts pass `--config wrangler.jsonc` explicitly.
> Without it, wrangler run from this folder can pick up the Sites app's
> generated `../.wrangler/deploy/config.json` and refuse to start.

`wrangler deploy` prints the URL, e.g.
`https://moveestimate-signaling.<your-subdomain>.workers.dev`.

The Sites app talks to it over WebSocket, so set that URL (with the `wss://`
scheme) as the `SIGNALING_URL` environment variable on the Sites deployment:

```
SIGNALING_URL = wss://moveestimate-signaling.<your-subdomain>.workers.dev
```

## Local dev

```bash
npm run dev        # wrangler dev, serves on http://localhost:8787
```

Point the Sites app at `SIGNALING_URL = ws://localhost:8787` while developing.

## Protocol

Both browsers open:

```
wss://<worker>/call/<callId>?role=rep|client
```

`callId` is the `video_sessions` id (a v4 UUID). The Worker relays every message
between the two peers untouched, stamping `from: "rep" | "client"` onto JSON
messages. It injects `welcome`, `peer-joined`, `peer-left`, and `room-full`.
See `src/room.ts` for the full list.

There is no auth beyond knowing the call id — same trust model as the client
join link. Tighten later by having the Sites app mint a short signed token that
the Worker verifies.
