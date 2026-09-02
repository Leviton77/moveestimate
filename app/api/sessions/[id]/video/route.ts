import { attachVideo, getSession, isSessionId } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";
import { getRepAccess } from "../../../../rep-auth";

const MAX_VIDEO_BYTES = 250 * 1024 * 1024;

export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isSessionId(id) || !(await getSession(id))) {
    return Response.json({ error: "Session not found." }, { status: 404 });
  }

  const contentType = request.headers.get("content-type")?.split(";")[0] ?? "";
  const contentLength = Number(
    request.headers.get("x-video-size") ?? request.headers.get("content-length") ?? "0",
  );
  if (!contentType.startsWith("video/")) {
    return Response.json({ error: "Please upload a video recording." }, { status: 415 });
  }
  if (!request.body) {
    return Response.json({ error: "The recording was empty." }, { status: 400 });
  }
  if (contentLength > MAX_VIDEO_BYTES) {
    return Response.json({ error: "The recording is larger than 250 MB." }, { status: 413 });
  }

  const extension = contentType.includes("mp4") ? "mp4" : "webm";
  const key = `sessions/${id}/${crypto.randomUUID()}.${extension}`;
  const bucket = mediaBucket();
  try {
    await bucket.put(key, request.body, { httpMetadata: { contentType } });
    await attachVideo(id, { key, contentType, size: contentLength });
    return Response.json({ ok: true });
  } catch (error) {
    await bucket.delete(key).catch(() => undefined);
    const message = error instanceof Error ? error.message : "Video upload failed.";
    return Response.json({ error: message }, { status: 500 });
  }
}

export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return Response.json({ error: "Not authorized." }, { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  const session = isSessionId(id) ? await getSession(id) : null;
  if (!session?.video_key) return new Response("Video not found", { status: 404 });

  const object = await mediaBucket().get(session.video_key);
  if (!object) return new Response("Video not found", { status: 404 });

  return new Response(object.body, {
    headers: {
      "content-type": session.video_content_type ?? "video/webm",
      "content-length": String(object.size),
      "cache-control": "private, no-store",
      "accept-ranges": "none",
    },
  });
}
