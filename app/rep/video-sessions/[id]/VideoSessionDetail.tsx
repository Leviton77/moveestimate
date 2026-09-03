"use client";

import Link from "next/link";
import { type FormEvent, useState } from "react";
import type { VideoSessionRecord } from "../../../../db/sessions";

function when(value: string) {
  return new Date(`${value.replace(" ", "T")}Z`).toLocaleString("en-CA");
}

function sizeLabel(bytes: number | null) {
  if (!bytes) return "—";
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function VideoSessionDetail({ session }: { session: VideoSessionRecord }) {
  const [name, setName] = useState(session.contact_name ?? "");
  const [phone, setPhone] = useState(session.contact_phone ?? "");
  const [email, setEmail] = useState(session.contact_email ?? "");
  const [note, setNote] = useState(session.contact_note ?? "");
  const [source, setSource] = useState(session.contact_source);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");

  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setSaved(false);
    setError("");
    try {
      const res = await fetch(`/api/video-sessions/${session.id}/contact`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ name, phone, email, note }),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(body.error || "Could not save.");
      }
      setSource("rep-entered");
      setSaved(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="review-shell">
      <div className="review-heading">
        <div>
          <Link className="back-link" href="/rep/video-sessions">
            ← Back to video calls
          </Link>
          <h1>{session.contact_name || "Video call"}</h1>
          <p>
            {when(session.created_at)} · started by {session.rep_email}
          </p>
        </div>
      </div>

      <div className="review-grid">
        <div className="review-main">
          <div className="review-video">
            {session.video_key ? (
              <video
                controls
                playsInline
                preload="metadata"
                src={`/api/video-sessions/${session.id}/video`}
              />
            ) : (
              <div className="video-empty">
                No recording — status is “{session.status}”.
              </div>
            )}
          </div>
          {session.estimate_session_id && (
            <section className="panel">
              <p>
                Linked to estimate{" "}
                <Link href={`/rep/session/${session.estimate_session_id}`}>
                  {session.estimate_session_id.slice(0, 8)}
                </Link>
                .
              </p>
            </section>
          )}
        </div>

        <aside className="review-sidebar">
          <section className="panel detail-list">
            <h2>Call</h2>
            <dl>
              <dt>Status</dt>
              <dd>{session.status}</dd>
              <dt>Recording</dt>
              <dd>{sizeLabel(session.video_size)}</dd>
              <dt>Rep</dt>
              <dd>{session.rep_email}</dd>
            </dl>
          </section>

          <form className="panel" onSubmit={save}>
            <h2>
              Client contact{" "}
              {source && (
                <span className="status-badge">
                  {source === "client-form" ? "from client" : "entered by rep"}
                </span>
              )}
            </h2>
            <label>
              <span>Name</span>
              <input value={name} onChange={(e) => setName(e.target.value)} />
            </label>
            <label>
              <span>Phone</span>
              <input
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                inputMode="tel"
              />
            </label>
            <label>
              <span>Email</span>
              <input
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                inputMode="email"
              />
            </label>
            <label>
              <span>Notes</span>
              <textarea
                rows={4}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Anything the rep knows about this client…"
              />
            </label>
            {error && (
              <p className="form-error" role="alert">
                {error}
              </p>
            )}
            <button
              className="button button--primary button--wide"
              type="submit"
              disabled={saving}
            >
              {saving ? "Saving…" : saved ? "Saved ✓" : "Save contact"}
            </button>
          </form>
        </aside>
      </div>
    </main>
  );
}
