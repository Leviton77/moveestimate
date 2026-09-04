import { createWpCall, listWpCallsAwaitingIngest } from "../../../db/sessions";
import { mintCallToken } from "../../call-token";
import { callLinkOrigin, isWordPressRequest, wpSharedSecret } from "../../wp-auth";

const CLIENT_TTL_SECONDS = 7 * 24 * 60 * 60;
const REP_TTL_SECONDS = 2 * 24 * 60 * 60;

const str = (value: unknown, max: number) =>
  typeof value === "string" ? value.trim().slice(0, max) : "";

/**
 * Create a live call. Called server-to-server by the WordPress plugin when a
 * rep starts a walkthrough. Returns the rep and client links (each carries a
 * signed token so the call pages open without a ChatGPT login).
 */
export async function POST(request: Request) {
  const secret = wpSharedSecret();
  if (!secret || !isWordPressRequest(request)) {
    return Response.json({ error: "Not authorized." }, { status: 401 });
  }

  const payload = (await request.json().catch(() => ({}))) as Record<string, unknown>;

  const repEmail = str(payload.rep_email, 200);
  if (repEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(repEmail)) {
    return Response.json({ error: "Invalid rep_email." }, { status: 400 });
  }

  const callId = await createWpCall({
    repEmail,
    repName: str(payload.rep_name, 200),
    clientLocale: payload.client_locale === "fr" ? "fr" : "en",
    contact: {
      name: str(payload.client_name, 200),
      phone: str(payload.client_phone, 60),
      email: str(payload.client_email, 200),
    },
  });

  const base = callLinkOrigin(request);
  const [repToken, clientToken] = await Promise.all([
    mintCallToken(secret, callId, "rep", REP_TTL_SECONDS),
    mintCallToken(secret, callId, "client", CLIENT_TTL_SECONDS),
  ]);

  return Response.json(
    {
      call_id: callId,
      rep_url: `${base}/video-call/${callId}/rep?t=${repToken}`,
      client_url: `${base}/video-call/${callId}?t=${clientToken}`,
      client_expires_at: new Date(Date.now() + CLIENT_TTL_SECONDS * 1000).toISOString(),
    },
    { status: 201 },
  );
}

/**
 * List completed calls the plugin has not pulled yet. Used by the plugin's cron
 * backstop for calls where the rep closed the tab instead of clicking through.
 * `GET /api/calls?ingested=0`
 */
export async function GET(request: Request) {
  if (!isWordPressRequest(request)) {
    return Response.json({ error: "Not authorized." }, { status: 401 });
  }
  const url = new URL(request.url);
  if (url.searchParams.get("ingested") !== "0") {
    return Response.json({ calls: [] });
  }
  const rows = await listWpCallsAwaitingIngest();
  return Response.json({
    calls: rows.map((row) => ({
      call_id: row.id,
      status: row.status,
      created_at: row.created_at,
      rep_email: row.rep_email,
      rep_name: row.rep_name ?? "",
    })),
  });
}
