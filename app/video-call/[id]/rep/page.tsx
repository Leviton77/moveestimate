import type { Metadata } from "next";
import { env } from "cloudflare:workers";
import { getVideoSession, isSessionId } from "../../../../db/sessions";
import { verifyCallLinkToken } from "../../../wp-auth";
import { RepCall } from "./RepCall";

export const metadata: Metadata = { title: "Run the call" };
export const dynamic = "force-dynamic";

export default async function RepCallPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ t?: string }>;
}) {
  const { id } = await params;
  const { t } = await searchParams;
  const session = isSessionId(id) ? await getVideoSession(id) : null;
  const authorized = session ? await verifyCallLinkToken(t, id, "rep") : false;

  if (!session || !authorized) {
    return (
      <main className="video-call-page">
        <div className="done-card">
          <div className="done-mark done-mark--warn" aria-hidden="true">!</div>
          <h1>This call link isn&rsquo;t valid</h1>
          <p>It may have expired. Start a new live walkthrough from WordPress.</p>
        </div>
      </main>
    );
  }

  const signalingUrl = (env as unknown as { SIGNALING_URL?: string }).SIGNALING_URL ?? "";
  const wpAdminUrl = (env as unknown as { WP_ADMIN_URL?: string }).WP_ADMIN_URL ?? "";

  return (
    <main className="video-call-page">
      <RepCall
        callId={id}
        repEmail={session.rep_name || session.rep_email}
        signalingUrl={signalingUrl}
        wpAdminUrl={wpAdminUrl}
      />
    </main>
  );
}
