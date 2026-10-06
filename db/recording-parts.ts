import { mediaBucket } from "./media";
import {
  attachVideoToSession,
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
 * file the client would have uploaded in one go at the end, so the pieces
 * themselves are the recording: nothing is copied into a single object.
 * "Sealing" a call records how many pieces make up its recording
 * (video_key = "parts:<n>"), and GET /api/calls/:id/recording streams them
 * back to back as one file. That keeps finishing instant and puts no ceiling
 * on call length beyond the WordPress import limit.
 *
 * When the customer closes the tab before the end, the pieces that already
 * arrived are still a playable recording, just missing its last few seconds.
 */

/** Generous sanity cap; WordPress's own "Maximum video size" is the real limit. */
export const MAX_RECORDING_BYTES = 2 * 1024 * 1024 * 1024;
/** No new piece for this long, and no "complete" → treat the call as abandoned. */
export const ABANDONED_AFTER_MINUTES = 5;
export const PARTS_KEY_PREFIX = "parts:";

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

/** Number of pieces a "parts:<n>" video_key refers to, or null for a single object. */
export function partsCountFromKey(key: string | null) {
  if (!key?.startsWith(PARTS_KEY_PREFIX)) return null;
  const count = Number(key.slice(PARTS_KEY_PREFIX.length));
  return Number.isInteger(count) && count > 0 ? count : null;
}

/** Which byte span of which pieces covers [start, end] of the whole recording. */
export function partSegments(
  parts: { key: string; size: number }[],
  start: number,
  end: number,
) {
  const segments: { key: string; offset: number; length: number }[] = [];
  let at = 0;
  for (const part of parts) {
    const partStart = at;
    const partEnd = at + part.size - 1;
    at += part.size;
    if (partEnd < start || partStart > end) continue;
    const from = Math.max(start, partStart) - partStart;
    const to = Math.min(end, partEnd) - partStart;
    segments.push({ key: part.key, offset: from, length: to - from + 1 });
  }
  return segments;
}

type FixedLengthStreamCtor = new (length: number) => {
  readable: ReadableStream;
  writable: WritableStream;
};

/**
 * Stream [start, end] of a pieces-based recording as one body. Content-Length
 * is fixed up front (FixedLengthStream) so downloads and range requests
 * behave exactly as they would for a single stored file.
 */
export function streamRecordingParts(
  parts: { key: string; size: number }[],
  start: number,
  end: number,
): ReadableStream {
  const Fixed = (globalThis as unknown as { FixedLengthStream: FixedLengthStreamCtor })
    .FixedLengthStream;
  const { readable, writable } = new Fixed(end - start + 1);
  const bucket = mediaBucket();
  void (async () => {
    try {
      for (const segment of partSegments(parts, start, end)) {
        const object = await bucket.get(segment.key, {
          range: { offset: segment.offset, length: segment.length },
        });
        if (!object) throw new Error(`Recording piece ${segment.key} is missing.`);
        await object.body.pipeTo(writable, { preventClose: true });
      }
      await writable.close();
    } catch (error) {
      await writable.abort(error).catch(() => undefined);
    }
  })();
  return readable;
}

/**
 * Mark a call's recording as made of its uploaded pieces, which marks the
 * call "uploaded" for WordPress to import. Instant: nothing is copied.
 *
 * - `expectedCount`: from the client's "complete" call — every piece it sent.
 *   Fails if any of them is missing so the client can resend.
 * - without it (abandoned call): uses the unbroken run from piece 0.
 *
 * Never changes a recording WordPress has already imported, and is a no-op
 * when the current recording already covers as many pieces.
 */
export async function assembleRecording(
  sessionId: string,
  options: { expectedCount?: number } = {},
): Promise<{ ok: true; parts: number; size: number } | { ok: false; error: string }> {
  const session = await getVideoSession(sessionId);
  if (!session) return { ok: false, error: "Video session not found." };

  // Already sealed with at least this many pieces (e.g. the client retrying
  // a "complete" whose reply got lost), or WordPress has imported it.
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
  if (size > MAX_RECORDING_BYTES) return { ok: false, error: "The recording is larger than 2 GB." };

  await attachVideoToSession(sessionId, {
    key: `${PARTS_KEY_PREFIX}${use.length}`,
    contentType: use[0].content_type || "video/webm",
    size,
  });
  await setPartsAssembled(sessionId, use.length);
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
