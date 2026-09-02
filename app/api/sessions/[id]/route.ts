import { getSession, isSessionId } from "../../../../db/sessions";

export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const { id } = await context.params;
  if (!isSessionId(id)) {
    return Response.json({ error: "Session not found." }, { status: 404 });
  }
  const session = await getSession(id);
  if (!session) {
    return Response.json({ error: "Session not found." }, { status: 404 });
  }
  return Response.json({
    session: {
      id: session.id,
      clientName: session.client_name,
      status: session.status,
      videoUploaded: Boolean(session.video_key),
    },
  });
}
