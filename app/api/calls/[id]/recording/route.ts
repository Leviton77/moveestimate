import { env } from "cloudflare:workers";
import { getVideoSession, isSessionId } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";
import { verifyCallToken } from "../../../../call-token";

type RuntimeEnv = { WP_SHARED_SECRET?: string };

/** Parse `Range: bytes=start-end` against a known total size. */
function parseRange(header: string | null, total: number) {
  if (!header) return null;
  const match = /^bytes=(\d*)-(\d*)$/.exec(header.trim());
  if (!match) return null;
  const hasStart = match[1] !== "";
  const hasEnd = match[2] !== "";
  let start: number;
  let end: number;
  if (hasStart) {
    start = Number(match[1]);
    end = hasEnd ? Math.min(Number(match[2]), total - 1) : total - 1;
  } else if (hasEnd) {
    start = Math.max(0, total - Number(match[2]));
    end = total - 1;
  } else {
    return null;
  }
  if (Number.isNaN(start) || Number.isNaN(end) || start > end || start >= total) {
    return "invalid" as const;
  }
  return { start, end };
}

/**
 * Streams a recorded call to the WordPress plugin. Authorized by a short-lived
 * `recording`-scope token minted in `GET /api/calls/:id` (not a login) so the
 * plugin can pull the video into its own R2. Supports HTTP range requests.
 */
export async function GET(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isSessionId(id)) return new Response("Invalid call id", { status: 400 });

  const secret = (env as unknown as RuntimeEnv).WP_SHARED_SECRET;
  const token = new URL(request.url).searchParams.get("t") ?? "";
  const claims = secret ? await verifyCallToken(secret, token) : null;
  if (!claims || claims.cid !== id || claims.scp !== "recording") {
    return new Response("Not authorized", { status: 401 });
  }

  const call = await getVideoSession(id);
  if (!call?.video_key) return new Response("Recording not found", { status: 404 });

  const contentType = call.video_content_type ?? "video/mp4";
  const total = call.video_size ?? 0;
  const range = total > 0 ? parseRange(request.headers.get("range"), total) : null;

  if (range === "invalid") {
    return new Response("Range Not Satisfiable", {
      status: 416,
      headers: { "content-range": `bytes */${total}`, "accept-ranges": "bytes" },
    });
  }

  const bucket = mediaBucket();
  const object = range
    ? await bucket.get(call.video_key, {
        range: { offset: range.start, length: range.end - range.start + 1 },
      })
    : await bucket.get(call.video_key);
  if (!object) return new Response("Recording not found", { status: 404 });

  const headers: Record<string, string> = {
    "content-type": contentType,
    "accept-ranges": "bytes",
    "cache-control": "private, no-store",
  };

  if (range) {
    const length = range.end - range.start + 1;
    return new Response(object.body, {
      status: 206,
      headers: {
        ...headers,
        "content-length": String(length),
        "content-range": `bytes ${range.start}-${range.end}/${total}`,
      },
    });
  }

  return new Response(object.body, {
    headers: { ...headers, "content-length": String(object.size || total) },
  });
}
