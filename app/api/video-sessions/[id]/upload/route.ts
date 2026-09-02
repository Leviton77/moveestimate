import { getVideoSession, attachVideoToSession } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";

const MAX_VIDEO_BYTES = 250 * 1024 * 1024;

function isVideoSessionId(value: unknown): value is string {
  return (
    typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
  );
}

export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isVideoSessionId(id)) {
    return Response.json({ error: "Invalid video session ID." }, { status: 400 });
  }

  const session = await getVideoSession(id);
  if (!session) {
    return Response.json({ error: "Video session not found." }, { status: 404 });
  }

  // The recording is sent as the raw request body (not multipart) so it streams
  // straight to R2 — mirrors app/api/sessions/[id]/video. The client puts the
  // byte length in x-video-size because Content-Length is not always present on
  // a streamed upload.
  const contentType = request.headers.get("content-type")?.split(";")[0] ?? "";
  const size = Number(
    request.headers.get("x-video-size") ??
      request.headers.get("content-length") ??
      "0",
  );
  if (!contentType.startsWith("video/")) {
    return Response.json({ error: "Please upload a video recording." }, { status: 415 });
  }
  if (!request.body || size <= 0) {
    return Response.json({ error: "The recording was empty." }, { status: 400 });
  }
  if (size > MAX_VIDEO_BYTES) {
    return Response.json({ error: "The recording is larger than 250 MB." }, { status: 413 });
  }

  const extension = contentType.includes("mp4") ? "mp4" : "webm";
  const key = `video-sessions/${id}/${crypto.randomUUID()}.${extension}`;
  const bucket = mediaBucket();
  try {
    await bucket.put(key, request.body, { httpMetadata: { contentType } });
    await attachVideoToSession(id, { key, contentType, size });
    return Response.json({ ok: true, videoKey: key });
  } catch (error) {
    await bucket.delete(key).catch(() => undefined);
    const message = error instanceof Error ? error.message : "Unable to upload video.";
    return Response.json({ error: message }, { status: 500 });
  }
}
