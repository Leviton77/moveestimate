import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2b-rc9-source/tom-moving-estimate-rc9");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const report = read("includes/class-tme-ai-report.php");
const exporter = read("includes/class-tme-ai-export.php");
const openai = read("includes/class-tme-openai.php");
const editor = read("assets/js/ai-report.js");
const readme = read("readme.txt");

assert.match(plugin, /Version:\s*1\.2\.0-rc9/);
assert.match(plugin, /define\('TME_VERSION', '1\.2\.0-rc9'\)/);
assert.match(readme, /Stable tag:\s*1\.2\.0-rc9/);

assert.match(openai, /Create an inventory entry for every item the customer explicitly says is moving or not moving/);
assert.match(openai, /This includes mattresses, foundations\/box springs, appliances and furniture/);
assert.match(openai, /Never omit an explicit moving or excluded item/);
assert.match(openai, /Merge repeated observations of the same physical item instead of duplicating it/);
assert.match(openai, /A moving mattress, foundation or box spring must still appear in room inventory/);
assert.match(openai, /Link bag records and protection questions only to the matching mattress\/foundation inventory item IDs/);
assert.match(openai, /cross-check every explicit move\/stay statement against inventory/);

for (const field of [
  "mattress_total",
  "foundation_total",
  "mattress_bags_total",
  "foundation_bags_total",
  "protection_bags_total",
  "mattress_bag_sizes",
  "foundation_bag_sizes",
]) {
  assert.ok(report.includes(`'${field}'`), `Missing server-side field: ${field}`);
}

assert.match(report, /public static function recalculate_for_output\(array \$report\): array/);
assert.match(admin, /TME_AI_Report::recalculate_for_output\(\$report\)/);
assert.match(editor, /summaryCard\("Mattress bags", summary\.mattress_bags_total \|\| 0\)/);
assert.match(editor, /summaryCard\("Foundation bags", summary\.foundation_bags_total \|\| 0\)/);
assert.match(exporter, /self::print_card\('Mattress bags', \(string\) \$mattress_bag_total\)/);
assert.match(exporter, /self::print_card\('Foundation bags', \(string\) \$foundation_bag_total\)/);

console.log("Phase 2B RC9 explicit-item preservation and separate bag checks passed.");
