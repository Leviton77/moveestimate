import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2a-rc4-verify");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const exporter = read("includes/class-tme-ai-export.php");
const sample = JSON.parse(read("assets/data/phase2-ai-report-example-v1.json"));

assert.match(plugin, /Version:\s*1\.1\.0-rc4/);
assert.match(plugin, /class-tme-ai-export\.php/);
assert.match(plugin, /is_readable\(\$tme_ai_export_file\)/);
assert.match(plugin, /'max_photos'\s*=>\s*50/);
assert.match(plugin, /maybe_upgrade_photo_limit\(\$installed_version\)/);
assert.match(plugin, /\$settings\['max_photos'\] = 50/);
assert.match(admin, /if \(class_exists\('TME_AI_Export'\)\)/);
assert.match(admin, /Report exports are temporarily unavailable/);

for (const action of [
  "tme_download_ai_csv",
  "tme_download_ai_json",
  "tme_print_ai_report",
]) {
  assert.match(admin, new RegExp(`admin_post_${action}`));
  assert.match(admin, new RegExp(`ai_export_context\\('${action}'\\)`));
}

for (const label of ["Print / Save PDF", "Download CSV", "Download JSON"]) {
  assert.ok(admin.includes(label), `Missing export control: ${label}`);
}

assert.match(admin, /self::require_capability\(\)/);
assert.match(admin, /check_admin_referer\(\$action \. '_' \. \$id\)/);
assert.match(admin, /ai_report_current/);
assert.match(admin, /Content-Type: text\/csv; charset=utf-8/);
assert.match(admin, /Content-Type: application\/json; charset=utf-8/);
assert.match(admin, /JSON_PRETTY_PRINT \| JSON_UNESCAPED_SLASHES/);
assert.match(admin, /X-Robots-Tag: noindex, nofollow, noarchive/);
assert.match(admin, /fputcsv\(\$output, \$row\)/);
assert.match(admin, /\\xEF\\xBB\\xBF/);

for (const heading of [
  "Estimate ID",
  "Move status",
  "Box likely",
  "Disassembly likelihood",
  "Destination reassembly",
  "Mattress size",
  "Foundation/box-spring bags",
  "Evidence timestamps",
]) {
  assert.ok(exporter.includes(`'${heading}'`), `Missing CSV field: ${heading}`);
}

assert.match(exporter, /disassembly_map\(\$report\)/);
assert.match(exporter, /mattress_map\(\$report\)/);
assert.match(exporter, /preg_match\('\/\^\[\\x00-\\x20\]\*\[=\+\\-@\]\/u'/);
assert.match(exporter, /@media print/);
assert.match(exporter, /window\.print\(\)/);
assert.match(exporter, /Internal representative-reviewed planning report/);
assert.match(exporter, /not a customer quotation/);
assert.match(exporter, /'APPROVED'/);
assert.match(exporter, /'DRAFT'/);

for (const section of [
  "Inventory by room",
  "Box estimate",
  "Likely disassembly and reassembly",
  "Mattress bags to prepare",
  "Truck access and carrying route",
  "Home layout and carrying-speed factors",
  "Questions requiring confirmation",
  "Representative review",
]) {
  assert.ok(exporter.includes(section), `Missing print section: ${section}`);
}

assert.equal(sample.mattress_bags.by_size[0].item_ids[0], "item_queen_bed");
assert.ok(
  sample.disassembly_plan.items.every((item) => item.item_id),
  "Each sample disassembly task must map to an inventory item.",
);

const allSource = fs
  .readdirSync(root, { recursive: true })
  .filter((relative) => fs.statSync(path.join(root, relative)).isFile())
  .map((relative) => read(relative))
  .join("\n");

assert.doesNotMatch(allSource, /api\.openai\.com/i);
assert.doesNotMatch(allSource, /OPENAI_API_KEY/);
assert.doesNotMatch(exporter, /https?:\/\//i);

console.log("Phase 2A RC4 photo-limit, export, permission, and no-AI-charge checks passed.");
