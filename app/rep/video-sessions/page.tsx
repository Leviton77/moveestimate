import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { SiteHeader } from "../../components/SiteHeader";
import { chatGPTSignOutPath } from "../../chatgpt-auth";
import { getRepAccess } from "../../rep-auth";
import { listVideoSessions } from "../../../db/sessions";

export const metadata: Metadata = { title: "Video calls" };
export const dynamic = "force-dynamic";

function when(value: string) {
  const d = new Date(`${value.replace(" ", "T")}Z`);
  return new Intl.DateTimeFormat("en-CA", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(d);
}

export default async function VideoSessionsPage() {
  const access = await getRepAccess();
  if (!access.authorized) redirect("/rep");
  const sessions = await listVideoSessions();
  const recorded = sessions.filter((s) => s.video_key).length;

  return (
    <>
      <SiteHeader />
      <main className="dashboard-shell">
        <div className="dashboard-heading">
          <div>
            <p className="eyebrow">Representative portal</p>
            <h1>Video calls</h1>
            <p>Signed in as {access.user?.email}</p>
          </div>
          <div className="dashboard-actions">
            <Link className="button button--quiet button--small" href="/rep/dashboard">
              Estimate requests
            </Link>
            <Link
              className="button button--quiet button--small"
              href={chatGPTSignOutPath("/rep")}
            >
              Sign out
            </Link>
          </div>
        </div>

        <div className="metric-grid">
          <div>
            <strong>{sessions.length}</strong>
            <span>Calls</span>
          </div>
          <div>
            <strong>{recorded}</strong>
            <span>Recorded</span>
          </div>
          <div>
            <strong>{sessions.filter((s) => s.contact_source).length}</strong>
            <span>With contact</span>
          </div>
        </div>

        <div className="request-list">
          {sessions.map((s) => (
            <Link
              href={`/rep/video-sessions/${s.id}`}
              className="request-card"
              key={s.id}
            >
              <div>
                <div className="request-card__title">
                  <h2>{s.contact_name || "No contact info"}</h2>
                  <span className={`status-badge status-badge--${s.status === "uploaded" ? "reviewed" : "new"}`}>
                    {s.status}
                  </span>
                  {s.video_key && <span className="status-badge">Recorded</span>}
                  {s.estimate_session_id && <span className="status-badge">Linked estimate</span>}
                </div>
                <p>
                  {s.contact_phone || s.contact_email || "Started by " + s.rep_email}
                </p>
                <small>{when(s.created_at)}</small>
              </div>
              <span className="request-card__arrow" aria-hidden="true">
                ›
              </span>
            </Link>
          ))}
          {!sessions.length && (
            <div className="empty-state">
              <h2>No video calls yet</h2>
              <p>
                Start one from <Link href="/rep/dashboard">Estimate requests</Link> →
                “Start a live call”.
              </p>
            </div>
          )}
        </div>
      </main>
    </>
  );
}
