import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2b-rc8-source/tom-moving-estimate-rc8");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const report = read("includes/class-tme-ai-report.php");
const exporter = read("includes/class-tme-ai-export.php");
const openai = read("includes/class-tme-openai.php");
const editor = read("assets/js/ai-report.js");
const readme = read("readme.txt");
const sample = JSON.parse(read("assets/data/phase2-ai-report-example-v1.json"));

assert.match(plugin, /Version:\s*1\.2\.0-rc8/);
assert.match(plugin, /define\('TME_VERSION', '1\.2\.0-rc8'\)/);
assert.match(readme, /Stable tag:\s*1\.2\.0-rc8/);

assert.match(openai, /Keep mattress bags and foundation\/box-spring bags as separate counts by size/);
assert.match(openai, /Count a foundation\/box-spring bag only when the customer clearly requested that protection/);
assert.match(openai, /leave its bag count at zero and add an unconfirmed note or question/);
assert.match(openai, /Never include foundation\/box-spring bags in the mattress-bag count/);

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
assert.match(editor, /report\.summary\.protection_bags_total = protectionBagTotal/);
assert.match(editor, /Keep mattress protection separate from optional foundation or box-spring protection/);
assert.match(editor, /total\.textContent = "Total: " \+ entry\.total_bags/);

assert.match(exporter, /self::print_card\('Mattress bags', \(string\) \$mattress_bag_total\)/);
assert.match(exporter, /self::print_card\('Foundation bags', \(string\) \$foundation_bag_total\)/);
assert.match(exporter, /Mattress and foundation bags to prepare/);
assert.match(exporter, /<th>Mattress bags<\/th><th>Foundation \/ box-spring bags<\/th>/);

assert.equal(sample.summary.mattress_bags_total, 1);
assert.equal(sample.summary.foundation_bags_total, 1);
assert.equal(sample.summary.protection_bags_total, 2);
assert.equal(sample.summary.mattress_bag_sizes.queen, 1);
assert.equal(sample.summary.foundation_bag_sizes.queen, 1);

console.log("Phase 2B RC8 separate mattress and foundation bag checks passed.");
