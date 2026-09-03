import { deleteVideoSession, getVideoSession } from "../../../../db/sessions";
import { mediaBucket } from "../../../../db/media";
import { getRepAccess } from "../../../rep-auth";

function isVideoSessionId(value: unknown): value is string {
  return (
    typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
  );
}

export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  try {
    const { id } = await context.params;
    if (!isVideoSessionId(id)) {
      return Response.json({ error: "Invalid video session ID." }, { status: 400 });
    }

    const session = await getVideoSession(id);
    if (!session) {
      return Response.json({ error: "Video session not found." }, { status: 404 });
    }

    return Response.json(session, { status: 200 });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unable to get video session.";
    return Response.json({ error: message }, { status: 500 });
  }
}

/**
 * Permanently erases a call: the recording in R2 and the row in D1. Rep only.
 */
export async function DELETE(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return Response.json({ error: "Not authorized." }, { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  if (!isVideoSessionId(id)) {
    return Response.json({ error: "Invalid video session ID." }, { status: 400 });
  }

  const session = await getVideoSession(id);
  if (!session) {
    return Response.json({ error: "Video session not found." }, { status: 404 });
  }

  if (session.video_key) {
    await mediaBucket()
      .delete(session.video_key)
      .catch(() => undefined);
  }
  await deleteVideoSession(id);
  return Response.json({ ok: true });
}
