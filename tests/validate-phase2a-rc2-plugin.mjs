import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2a-rc2-source/tom-moving-estimate-rc2");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const ai = read("includes/class-tme-ai-report.php");
const editor = read("assets/js/ai-report.js");
const css = read("assets/css/admin.css");
const sample = JSON.parse(read("assets/data/phase2-ai-report-example-v1.json"));

assert.match(plugin, /Version:\s*1\.1\.0-rc2/);
assert.match(plugin, /class-tme-ai-report\.php/);
assert.match(admin, /admin_post_tme_load_synthetic_ai_report/);
assert.match(admin, /TME_Plugin::is_staging\(\)/);
assert.match(admin, /Load synthetic example/);
assert.match(admin, /name="ai_action" value="approve"/);
assert.match(admin, /ai_report_original/);
assert.match(admin, /ai_report_current/);
assert.match(admin, /Synthetic AI report loaded\. No customer media was analyzed\./);

for (const section of [
  "Inventory by room",
  "Disassembly and reassembly",
  "Mattress bags",
  "Access and home layout",
  "Questions and uncertainty",
  "Review record",
]) {
  assert.ok(editor.includes(section), `Missing editor section: ${section}`);
}

for (const capability of [
  "Add room",
  "Add item",
  "Add disassembly item",
  "Add bag size",
  "Add question",
  "Unsaved report changes.",
]) {
  assert.ok(editor.includes(capability), `Missing editor capability: ${capability}`);
}

assert.match(ai, /prepare_for_storage/);
assert.match(ai, /content_without_review/);
assert.match(ai, /MAX_CHANGES/);
assert.match(css, /\.tme-ai-summary/);
assert.match(css, /\.tme-ai-access-grid/);

assert.equal(sample.analysis.analysis_id, "synthetic-example");
assert.ok(sample.analysis.warnings.some((warning) => /No customer media was analyzed/i.test(warning)));
assert.equal(sample.mattress_bags.total_bags, 2);
assert.equal(sample.disassembly_plan.totals.likely, 3);

const allSource = fs
  .readdirSync(root, { recursive: true })
  .filter((relative) => fs.statSync(path.join(root, relative)).isFile())
  .map((relative) => read(relative))
  .join("\n");

assert.doesNotMatch(allSource, /api\.openai\.com/i);
assert.doesNotMatch(allSource, /OPENAI_API_KEY/);

console.log("Phase 2A RC2 synthetic editor and safety checks passed.");
