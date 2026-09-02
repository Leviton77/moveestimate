import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase1-rc12-source/tom-moving-estimate-rc2");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const db = read("includes/class-tme-db.php");
const publicPhp = read("includes/class-tme-public.php");
const adminPhp = read("includes/class-tme-admin.php");
const retentionPhp = read("includes/class-tme-retention.php");
const publicJs = read("assets/js/public.js");
const publicCss = read("assets/css/public.css");

assert.match(plugin, /Version:\s*1\.0\.0-rc12/);
assert.match(plugin, /define\('TME_VERSION', '1\.0\.0-rc12'\)/);
assert.match(plugin, /'max_photos'\s*=>\s*30/);
assert.match(plugin, /'max_photo_mb'\s*=>\s*15/);

for (const field of [
  "submission_type",
  "submitted_at",
  "photos",
  "pending_photos",
  "media_expires_at",
  "media_warning_sent_at",
  "media_deleted_at",
]) {
  assert.match(db, new RegExp(`\\b${field}\\b`), `Missing database field: ${field}`);
}

for (const route of [
  "/sessions",
  "/submission-type",
  "/upload-url",
  "/complete",
  "/photo-upload-url",
  "/complete-photos",
]) {
  assert.ok(publicPhp.includes(route), `Missing REST route: ${route}`);
}

assert.match(publicPhp, /data-tme-choice-view/);
assert.match(publicPhp, /data-tme-choose-photos/);
assert.match(publicPhp, /data-tme-choose-video/);
assert.doesNotMatch(publicPhp, /name="submissionType"/);

for (const hook of [
  "tme_delete_video",
  "tme_download_video",
  "tme_delete_photo",
  "tme_delete_photos",
  "tme_download_photo",
]) {
  assert.ok(adminPhp.includes(hook), `Missing admin action: ${hook}`);
}

assert.match(retentionPhp, /delete_media/);
assert.match(retentionPhp, /delete_photos/);
assert.match(publicJs, /submitPhotos/);
assert.match(publicJs, /openRecorder/);
assert.match(publicJs, /chooseSubmissionType/);
assert.match(publicCss, /\.tme-choice-grid/);
assert.match(publicCss, /\.tme-photo-grid/);

function phpSegments(source) {
  return [...source.matchAll(/<\?php([\s\S]*?)(?:\?>|$)/g)].map((match) => match[1]).join("\n");
}

function assertBalanced(source, name) {
  const pairs = new Map([[")", "("], ["]", "["], ["}", "{"]]);
  const openings = new Set(pairs.values());
  const stack = [];
  let quote = "";
  let lineComment = false;
  let blockComment = false;
  let escaped = false;

  for (let index = 0; index < source.length; index += 1) {
    const char = source[index];
    const next = source[index + 1] || "";
    if (lineComment) {
      if (char === "\n") lineComment = false;
      continue;
    }
    if (blockComment) {
      if (char === "*" && next === "/") {
        blockComment = false;
        index += 1;
      }
      continue;
    }
    if (quote) {
      if (escaped) {
        escaped = false;
      } else if (char === "\\") {
        escaped = true;
      } else if (char === quote) {
        quote = "";
      }
      continue;
    }
    if (char === "'" || char === '"') {
      quote = char;
      continue;
    }
    if ((char === "/" && next === "/") || char === "#") {
      lineComment = true;
      if (char === "/") index += 1;
      continue;
    }
    if (char === "/" && next === "*") {
      blockComment = true;
      index += 1;
      continue;
    }
    if (openings.has(char)) stack.push(char);
    if (pairs.has(char)) {
      assert.equal(stack.pop(), pairs.get(char), `${name} has an unbalanced ${char}`);
    }
  }
  assert.equal(quote, "", `${name} has an unterminated string`);
  assert.deepEqual(stack, [], `${name} has unbalanced brackets`);
}

for (const relative of [
  "tom-moving-estimate.php",
  "includes/class-tme-db.php",
  "includes/class-tme-r2.php",
  "includes/class-tme-retention.php",
  "includes/class-tme-public.php",
  "includes/class-tme-admin.php",
]) {
  assertBalanced(phpSegments(read(relative)), relative);
}

console.log("RC12 plugin structure and syntax-balance checks passed.");
