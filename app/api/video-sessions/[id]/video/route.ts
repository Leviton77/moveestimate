import { getVideoSession } from "../../../../../db/sessions";
import { mediaBucket } from "../../../../../db/media";
import { getRepAccess } from "../../../../rep-auth";

function isVideoSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

/**
 * Streams a recorded call back to an authorized rep. Mirrors
 * app/api/sessions/[id]/video — private, no-store, no range requests.
 */
export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return new Response("Not authorized", { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  const session = isVideoSessionId(id) ? await getVideoSession(id) : null;
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
