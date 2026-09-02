import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { VideoCallInterface } from "./VideoCallInterface";
import { getVideoSession } from "../../../db/sessions";

export const metadata: Metadata = { title: "Video Call - Tom Moving" };
export const dynamic = "force-dynamic";

function isVideoSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

export default async function VideoCallPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const session = isVideoSessionId(id) ? await getVideoSession(id) : null;

  if (!session) {
    redirect("/");
  }

  return (
    <main className="video-call-page">
      <VideoCallInterface videoSessionId={id} repEmail={session.rep_email} />
    </main>
  );
}
