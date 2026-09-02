import { createVideoSession } from "../../../db/sessions";

// Build the public origin for the customer join link. Prefer the proxy's
// forwarded headers (production runs behind the Sites edge), then the Host
// header, then the request URL itself. Mirrors app/layout.tsx.
function getJoinOrigin(request: Request): string {
  const url = new URL(request.url);
  const host =
    request.headers.get("x-forwarded-host") ??
    request.headers.get("host") ??
    url.host;
  const protocol =
    request.headers.get("x-forwarded-proto") ?? url.protocol.replace(":", "");
  return `${protocol}://${host}`;
}

export async function POST(request: Request) {
  try {
    const payload = (await request.json()) as Record<string, unknown>;

    // Optional repEmail; falls back to a placeholder for standalone video calls
    // created without a signed-in representative.
    let repEmail = typeof payload.repEmail === "string" ? payload.repEmail.trim() : "";
    if (!repEmail) {
      repEmail = "customer@video-walkthrough.local";
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(repEmail)) {
      return Response.json(
        { error: "Please provide a valid representative email." },
        { status: 400 },
      );
    }

    // Optional estimate session ID
    const estimateSessionId =
      typeof payload.estimateSessionId === "string" ? payload.estimateSessionId.trim() : undefined;

    const videoSessionId = await createVideoSession(repEmail, estimateSessionId);
    const baseUrl = getJoinOrigin(request);
    const joinLink = `${baseUrl}/video-call/${videoSessionId}`;

    return Response.json({ videoSessionId, joinLink }, { status: 201 });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unable to create video session.";
    return Response.json({ error: message }, { status: 500 });
  }
}
