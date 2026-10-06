import { mediaBucket } from "./media";
import {
  attachVideoToSession,
  deleteVideoPartRows,
  getVideoSession,
  listAbandonedPartialCalls,
  listVideoParts,
  setPartsAssembled,
  type VideoPartRecord,
} from "./sessions";

/**
 * Progressive recording upload.
 *
 * The client's MediaRecorder emits a continuous byte stream in small chunks;
 * the client uploads those chunks in order as numbered pieces while the call
 * is still running. Concatenating the pieces in order reproduces exactly the
 * file the client would have uploaded in one go at the end. So when the
 * customer closes the tab before the end, the pieces that already arrived are
 * still a playable recording, just missing its last few seconds.
 */

export const MAX_RECORDING_BYTES = 250 * 1024 * 1024;
/** No new piece for this long, and no "complete" → treat the call as abandoned. */
export const ABANDONED_AFTER_MINUTES = 5;

export function partKey(sessionId: string, seq: number) {
  return `video-sessions/${sessionId}/parts/${String(seq).padStart(6, "0")}`;
}

/** The unbroken run of pieces from seq 0 (a gap means a lost piece). */
export function contiguousParts(parts: VideoPartRecord[]) {
  const sorted = [...parts].sort((a, b) => a.seq - b.seq);
  const run: VideoPartRecord[] = [];
  for (const part of sorted) {
    if (part.seq !== run.length) break;
    run.push(part);
  }
  return run;
}

type FixedLengthStreamCtor = new (length: number) => {
  readable: ReadableStream;
  writable: WritableStream;
};

/**
 * Build one recording object from the session's pieces and attach it, which
 * marks the call "uploaded" for WordPress to import.
 *
 * - `expectedCount`: from the client's "complete" call — every piece it sent.
 *   Fails if any of them is missing so the client can resend.
 * - without it (abandoned call): uses the unbroken run from piece 0.
 *
 * Never replaces a recording WordPress has already imported, and skips the
 * work when the current recording already covers as many pieces.
 */
export async function assembleRecording(
  sessionId: string,
  options: { expectedCount?: number; deleteParts?: boolean } = {},
): Promise<{ ok: true; parts: number; size: number } | { ok: false; error: string }> {
  const session = await getVideoSession(sessionId);
  if (!session) return { ok: false, error: "Video session not found." };

  // Already built from at least this many pieces (e.g. the client retrying a
  // "complete" whose reply got lost — its pieces are deleted by now), or
  // WordPress has imported it: nothing more to do.
  const already = session.status === "uploaded" && session.video_key;
  const assembled = session.parts_assembled ?? 0;
  if (
    already &&
    (session.wp_ingested === 1 ||
      (options.expectedCount !== undefined && assembled >= options.expectedCount))
  ) {
    return { ok: true, parts: assembled, size: session.video_size ?? 0 };
  }

  const parts = contiguousParts(await listVideoParts(sessionId));
  if (options.expectedCount !== undefined && parts.length < options.expectedCount) {
    return { ok: false, error: `Missing recording piece ${parts.length}.` };
  }
  const use = options.expectedCount !== undefined ? parts.slice(0, options.expectedCount) : parts;
  if (use.length === 0) return { ok: false, error: "No recording pieces arrived." };
  if (already && assembled >= use.length) {
    return { ok: true, parts: assembled, size: session.video_size ?? 0 };
  }

  const size = use.reduce((sum, part) => sum + part.size, 0);
  if (size > MAX_RECORDING_BYTES) return { ok: false, error: "The recording is larger than 250 MB." };

  const contentType = use[0].content_type || "video/webm";
  const extension = contentType.includes("mp4") ? "mp4" : "webm";
  const key = `video-sessions/${sessionId}/${crypto.randomUUID()}.${extension}`;
  const bucket = mediaBucket();

  // R2 needs the total length up front for a streamed put; FixedLengthStream
  // provides it, and the pieces are piped through one after another.
  const Fixed = (globalThis as unknown as { FixedLengthStream: FixedLengthStreamCtor })
    .FixedLengthStream;
  const { readable, writable } = new Fixed(size);
  const pump = (async () => {
    for (const part of use) {
      const object = await bucket.get(part.key);
      if (!object) throw new Error(`Recording piece ${part.seq} is missing from storage.`);
      await object.body.pipeTo(writable, { preventClose: true });
    }
    await writable.close();
  })();

  try {
    await Promise.all([bucket.put(key, readable, { httpMetadata: { contentType } }), pump]);
  } catch (error) {
    await writable.abort(error).catch(() => undefined);
    await bucket.delete(key).catch(() => undefined);
    return { ok: false, error: error instanceof Error ? error.message : "Could not assemble the recording." };
  }

  const previousKey = session.video_key;
  await attachVideoToSession(sessionId, { key, contentType, size });
  await setPartsAssembled(sessionId, use.length);
  if (previousKey && previousKey !== key) await bucket.delete(previousKey).catch(() => undefined);

  if (options.deleteParts) {
    const all = await listVideoParts(sessionId);
    await Promise.all(all.map((part) => bucket.delete(part.key).catch(() => undefined)));
    await deleteVideoPartRows(sessionId);
  }

  return { ok: true, parts: use.length, size };
}

/**
 * Assemble calls the customer abandoned mid-upload. Called from the
 * WordPress-facing endpoints, so the plugin's 5-minute sweep (and its
 * "Calls waiting to import" list) is what drives it — no cron needed here.
 */
export async function assembleAbandonedCalls() {
  const ids = await listAbandonedPartialCalls(ABANDONED_AFTER_MINUTES);
  for (const id of ids) {
    const result = await assembleRecording(id).catch((error: unknown) => ({
      ok: false as const,
      error: error instanceof Error ? error.message : String(error),
    }));
    if (!result.ok) console.warn(`[recording-parts] could not assemble ${id}: ${result.error}`);
  }
}
