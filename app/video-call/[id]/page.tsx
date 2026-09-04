import type { Metadata } from "next";
import { env } from "cloudflare:workers";
import { VideoCallInterface } from "./VideoCallInterface";
import { getVideoSession, isSessionId } from "../../../db/sessions";
import { verifyCallLinkToken } from "../../wp-auth";

export const metadata: Metadata = { title: "Live walkthrough" };
export const dynamic = "force-dynamic";

export default async function VideoCallPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ t?: string }>;
}) {
  const { id } = await params;
  const { t } = await searchParams;
  const session = isSessionId(id) ? await getVideoSession(id) : null;
  const authorized = session ? await verifyCallLinkToken(t, id, "client") : false;

  if (!session || !authorized) {
    return (
      <main className="video-call-page">
        <div className="done-card">
          <div className="done-mark done-mark--warn" aria-hidden="true">!</div>
          <h1>This link isn&rsquo;t valid</h1>
          <p>It may have expired. Contact your Tom Moving representative for a new one.</p>
        </div>
      </main>
    );
  }

  const signalingUrl = (env as unknown as { SIGNALING_URL?: string }).SIGNALING_URL ?? "";

  return (
    <main className="video-call-page">
      <VideoCallInterface
        videoSessionId={id}
        repEmail={session.rep_name || session.rep_email}
        signalingUrl={signalingUrl}
      />
    </main>
  );
}
