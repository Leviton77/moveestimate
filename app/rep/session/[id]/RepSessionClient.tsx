"use client";

import Link from "next/link";
import { FormEvent, useRef, useState } from "react";
import type { SessionRecord, SessionStatus } from "../../../../db/sessions";

type TimestampNote = { id: string; time: number; text: string };

function parseAnnotations(value: string): TimestampNote[] {
  try {
    const parsed = JSON.parse(value) as unknown;
    return Array.isArray(parsed)
      ? parsed.filter((item): item is TimestampNote => Boolean(item && typeof item === "object" && "time" in item && "text" in item))
      : [];
  } catch { return []; }
}

function timeLabel(value: number) {
  const seconds = Math.max(0, Math.floor(value));
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

export function RepSessionClient({ session }: { session: SessionRecord }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [status, setStatus] = useState<SessionStatus>(session.status);
  const [repNotes, setRepNotes] = useState(session.rep_notes ?? "");
  const [annotations, setAnnotations] = useState<TimestampNote[]>(() => parseAnnotations(session.annotations));
  const [noteText, setNoteText] = useState("");
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");

  function addTimestampNote(event: FormEvent) {
    event.preventDefault();
    const text = noteText.trim();
    if (!text) return;
    setAnnotations((current) => [...current, { id: crypto.randomUUID(), time: videoRef.current?.currentTime ?? 0, text }]);
    setNoteText("");
  }

  function seek(time: number) {
    if (!videoRef.current) return;
    videoRef.current.currentTime = time;
    videoRef.current.play().catch(() => undefined);
  }

  async function save() {
    setSaving(true); setSaved(false); setError("");
    try {
      const response = await fetch(`/api/rep/sessions/${session.id}`, {
        method: "PATCH", headers: { "content-type": "application/json" },
        body: JSON.stringify({ status, repNotes, annotations }),
      });
      const body = (await response.json()) as { error?: string };
      if (!response.ok) throw new Error(body.error ?? "Unable to save changes.");
      setSaved(true);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to save changes.");
    } finally { setSaving(false); }
  }

  return (
    <main className="review-shell">
      <div className="review-heading"><div><Link className="back-link" href="/rep/dashboard">← Back to dashboard</Link><h1>{session.client_name}</h1><p>{session.current_address} <span aria-hidden="true">→</span> {session.destination_address}</p></div></div>
      <div className="review-grid">
        <div className="review-main">
          <div className="review-video">{session.video_key ? <video ref={videoRef} controls playsInline preload="metadata" src={`/api/sessions/${session.id}/video`} /> : <div className="video-empty">No walkthrough uploaded yet</div>}</div>
          {session.video_key && <section className="panel timestamp-panel"><div className="panel-heading"><h2>Timestamp notes</h2><span>{annotations.length}</span></div><form onSubmit={addTimestampNote} className="note-form"><input value={noteText} onChange={(event) => setNoteText(event.target.value)} placeholder="Add a note at the current video time…" /><button className="button button--primary button--small" type="submit">Add note</button></form><div className="timestamp-list">{annotations.map((annotation) => <div key={annotation.id}><button type="button" onClick={() => seek(annotation.time)}>{timeLabel(annotation.time)}</button><span>{annotation.text}</span><button className="remove-note" type="button" aria-label={`Remove note at ${timeLabel(annotation.time)}`} onClick={() => setAnnotations((items) => items.filter((item) => item.id !== annotation.id))}>×</button></div>)}{!annotations.length && <p>No timestamp notes yet. Play the video, pause at a useful moment, and add one above.</p>}</div></section>}
        </div>
        <aside className="review-sidebar">
          <section className="panel detail-list"><h2>Client details</h2><dl><dt>Email</dt><dd><a href={`mailto:${session.email}`}>{session.email}</a></dd><dt>Phone</dt><dd><a href={`tel:${session.phone}`}>{session.phone}</a></dd><dt>Move date</dt><dd>{new Date(`${session.move_date}T12:00:00`).toLocaleDateString("en-CA")}</dd><dt>Home size</dt><dd>{session.estimated_size}</dd>{session.special_items && <><dt>Special items</dt><dd>{session.special_items}</dd></>}</dl></section>
          <section className="panel"><label><span>Status</span><select value={status} onChange={(event) => setStatus(event.target.value as SessionStatus)}><option value="new">New</option><option value="reviewed">Reviewed</option><option value="quoted">Quoted</option></select></label></section>
          <section className="panel"><label><span>Estimate notes</span><textarea rows={7} value={repNotes} onChange={(event) => setRepNotes(event.target.value)} placeholder="Internal notes for this estimate…" /></label></section>
          {error && <p className="form-error" role="alert">{error}</p>}
          <button className="button button--primary button--wide" onClick={save} disabled={saving}>{saving ? "Saving…" : saved ? "Saved ✓" : "Save changes"}</button>
        </aside>
      </div>
    </main>
  );
}
