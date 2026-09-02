import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve("work/phase2a-rc3-source/tom-moving-estimate-rc2");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const admin = read("includes/class-tme-admin.php");
const openai = read("includes/class-tme-openai.php");
const readme = read("readme.txt");

assert.match(plugin, /Version:\s*1\.1\.0-rc5/);
assert.match(plugin, /class-tme-openai\.php/);
assert.match(plugin, /'openai_api_key_enc'\s*=>\s*''/);
assert.match(plugin, /'openai_analysis_model'\s*=>\s*'gpt-5\.4-mini'/);
assert.match(plugin, /'openai_fallback_model'\s*=>\s*'gpt-5\.4'/);
assert.match(plugin, /'openai_transcription_model'\s*=>\s*'gpt-4o-mini-transcribe'/);
assert.match(plugin, /TME_Secrets::decrypt\(\(string\) \$settings\['openai_api_key_enc'\]\)/);

assert.match(admin, /admin_post_tme_save_ai_settings/);
assert.match(admin, /admin_post_tme_test_openai/);
assert.match(admin, /name="openai_api_key" type="password"/);
assert.match(admin, /autocomplete="new-password"/);
assert.doesNotMatch(admin, /name="openai_api_key"[^>]*value=/);
assert.match(admin, /self::require_capability\('manage_options'\)/);
assert.match(admin, /check_admin_referer\('tme_save_ai_settings'\)/);
assert.match(admin, /check_admin_referer\('tme_test_openai'\)/);
assert.match(admin, /TME_Secrets::encrypt\(\$api_key\)/);
assert.match(admin, /Live analysis remains disabled in this connection-test release/);
assert.match(admin, /no media was uploaded and no inference request was made/i);

assert.match(openai, /https:\/\/api\.openai\.com\/v1/);
assert.match(openai, /'Authorization'\s*=>\s*'Bearer ' \. \$this->api_key/);
assert.match(openai, /\/models\//);
assert.doesNotMatch(openai, /\/responses/);
assert.doesNotMatch(openai, /\/audio\/transcriptions/);
assert.doesNotMatch(openai, /\berror_log\b|\bprint_r\b|\bvar_dump\b/);

assert.match(readme, /Stable tag:\s*1\.1\.0-rc5/);
assert.match(readme, /does not upload media, run inference or create an AI report/i);

console.log("Phase 2A RC5 encrypted OpenAI settings and no-inference connection checks passed.");
