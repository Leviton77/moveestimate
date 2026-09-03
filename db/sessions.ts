import { env } from "cloudflare:workers";

export const SESSION_STATUSES = ["new", "reviewed", "quoted"] as const;
export type SessionStatus = (typeof SESSION_STATUSES)[number];

export type SessionRecord = {
  id: string;
  client_name: string;
  email: string;
  phone: string;
  current_address: string;
  destination_address: string;
  move_date: string;
  estimated_size: string;
  special_items: string | null;
  status: SessionStatus;
  video_key: string | null;
  video_content_type: string | null;
  video_size: number | null;
  rep_notes: string;
  annotations: string;
  created_at: string;
  updated_at: string;
};

type RuntimeBindings = { DB?: D1Database };

function database(): D1Database {
  const db = (env as unknown as RuntimeBindings).DB;
  if (!db) throw new Error("The estimate database is not configured.");
  return db;
}

let initialized = false;

export async function ensureDatabase() {
  if (initialized) return;
  const db = database();
  await db.batch([
    db.prepare(`CREATE TABLE IF NOT EXISTS sessions (
      id TEXT PRIMARY KEY NOT NULL,
      client_name TEXT NOT NULL,
      email TEXT NOT NULL,
      phone TEXT NOT NULL,
      current_address TEXT NOT NULL,
      destination_address TEXT NOT NULL,
      move_date TEXT NOT NULL,
      estimated_size TEXT NOT NULL,
      special_items TEXT,
      status TEXT NOT NULL DEFAULT 'new',
      video_key TEXT,
      video_content_type TEXT,
      video_size INTEGER,
      rep_notes TEXT NOT NULL DEFAULT '',
      annotations TEXT NOT NULL DEFAULT '[]',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(
      "CREATE INDEX IF NOT EXISTS sessions_created_at_idx ON sessions (created_at DESC)",
    ),
    db.prepare(`CREATE TABLE IF NOT EXISTS video_sessions (
      id TEXT PRIMARY KEY NOT NULL,
      estimate_session_id TEXT,
      rep_email TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'waiting',
      video_key TEXT,
      video_content_type TEXT,
      video_size INTEGER,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(
      "CREATE INDEX IF NOT EXISTS video_sessions_created_at_idx ON video_sessions (created_at DESC)",
    ),
    db.prepare(
      "CREATE INDEX IF NOT EXISTS video_sessions_estimate_idx ON video_sessions (estimate_session_id)",
    ),
  ]);

  // Contact columns were added after video_sessions shipped; existing tables
  // need them backfilled. SQLite has no "ADD COLUMN IF NOT EXISTS", so try each
  // and ignore the "duplicate column" error.
  for (const column of [
    "contact_name TEXT",
    "contact_phone TEXT",
    "contact_email TEXT",
    "contact_note TEXT",
    "contact_source TEXT",
    // WordPress ("Tom Moving Estimate") integration: rows created by the plugin
    // are marked origin='wp' and tracked until the plugin has pulled them.
    "origin TEXT",
    "rep_name TEXT",
    "wp_request_id TEXT",
    "wp_ingested INTEGER NOT NULL DEFAULT 0",
  ]) {
    try {
      await db.prepare(`ALTER TABLE video_sessions ADD COLUMN ${column}`).run();
    } catch {
      // column already exists
    }
  }

  initialized = true;
}

export type NewSession = Pick<
  SessionRecord,
  | "client_name"
  | "email"
  | "phone"
  | "current_address"
  | "destination_address"
  | "move_date"
  | "estimated_size"
  | "special_items"
>;

export async function createSession(input: NewSession) {
  await ensureDatabase();
  const id = crypto.randomUUID();
  await database()
    .prepare(`INSERT INTO sessions (
      id, client_name, email, phone, current_address, destination_address,
      move_date, estimated_size, special_items
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`)
    .bind(
      id,
      input.client_name,
      input.email,
      input.phone,
      input.current_address,
      input.destination_address,
      input.move_date,
      input.estimated_size,
      input.special_items,
    )
    .run();
  return id;
}

export async function getSession(id: string) {
  await ensureDatabase();
  return database()
    .prepare("SELECT * FROM sessions WHERE id = ? LIMIT 1")
    .bind(id)
    .first<SessionRecord>();
}

export async function listSessions() {
  await ensureDatabase();
  const result = await database()
    .prepare("SELECT * FROM sessions ORDER BY created_at DESC LIMIT 250")
    .all<SessionRecord>();
  return result.results;
}

export async function attachVideo(
  id: string,
  video: { key: string; contentType: string; size: number },
) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE sessions
      SET video_key = ?, video_content_type = ?, video_size = ?,
          status = 'new', updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(video.key, video.contentType, video.size, id)
    .run();
}

export async function updateSession(
  id: string,
  input: { status: SessionStatus; repNotes: string; annotations: string },
) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE sessions
      SET status = ?, rep_notes = ?, annotations = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(input.status, input.repNotes, input.annotations, id)
    .run();
}

export function isSessionId(value: string) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
    value,
  );
}

export type VideoSessionStatus = "waiting" | "active" | "completed" | "uploaded" | "failed";

export type ContactSource = "client-form" | "rep-entered";

export type VideoSessionRecord = {
  id: string;
  estimate_session_id: string | null;
  rep_email: string;
  status: VideoSessionStatus;
  video_key: string | null;
  video_content_type: string | null;
  video_size: number | null;
  contact_name: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  contact_note: string | null;
  contact_source: ContactSource | null;
  origin: "wp" | "sites" | null;
  rep_name: string | null;
  wp_request_id: string | null;
  /** 0 or 1 — whether the WordPress plugin has pulled this completed call. */
  wp_ingested: number;
  created_at: string;
  updated_at: string;
};

export type VideoSessionContact = {
  name: string;
  phone: string;
  email: string;
  note: string;
};

export async function createVideoSession(repEmail: string, estimateSessionId?: string) {
  await ensureDatabase();
  const id = crypto.randomUUID();
  await database()
    .prepare(`INSERT INTO video_sessions (id, rep_email, estimate_session_id, status)
      VALUES (?, ?, ?, 'waiting')`)
    .bind(id, repEmail, estimateSessionId || null)
    .run();
  return id;
}

export async function getVideoSession(id: string) {
  await ensureDatabase();
  return database()
    .prepare("SELECT * FROM video_sessions WHERE id = ? LIMIT 1")
    .bind(id)
    .first<VideoSessionRecord>();
}

export async function listVideoSessions() {
  await ensureDatabase();
  const result = await database()
    .prepare("SELECT * FROM video_sessions ORDER BY created_at DESC LIMIT 250")
    .all<VideoSessionRecord>();
  return result.results;
}

export async function deleteVideoSession(id: string) {
  await ensureDatabase();
  await database()
    .prepare("DELETE FROM video_sessions WHERE id = ?")
    .bind(id)
    .run();
}

export async function setVideoSessionContact(
  id: string,
  contact: VideoSessionContact,
  source: ContactSource,
) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE video_sessions
      SET contact_name = ?, contact_phone = ?, contact_email = ?,
          contact_note = ?, contact_source = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(
      contact.name || null,
      contact.phone || null,
      contact.email || null,
      contact.note || null,
      source,
      id,
    )
    .run();
}

export async function updateVideoSessionStatus(id: string, status: VideoSessionStatus) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE video_sessions
      SET status = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(status, id)
    .run();
}

export async function attachVideoToSession(
  videoSessionId: string,
  video: { key: string; contentType: string; size: number },
) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE video_sessions
      SET video_key = ?, video_content_type = ?, video_size = ?,
          status = 'uploaded', updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(video.key, video.contentType, video.size, videoSessionId)
    .run();
}

// --- WordPress ("Tom Moving Estimate") integration ------------------------

export async function createWpCall(input: {
  repEmail: string;
  repName: string;
  contact?: { name?: string; phone?: string; email?: string };
}) {
  await ensureDatabase();
  const id = crypto.randomUUID();
  const name = input.contact?.name?.trim() || null;
  const phone = input.contact?.phone?.trim() || null;
  const email = input.contact?.email?.trim() || null;
  const source = name || phone || email ? "rep-entered" : null;
  await database()
    .prepare(`INSERT INTO video_sessions
      (id, rep_email, rep_name, origin, status,
       contact_name, contact_phone, contact_email, contact_source)
      VALUES (?, ?, ?, 'wp', 'waiting', ?, ?, ?, ?)`)
    .bind(
      id,
      input.repEmail || "rep@tommoving.ca",
      input.repName || "",
      name,
      phone,
      email,
      source,
    )
    .run();
  return id;
}

export async function markWpIngested(id: string, wpRequestId: string) {
  await ensureDatabase();
  await database()
    .prepare(`UPDATE video_sessions
      SET wp_ingested = 1, wp_request_id = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?`)
    .bind(wpRequestId || null, id)
    .run();
}

/** Completed WordPress calls the plugin has not pulled yet (cron backstop). */
export async function listWpCallsAwaitingIngest() {
  await ensureDatabase();
  const result = await database()
    .prepare(`SELECT * FROM video_sessions
      WHERE origin = 'wp' AND status = 'uploaded' AND wp_ingested = 0
      ORDER BY created_at DESC LIMIT 100`)
    .all<VideoSessionRecord>();
  return result.results;
}
