import {
  getSession,
  isSessionId,
  SESSION_STATUSES,
  updateSession,
} from "../../../../../db/sessions";
import { getRepAccess } from "../../../../rep-auth";

export async function PATCH(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return Response.json({ error: "Not authorized." }, { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  if (!isSessionId(id) || !(await getSession(id))) {
    return Response.json({ error: "Session not found." }, { status: 404 });
  }

  const payload = (await request.json()) as Record<string, unknown>;
  const status = typeof payload.status === "string" ? payload.status : "";
  const repNotes = typeof payload.repNotes === "string" ? payload.repNotes.slice(0, 5000) : "";
  const annotations = Array.isArray(payload.annotations) ? payload.annotations.slice(0, 500) : [];
  if (!SESSION_STATUSES.includes(status as (typeof SESSION_STATUSES)[number])) {
    return Response.json({ error: "Invalid status." }, { status: 400 });
  }

  await updateSession(id, {
    status: status as (typeof SESSION_STATUSES)[number],
    repNotes,
    annotations: JSON.stringify(annotations),
  });
  return Response.json({ ok: true });
}
