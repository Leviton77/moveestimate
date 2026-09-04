import type { Metadata } from "next";
import { env } from "cloudflare:workers";
import { VideoCallInterface } from "./VideoCallInterface";
import { getVideoSession, isSessionId } from "../../../db/sessions";
import { verifyCallLinkToken } from "../../wp-auth";
import { MESSAGES, parseLocale } from "./messages";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const { id } = await params;
  const session = isSessionId(id) ? await getVideoSession(id) : null;
  const locale = parseLocale(session?.client_locale);
  return { title: MESSAGES[locale].pageTitle };
}

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
  const locale = parseLocale(session?.client_locale);
  const messages = MESSAGES[locale];

  if (!session || !authorized) {
    return (
      <main className="video-call-page">
        <div className="done-card">
          <div className="done-mark done-mark--warn" aria-hidden="true">!</div>
          <h1>{messages.linkNotValidTitle}</h1>
          <p>{messages.linkNotValidBody}</p>
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
        locale={locale}
      />
    </main>
  );
}
