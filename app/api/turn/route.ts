import { env } from "cloudflare:workers";

type RuntimeEnv = { TURN_KEY_ID?: string; TURN_API_TOKEN?: string };

const STUN: RTCIceServer = { urls: "stun:stun.cloudflare.com:3478" };

/**
 * Hands the browser a set of ICE servers for the live call, including
 * short-lived TURN credentials so the call connects even behind strict NAT.
 *
 * The TURN key stays server-side; the browser only ever sees a credential that
 * expires in an hour. Uses Cloudflare Realtime TURN
 * (https://developers.cloudflare.com/realtime/turn/).
 */
export async function GET() {
  const { TURN_KEY_ID, TURN_API_TOKEN } = env as unknown as RuntimeEnv;

  if (!TURN_KEY_ID || !TURN_API_TOKEN) {
    // No TURN configured — return STUN only. Direct connections still work;
    // strict-NAT clients will fail. `turn: false` lets the UI say so.
    return Response.json({ iceServers: [STUN], turn: false });
  }

  try {
    const res = await fetch(
      `https://rtc.live.cloudflare.com/v1/turn/keys/${TURN_KEY_ID}/credentials/generate`,
      {
        method: "POST",
        headers: {
          authorization: `Bearer ${TURN_API_TOKEN}`,
          "content-type": "application/json",
        },
        body: JSON.stringify({ ttl: 3600 }),
      },
    );
    if (!res.ok) {
      return Response.json({ iceServers: [STUN], turn: false }, { status: 200 });
    }
    const data = (await res.json()) as { iceServers?: RTCIceServer | RTCIceServer[] };
    const turnServers = Array.isArray(data.iceServers)
      ? data.iceServers
      : data.iceServers
        ? [data.iceServers]
        : [];
    return Response.json({ iceServers: [STUN, ...turnServers], turn: turnServers.length > 0 });
  } catch {
    return Response.json({ iceServers: [STUN], turn: false }, { status: 200 });
  }
}
