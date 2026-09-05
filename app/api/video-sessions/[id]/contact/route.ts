import {
  getVideoSession,
  setVideoSessionContact,
  type VideoSessionContact,
} from "../../../../../db/sessions";

function isVideoSessionId(value: unknown): value is string {
  return (
    typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
  );
}

function readContact(payload: Record<string, unknown>): VideoSessionContact {
  const str = (v: unknown, max: number) =>
    typeof v === "string" ? v.trim().slice(0, max) : "";
  const moveDate = str(payload.moveDate, 10);
  return {
    name: str(payload.name, 200),
    phone: str(payload.phone, 60),
    email: str(payload.email, 200),
    note: str(payload.note, 2000),
    // Loosely validated: an <input type="date"> always yields YYYY-MM-DD:
    // drop anything else rather than reject the whole (optional) form over it.
    moveDate: /^\d{4}-\d{2}-\d{2}$/.test(moveDate) ? moveDate : "",
    homeSize: str(payload.homeSize, 40),
    currentAddress: str(payload.currentAddress, 240),
    destinationAddress: str(payload.destinationAddress, 240),
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
  if (!contact.name) {
    return Response.json({ error: "Please add your name." }, { status: 400 });
  }
  await setVideoSessionContact(id, contact, "client-form");
  return Response.json({ ok: true });
}
