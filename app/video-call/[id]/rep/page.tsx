import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { env } from "cloudflare:workers";
import { getRepAccess } from "../../../rep-auth";
import { getVideoSession } from "../../../../db/sessions";
import { RepCall } from "./RepCall";

export const metadata: Metadata = { title: "Run the call" };
export const dynamic = "force-dynamic";

function isVideoSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

export default async function RepCallPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const access = await getRepAccess();
  if (!access.authorized) redirect("/rep");

  const { id } = await params;
  const session = isVideoSessionId(id) ? await getVideoSession(id) : null;
  if (!session) redirect("/rep/dashboard");

  const signalingUrl = (env as unknown as { SIGNALING_URL?: string }).SIGNALING_URL ?? "";

  return (
    <main className="video-call-page">
      <RepCall
        callId={id}
        repEmail={access.user?.email ?? "the representative"}
        signalingUrl={signalingUrl}
      />
    </main>
  );
}
