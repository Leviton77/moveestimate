import { createVideoSession } from "../../../db/sessions";

function getJoinOrigin(request: Request): string {
  const url = new URL(request.url);
  const forwardedHost = request.headers.get("x-forwarded-host");
  const requestHost = request.headers.get("host");
  const incomingHost = forwardedHost || requestHost;
  const incomingProtocol = request.headers.get("x-forwarded-proto") || url.protocol.replace(":", "");

  if (incomingHost && !/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/i.test(incomingHost)) {
    return `${incomingProtocol}://${incomingHost}`;
  }

  // In development, try to use local IP instead of localhost
  if (process.env.NODE_ENV !== 'development') {
    return new URL(url).origin;
  }

  // Check for custom host from environment variable
  const customHost = process.env.VITE_HOST || process.env.HOST;
  if (customHost && customHost !== 'localhost') {
    const urlObj = new URL(url);
    return `http://${customHost}:${urlObj.port || 3000}`;
  }

  // Try to detect local IP from request headers
  const urlObj = new URL(url);
  const hostHeader = urlObj.host;
  
  // If host is already an IP, use it
  if (/^\d+\.\d+\.\d+\.\d+/.test(hostHeader)) {
    return urlObj.origin;
  }

  // Default to localhost (can be manually changed by user)
  return urlObj.origin;
}

export async function POST(request: Request) {
  try {
    const payload = (await request.json()) as Record<string, unknown>;

    // Optional repEmail (defaults to "anonymous" for standalone video calls)
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
