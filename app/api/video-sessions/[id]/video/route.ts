import { getVideoSession } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";
import { getRepAccess } from "../../../../rep-auth";

function isVideoSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

/** Parse `Range: bytes=start-end` against a known total size. */
function parseRange(header: string | null, total: number) {
  if (!header) return null;
  const m = /^bytes=(\d*)-(\d*)$/.exec(header.trim());
  if (!m) return null;
  const hasStart = m[1] !== "";
  const hasEnd = m[2] !== "";
  let start: number;
  let end: number;
  if (hasStart) {
    start = Number(m[1]);
    end = hasEnd ? Math.min(Number(m[2]), total - 1) : total - 1;
  } else if (hasEnd) {
    // suffix range: last N bytes
    start = Math.max(0, total - Number(m[2]));
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
 * Streams a recorded call back to an authorized rep, with HTTP range support so
 * the player's scrubber works. Private, no-store.
 */
export async function GET(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return new Response("Not authorized", { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  const session = isVideoSessionId(id) ? await getVideoSession(id) : null;
  if (!session?.video_key) return new Response("Video not found", { status: 404 });

  const contentType = session.video_content_type ?? "video/webm";
  const total = session.video_size ?? 0;
  const range = total > 0 ? parseRange(request.headers.get("range"), total) : null;

  if (range === "invalid") {
    return new Response("Range Not Satisfiable", {
      status: 416,
      headers: { "content-range": `bytes */${total}`, "accept-ranges": "bytes" },
    });
  }

  const bucket = mediaBucket();
  const object = range
    ? await bucket.get(session.video_key, {
        range: { offset: range.start, length: range.end - range.start + 1 },
      })
    : await bucket.get(session.video_key);
  if (!object) return new Response("Video not found", { status: 404 });

  const baseHeaders: Record<string, string> = {
    "content-type": contentType,
    "accept-ranges": "bytes",
    "cache-control": "private, no-store",
  };

  if (range) {
    const length = range.end - range.start + 1;
    return new Response(object.body, {
      status: 206,
      headers: {
        ...baseHeaders,
        "content-length": String(length),
        "content-range": `bytes ${range.start}-${range.end}/${total}`,
      },
    });
  }

  return new Response(object.body, {
    headers: {
      ...baseHeaders,
      "content-length": String(object.size || total),
    },
  });
}
