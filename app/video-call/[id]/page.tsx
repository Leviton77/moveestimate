import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { VideoCallInterface } from "./VideoCallInterface";
import { getVideoSession } from "../../../db/sessions";

export const metadata: Metadata = { title: "Video Call - Tom Moving" };

interface VideoCallPageProps {
  params: {
    id: string;
  };
}

export default async function VideoCallPage({ params }: VideoCallPageProps) {
  // Validate the video session ID exists
  const session = await getVideoSession(params.id);

  if (!session) {
    redirect("/");
  }

  return (
    <main className="video-call-page">
      <VideoCallInterface videoSessionId={params.id} repEmail={session.rep_email} />
    </main>
  );
}
