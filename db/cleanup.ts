import { env } from "cloudflare:workers";
import { mediaBucket } from "./media";
import { listCallsWithExpiredMedia, listVideoParts, markMediaDeleted } from "./sessions";
import { partsCountFromKey } from "./recording-parts";

type CleanupEnv = { CLEANUP_AFTER_IMPORT_DAYS?: string; CLEANUP_MAX_AGE_DAYS?: string };

function days(value: string | undefined, fallback: number) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? Math.floor(n) : fallback;
}

/**
 * Delete this app's copy of recordings it no longer needs, so storage doesn't
 * grow forever (~300 MB per long call). WordPress keeps its own copy (and its
 * own 30-day retention), so a recording can go a few days after import
 * (CLEANUP_AFTER_IMPORT_DAYS, default 3). Anything older than
 * CLEANUP_MAX_AGE_DAYS (default 45) goes regardless — calls that were
 * abandoned or never imported. Runs daily from the Worker's cron trigger.
 */
export async function cleanupOldRecordings() {
  const config = env as unknown as CleanupEnv;
  const afterImport = days(config.CLEANUP_AFTER_IMPORT_DAYS, 3);
  const maxAge = days(config.CLEANUP_MAX_AGE_DAYS, 45);
  const bucket = mediaBucket();

  let calls = 0;
  let objects = 0;
  for (const call of await listCallsWithExpiredMedia(afterImport, maxAge)) {
    const keys = (await listVideoParts(call.id)).map((part) => part.key);
    if (call.video_key && partsCountFromKey(call.video_key) === null) keys.push(call.video_key);
    for (let i = 0; i < keys.length; i += 1000) {
      await bucket.delete(keys.slice(i, i + 1000));
    }
    await markMediaDeleted(call.id);
    calls += 1;
    objects += keys.length;
  }
  console.log(`[cleanup] removed ${objects} object(s) from ${calls} call(s)`);
  return { calls, objects };
}
