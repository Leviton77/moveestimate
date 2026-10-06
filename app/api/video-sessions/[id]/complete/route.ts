import { isSessionId } from "../../../../../db/sessions";
import { assembleRecording } from "../../../../../db/recording-parts";

/**
 * The client finished uploading: `POST /api/video-sessions/:id/complete` with
 * `{ "parts": N }` — the number of pieces it sent (seq 0..N-1). Builds the
 * recording from them and marks the call uploaded for WordPress. Fails with
 * 409 if a piece is missing, so the client can resend it and try again.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isSessionId(id)) {
    return Response.json({ error: "Invalid video session ID." }, { status: 400 });
  }
  const payload = (await request.json().catch(() => ({}))) as { parts?: unknown };
  const parts = Number(payload.parts);
  if (!Number.isInteger(parts) || parts <= 0) {
    return Response.json({ error: "Nothing was recorded." }, { status: 400 });
  }

  const result = await assembleRecording(id, { expectedCount: parts, deleteParts: true });
  if (!result.ok) {
    const status = result.error.startsWith("Missing") ? 409 : 500;
    return Response.json({ error: result.error }, { status });
  }
  return Response.json({ ok: true, parts: result.parts, size: result.size });
}
