import { getVideoSession, isSessionId, saveVideoPart } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";
import { partKey } from "../../../../../db/recording-parts";

const MAX_PART_BYTES = 50 * 1024 * 1024;
const MAX_PARTS = 5000;

/**
 * One piece of a recording, uploaded while the call is running:
 * `POST /api/video-sessions/:id/parts?seq=N`, raw video bytes as the body.
 * Pieces are numbered from 0 and concatenated in order on "complete" (or when
 * the client goes quiet — see db/recording-parts.ts). Re-sending a seq
 * replaces it, so the client can simply retry after a network blip.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isSessionId(id)) {
    return Response.json({ error: "Invalid video session ID." }, { status: 400 });
  }
  const seq = Number(new URL(request.url).searchParams.get("seq"));
  if (!Number.isInteger(seq) || seq < 0 || seq >= MAX_PARTS) {
    return Response.json({ error: "Invalid piece number." }, { status: 400 });
  }

  const session = await getVideoSession(id);
  if (!session) {
    return Response.json({ error: "Video session not found." }, { status: 404 });
  }
  if (session.wp_ingested === 1) {
    return Response.json({ error: "This call has already been imported." }, { status: 409 });
  }

  const contentType = request.headers.get("content-type")?.split(";")[0] ?? "";
  const size = Number(request.headers.get("x-video-size") ?? request.headers.get("content-length") ?? "0");
  if (!contentType.startsWith("video/")) {
    return Response.json({ error: "Please upload a video recording." }, { status: 415 });
  }
  if (!request.body || !(size > 0)) {
    return Response.json({ error: "The piece was empty." }, { status: 400 });
  }
  if (size > MAX_PART_BYTES) {
    return Response.json({ error: "The piece is too large." }, { status: 413 });
  }

  const key = partKey(id, seq);
  try {
    await mediaBucket().put(key, request.body, { httpMetadata: { contentType } });
    await saveVideoPart({ session_id: id, seq, key, size, content_type: contentType });
    return Response.json({ ok: true, seq });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unable to store the piece.";
    return Response.json({ error: message }, { status: 500 });
  }
}
