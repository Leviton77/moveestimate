import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { SiteHeader } from "../../components/SiteHeader";
import { VideoLinkGenerator } from "../../components/VideoLinkGenerator";
import { chatGPTSignOutPath } from "../../chatgpt-auth";
import { getRepAccess } from "../../rep-auth";
import { listSessions } from "../../../db/sessions";

export const metadata: Metadata = { title: "Estimate dashboard" };
export const dynamic = "force-dynamic";

function displayDate(value: string) {
  return new Intl.DateTimeFormat("en-CA", { year: "numeric", month: "short", day: "numeric" }).format(new Date(`${value}T12:00:00`));
}

export default async function DashboardPage() {
  const access = await getRepAccess();
  if (!access.authorized) redirect("/rep");
  const sessions = await listSessions();
  const counts = { new: 0, reviewed: 0, quoted: 0 };
  sessions.forEach((session) => { if (session.status in counts) counts[session.status] += 1; });
  return (
    <><SiteHeader /><main className="dashboard-shell"><div className="dashboard-heading"><div><p className="eyebrow">Representative portal</p><h1>Estimate requests</h1><p>Signed in as {access.user?.email}</p></div><div className="dashboard-actions"><VideoLinkGenerator repEmail={access.user?.email || ""} /><Link className="button button--quiet button--small" href="/rep/video-sessions">Video calls</Link><Link className="button button--quiet button--small" href={chatGPTSignOutPath("/rep")}>Sign out</Link></div></div>
      <div className="metric-grid"><div><strong>{counts.new}</strong><span>New</span></div><div><strong>{counts.reviewed}</strong><span>Reviewed</span></div><div><strong>{counts.quoted}</strong><span>Quoted</span></div></div>
      <div className="request-list">
        {sessions.map((session) => <Link href={`/rep/session/${session.id}`} className="request-card" key={session.id}><div><div className="request-card__title"><h2>{session.client_name}</h2><span className={`status-badge status-badge--${session.status}`}>{session.status}</span>{session.video_key && <span className="status-badge">Video ready</span>}</div><p>{session.current_address} <span aria-hidden="true">→</span> {session.destination_address}</p><small>{session.estimated_size} · Move {displayDate(session.move_date)}</small></div><span className="request-card__arrow" aria-hidden="true">›</span></Link>)}
        {!sessions.length && <div className="empty-state"><h2>No estimate requests yet</h2><p>New customer submissions will appear here automatically.</p></div>}
      </div>
    </main></>
  );
}
