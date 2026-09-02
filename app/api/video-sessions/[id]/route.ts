import { getVideoSession } from "../../../../db/sessions";

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
