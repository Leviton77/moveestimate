import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2a-rc1-source/tom-moving-estimate-rc2");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const db = read("includes/class-tme-db.php");
const admin = read("includes/class-tme-admin.php");
const adminCss = read("assets/css/admin.css");
const readme = read("readme.txt");

assert.match(plugin, /Version:\s*1\.1\.0-rc1/);
assert.match(plugin, /define\('TME_VERSION', '1\.1\.0-rc1'\)/);
assert.match(readme, /Stable tag:\s*1\.1\.0-rc1/);

for (const field of [
  "ai_status",
  "ai_report_original",
  "ai_report_current",
  "ai_schema_version",
  "ai_model",
  "ai_requested_at",
  "ai_completed_at",
  "ai_reviewed_at",
  "ai_reviewed_by",
  "ai_error",
]) {
  assert.match(db, new RegExp(`\\b${field}\\b`), `Missing AI report database field: ${field}`);
}

assert.match(db, /ai_status varchar\(20\) NOT NULL DEFAULT 'not_started'/);
assert.match(db, /KEY ai_status \(ai_status\)/);

for (const status of [
  "not_started",
  "queued",
  "processing",
  "needs_review",
  "approved",
  "failed",
]) {
  assert.ok(admin.includes(`'${status}'`), `Missing AI report status: ${status}`);
}

for (const section of [
  "Inventory by room",
  "Box estimates",
  "Disassembly and reassembly",
  "Mattress bags by size",
  "Access and home layout",
  "Questions and uncertainty",
]) {
  assert.ok(admin.includes(section), `Missing report workspace section: ${section}`);
}

assert.match(admin, /AI connection is not enabled\./);
assert.match(admin, /Run AI analysis/);
assert.match(admin, /type="button" disabled aria-disabled="true"/);
assert.match(adminCss, /\.tme-ai-panel/);
assert.match(adminCss, /\.tme-ai-sections/);

function files(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const fullPath = path.join(directory, entry.name);
    return entry.isDirectory() ? files(fullPath) : [fullPath];
  });
}

const allSource = files(root)
  .map((file) => fs.readFileSync(file, "utf8"))
  .join("\n");

assert.doesNotMatch(allSource, /api\.openai\.com/i);
assert.doesNotMatch(allSource, /OPENAI_API_KEY/);

console.log("Phase 2A RC1 database and disabled report-workspace checks passed.");
