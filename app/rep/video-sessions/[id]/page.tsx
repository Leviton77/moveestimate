import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { SiteHeader } from "../../../components/SiteHeader";
import { getRepAccess } from "../../../rep-auth";
import { getVideoSession } from "../../../../db/sessions";
import { VideoSessionDetail } from "./VideoSessionDetail";

export const metadata: Metadata = { title: "Review call" };
export const dynamic = "force-dynamic";

function isVideoSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

export default async function RepVideoSessionPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const access = await getRepAccess();
  if (!access.authorized) redirect("/rep");

  const { id } = await params;
  const session = isVideoSessionId(id) ? await getVideoSession(id) : null;
  if (!session) {
    return (
      <>
        <SiteHeader />
        <div className="status-card status-card--page">
          <h1>Call not found</h1>
          <Link href="/rep/video-sessions" className="button button--quiet">
            Back to video calls
          </Link>
        </div>
      </>
    );
  }

  return (
    <>
      <SiteHeader />
      <VideoSessionDetail session={session} />
    </>
  );
}
