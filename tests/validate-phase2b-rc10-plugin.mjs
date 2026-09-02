import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(
  "work/phase2b-rc10-source/tom-moving-estimate-rc10-production-safe",
);
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const report = read("includes/class-tme-ai-report.php");
const exporter = read("includes/class-tme-ai-export.php");
const openai = read("includes/class-tme-openai.php");
const editor = read("assets/js/ai-report.js");
const readme = read("readme.txt");

assert.match(plugin, /Version:\s*1\.2\.0-rc10/);
assert.match(plugin, /define\('TME_VERSION', '1\.2\.0-rc10'\)/);
assert.match(readme, /Stable tag:\s*1\.2\.0-rc10/);

assert.match(
  plugin,
  /'openai_live_enabled'\s*=>\s*self::is_staging\(\)\s*\?\s*1\s*:\s*0/,
);
assert.match(plugin, /public static function is_ai_live_enabled\(\): bool/);
assert.match(openai, /if \(!TME_Plugin::is_ai_live_enabled\(\)\)/);
assert.doesNotMatch(openai, /tme_ai_staging_only|restricted to staging/);
assert.match(
  admin,
  /name="openai_live_enabled" type="checkbox" value="1"/,
);
assert.match(
  admin,
  /\$settings\['openai_live_enabled'\] = !empty\(\$_POST\['openai_live_enabled'\]\) \? 1 : 0;/,
);
assert.ok(
  (admin.match(/!TME_Plugin::is_ai_live_enabled\(\)/g) || []).length >= 2,
  "Both report availability and the run action must enforce the live-analysis switch.",
);
assert.match(
  readme,
  /Live analysis is disabled by default on production and must be explicitly enabled/,
);

assert.match(
  openai,
  /Create an inventory entry for every item the customer explicitly says is moving or not moving/,
);
assert.match(openai, /Never omit an explicit moving or excluded item/);
assert.match(
  openai,
  /Link bag records and protection questions only to the matching mattress\/foundation inventory item IDs/,
);

for (const field of [
  "mattress_total",
  "foundation_total",
  "mattress_bags_total",
  "foundation_bags_total",
  "protection_bags_total",
]) {
  assert.ok(report.includes(`'${field}'`), `Missing server-side field: ${field}`);
}

assert.match(report, /public static function recalculate_for_output\(array \$report\): array/);
assert.match(editor, /summaryCard\("Mattress bags", summary\.mattress_bags_total \|\| 0\)/);
assert.match(editor, /summaryCard\("Foundation bags", summary\.foundation_bags_total \|\| 0\)/);
assert.match(exporter, /self::print_card\('Mattress bags', \(string\) \$mattress_bag_total\)/);
assert.match(exporter, /self::print_card\('Foundation bags', \(string\) \$foundation_bag_total\)/);

console.log("Phase 2B RC10 production-enablement safeguards passed.");
