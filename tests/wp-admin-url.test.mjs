import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (p) => readFile(new URL(p, root), "utf8");

// One Sites deployment can serve more than one WordPress (staging vs
// production). "Finish in Tom Estimator" must return to whichever WordPress
// started the call, not a single hard-coded WP_ADMIN_URL.

test("createWpCall persists a per-call wp_admin_url", async () => {
  const db = await read("db/sessions.ts");
  assert.match(db, /"wp_admin_url TEXT"/); // migration column
  assert.match(db, /wp_admin_url: string \| null;/); // on the record type
  assert.match(db, /wpAdminUrl\?: string;/); // createWpCall input
  assert.match(db, /wp_admin_url[\s\S]{0,120}?VALUES/); // written in the INSERT
});

test("POST /api/calls validates wp_admin_url; the awaiting-ingest list returns it", async () => {
  const route = await read("app/api/calls/route.ts");
  assert.match(route, /new URL\(str\(payload\.wp_admin_url/);
  assert.match(route, /u\.protocol === "https:" \|\| u\.protocol === "http:"/);
  assert.match(route, /createWpCall\(\{[\s\S]*?wpAdminUrl[\s\S]*?\}\)/);
  assert.match(route, /wp_admin_url: row\.wp_admin_url \?\? ""/);
});

test("the rep page prefers the call's wp_admin_url over the env fallback", async () => {
  const page = await read("app/video-call/[id]/rep/page.tsx");
  assert.match(
    page,
    /session\.wp_admin_url\s*\|\|[\s\S]{0,120}?WP_ADMIN_URL/,
  );
});

test("the plugin sends its admin_url() and its cron skips other sites' calls", async () => {
  const plugin = await read(
    "wordpress-plugin/tom-moving-estimate/includes/class-tme-live-call.php",
  );
  assert.match(plugin, /'wp_admin_url'\s*=>\s*admin_url\(\)/); // handle_start
  // sweep() compares the returned wp_admin_url against this site's admin_url()
  assert.match(plugin, /\$here = untrailingslashit\(admin_url\(\)\)/);
  assert.match(plugin, /strcasecmp\(\$origin, \$here\) !== 0/);
});
