import { getVideoSession, isSessionId, markWpIngested } from "../../../../../db/sessions";
import { isWordPressRequest } from "../../../../wp-auth";

/**
 * The WordPress plugin acks that it has pulled a completed call and created the
 * estimate request. Marks the row so the cron backstop stops re-offering it.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  if (!isWordPressRequest(request)) {
    return Response.json({ error: "Not authorized." }, { status: 401 });
  }

  const { id } = await context.params;
  if (!isSessionId(id)) {
    return Response.json({ error: "Invalid call id." }, { status: 400 });
  }
  if (!(await getVideoSession(id))) {
    return Response.json({ error: "Call not found." }, { status: 404 });
  }

  const body = (await request.json().catch(() => ({}))) as Record<string, unknown>;
  const wpRequestId =
    typeof body.wp_request_id === "string" ? body.wp_request_id.slice(0, 64) : "";

  await markWpIngested(id, wpRequestId);
  return Response.json({ ok: true });
}
