import { getVideoSession, isSessionId } from "../../../../db/sessions";
import { mintCallToken } from "../../../call-token";
import { callLinkOrigin, isWordPressRequest, wpSharedSecret } from "../../../wp-auth";

const RECORDING_TTL_SECONDS = 30 * 60;

/**
 * Pull one call's result. Called server-to-server by the WordPress plugin after
 * the rep clicks "Finish in Tom Estimator" (and by the cron backstop). Returns
 * the captured contact details and, once the recording is uploaded, a
 * short-lived signed URL the plugin uses to fetch the video into its own R2.
 */
export async function GET(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const secret = wpSharedSecret();
  if (!secret || !isWordPressRequest(request)) {
    return Response.json({ error: "Not authorized." }, { status: 401 });
  }

  const { id } = await context.params;
  if (!isSessionId(id)) {
    return Response.json({ error: "Invalid call id." }, { status: 400 });
  }

  const call = await getVideoSession(id);
  if (!call) {
    return Response.json({ error: "Call not found." }, { status: 404 });
  }

  let recording: {
    url: string;
    content_type: string;
    size: number;
  } | null = null;
  if (call.video_key && (call.status === "uploaded" || call.status === "completed")) {
    const token = await mintCallToken(secret, id, "recording", RECORDING_TTL_SECONDS);
    recording = {
      url: `${callLinkOrigin(request)}/api/calls/${id}/recording?t=${token}`,
      content_type: call.video_content_type ?? "video/mp4",
      size: call.video_size ?? 0,
    };
  }

  return Response.json({
    call_id: id,
    status: call.status,
    ingested: call.wp_ingested === 1,
    rep_email: call.rep_email,
    rep_name: call.rep_name ?? "",
    created_at: call.created_at,
    updated_at: call.updated_at,
    contact: {
      name: call.contact_name ?? "",
      phone: call.contact_phone ?? "",
      email: call.contact_email ?? "",
      note: call.contact_note ?? "",
      move_date: call.contact_move_date ?? "",
      home_size: call.contact_home_size ?? "",
      current_address: call.contact_current_address ?? "",
      destination_address: call.contact_destination_address ?? "",
      source: call.contact_source ?? null,
    },
    recording,
  });
}
