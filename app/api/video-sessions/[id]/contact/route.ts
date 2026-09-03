import {
  getVideoSession,
  setVideoSessionContact,
  type VideoSessionContact,
} from "../../../../../db/sessions";
import { getRepAccess } from "../../../../rep-auth";

function isVideoSessionId(value: unknown): value is string {
  return (
    typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
  );
}

function readContact(payload: Record<string, unknown>): VideoSessionContact {
  const str = (v: unknown, max: number) =>
    typeof v === "string" ? v.trim().slice(0, max) : "";
  return {
    name: str(payload.name, 200),
    phone: str(payload.phone, 60),
    email: str(payload.email, 200),
    note: str(payload.note, 2000),
  };
}

/**
 * Client-submitted contact form, sent from the live call when the rep asks for
 * it. No auth — the call id is the secret, same as joining the call.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isVideoSessionId(id)) {
    return Response.json({ error: "Invalid video session ID." }, { status: 400 });
  }
  if (!(await getVideoSession(id))) {
    return Response.json({ error: "Video session not found." }, { status: 404 });
  }

  const payload = (await request.json().catch(() => ({}))) as Record<string, unknown>;
  const contact = readContact(payload);
  if (!contact.name && !contact.phone && !contact.email) {
    return Response.json(
      { error: "Add at least a name, phone, or email." },
      { status: 400 },
    );
  }
  await setVideoSessionContact(id, contact, "client-form");
  return Response.json({ ok: true });
}

/**
 * Rep-entered contact details from the review page. Rep auth required.
 */
export async function PATCH(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const access = await getRepAccess();
  if (!access.authorized) {
    return Response.json({ error: "Not authorized." }, { status: access.user ? 403 : 401 });
  }

  const { id } = await context.params;
  if (!isVideoSessionId(id) || !(await getVideoSession(id))) {
    return Response.json({ error: "Video session not found." }, { status: 404 });
  }

  const payload = (await request.json().catch(() => ({}))) as Record<string, unknown>;
  await setVideoSessionContact(id, readContact(payload), "rep-entered");
  return Response.json({ ok: true });
}
