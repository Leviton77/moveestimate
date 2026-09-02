import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(
  "work/phase3-rc11-source/tom-moving-estimate-rc11-homepage",
);
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

const plugin = read("tom-moving-estimate.php");
const publicPhp = read("includes/class-tme-public.php");
const publicJs = read("assets/js/public.js");
const quickJs = read("assets/js/quick-start.js");
const publicCss = read("assets/css/public.css");
const readme = read("readme.txt");

assert.match(plugin, /Version:\s*1\.4\.0-rc12/);
assert.match(plugin, /define\('TME_VERSION', '1\.4\.0-rc12'\)/);
assert.match(readme, /Stable tag:\s*1\.4\.0-rc12/);

assert.match(
  publicPhp,
  /add_shortcode\('tom_moving_estimate_quick_start', array\(__CLASS__, 'quick_start_shortcode'\)\)/,
);
assert.match(publicPhp, /public static function quick_start_shortcode\(\$atts = array\(\)\): string/);
assert.match(publicPhp, /assets\/js\/quick-start\.js/);

const quickMarkup = publicPhp.slice(
  publicPhp.indexOf("public static function quick_start_shortcode"),
  publicPhp.indexOf("public static function register_routes"),
);
for (const name of ["clientName", "moveDate", "estimatedSize"]) {
  assert.match(quickMarkup, new RegExp(`name="${name}"`));
}
for (const excluded of [
  "email",
  "phone",
  "currentAddress",
  "destinationAddress",
  "specialItems",
  "consent",
]) {
  assert.doesNotMatch(quickMarkup, new RegExp(`name="${excluded}"`));
}
assert.match(quickMarkup, /method="post"/);
assert.match(quickMarkup, /admin_url\('admin-post\.php'\)/);
assert.match(quickMarkup, /name="action" value="tme_quick_start"/);
assert.match(quickMarkup, /name="tmeLanguage"/);
assert.match(quickMarkup, /Start my estimate/);
assert.match(quickMarkup, /Commencer mon estimation/);
assert.match(quickMarkup, /data-language=/);
assert.match(quickMarkup, /\/fr\/estimation\//);
assert.match(quickMarkup, /home_size_label\(\$size, \$language\)/);

assert.match(quickJs, /window\.sessionStorage\.setItem/);
assert.match(quickJs, /createdAt:\s*Date\.now\(\)/);
assert.match(quickJs, /new URL\("\/wp-json\/tme\/v1\/quick-start", window\.location\.origin\)/);
assert.match(quickJs, /form\.getAttribute\("action"\)/);
assert.match(quickJs, /HTMLFormElement\.prototype\.submit\.call\(form\)/);
assert.match(quickJs, /searchParams\.set\("quick-start-token", token\)/);
assert.match(quickJs, /searchParams\.set\("quick-start", "1"\)/);
assert.doesNotMatch(
  quickJs,
  /searchParams\.set\("(?:clientName|moveDate|estimatedSize)"/,
);
assert.doesNotMatch(quickJs, /localStorage/);
assert.match(quickJs, /Veuillez saisir votre nom complet/);
assert.match(quickJs, /TMEQuickStart\.locale/);

const applyQuickStart = publicJs.slice(
  publicJs.indexOf("function applyQuickStart"),
  publicJs.indexOf("function timeLabel"),
);
assert.match(publicPhp, /private static function valid_quick_start\(array \$payload\): \?array/);
assert.match(publicPhp, /public static function create_quick_start\(WP_REST_Request \$request\)/);
assert.match(publicPhp, /public static function handle_quick_start\(\): void/);
assert.match(publicPhp, /admin_post_nopriv_tme_quick_start/);
assert.match(publicPhp, /public static function consume_quick_start\(WP_REST_Request \$request\)/);
assert.match(publicPhp, /set_transient\('tme_qs_' \. \$token, \$values, 10 \* MINUTE_IN_SECONDS\)/);
assert.match(publicPhp, /register_rest_route\('tme\/v1', '\/quick-start'/);
assert.match(publicPhp, /register_rest_route\('tme\/v1', '\/quick-start\/\(\?P<token>\[a-f0-9\]\{64\}\)'/);
assert.match(publicPhp, /'quickStartPost'\s*=>\s*\$quick_start_post/);
assert.match(publicPhp, /'quickStartRestUrl'\s*=>\s*esc_url_raw\(rest_url\('tme\/v1\/quick-start\/'\)\)/);
assert.match(publicPhp, /value="<\?php echo esc_attr\(\$quick_start_post\['clientName'\] \?\? ''\); \?>"/);
assert.match(applyQuickStart, /TMEPublic\.quickStartPost \|\| null/);
assert.match(applyQuickStart, /new URL\([\s\S]*\/wp-json\/tme\/v1\/quick-start\/[\s\S]*window\.location\.origin/);
assert.match(applyQuickStart, /fetch\(quickStartRestUrl \+ encodeURIComponent\(handoffToken\)/);
assert.match(applyQuickStart, /sessionStorage\.getItem/);
assert.match(applyQuickStart, /sessionStorage\.removeItem/);
assert.match(applyQuickStart, /Date\.now\(\) - createdAt <= maxAge/);
assert.match(applyQuickStart, /searchParams\.delete\("quick-start"\)/);
assert.match(applyQuickStart, /form\.elements\.clientName\.value = clientName/);
assert.match(applyQuickStart, /form\.elements\.moveDate\.value = moveDate/);
assert.match(applyQuickStart, /form\.elements\.estimatedSize\.value = estimatedSize/);
assert.doesNotMatch(applyQuickStart, /consent/);

assert.match(publicPhp, /quickStartMaxAgeMs'\s*=>\s*2 \* HOUR_IN_SECONDS \* 1000/);
assert.match(publicPhp, /public static function shortcode\(\$atts = array\(\)\): string/);
assert.match(publicPhp, /'locale'\s*=>\s*\$language/);
assert.match(publicPhp, /'quickStartStorageKey'\s*=>\s*'tme_quick_start_v1_' \. \$language/);
assert.match(publicPhp, /data-tme-app data-language=/);
assert.match(publicPhp, /Demandez votre estimation de déménagement/);
assert.match(publicPhp, /Téléverser des photos/);
assert.match(publicPhp, /Commencer la visite vidéo/);
assert.match(publicPhp, /Déménagement Tom utilise ces renseignements/);
assert.match(publicPhp, /wp_safe_redirect\(add_query_arg\('quick-start-token', \$token, home_url\(\$estimate_path\)\)\)/);
assert.match(publicJs, /const isFrench =/);
assert.match(publicJs, /Vos photos ont été téléversées de façon sécurisée/);
assert.match(publicJs, /Enregistrement /);
assert.match(publicPhp, /data-tme-prefill-note/);
assert.match(publicCss, /\.tme-quick__grid/);
assert.match(publicCss, /\.tme-prefill-note/);
assert.match(publicCss, /--tme-quick-blue:\s*#1b3de0/);
assert.match(publicCss, /\.tme \.tme-choice[\s\S]*white-space:\s*normal !important/);
assert.match(publicCss, /\.tme-choice strong, \.tme-choice span[\s\S]*white-space:\s*normal !important/);
assert.match(publicCss, /@media \(max-width: 640px\)[\s\S]*\.tme-quick__grid \{ grid-template-columns: 1fr; \}/);

assert.match(readme, /Personal information is not added to the URL/);
assert.match(readme, /random, one-use token/);
assert.match(readme, /no incomplete estimate record is created/);
assert.match(readme, /consent remains unchecked/);

for (const relative of [
  "assets/js/quick-start.js",
  "assets/js/public.js",
  "assets/js/admin.js",
  "assets/js/ai-report.js",
]) {
  execFileSync(process.execPath, ["--check", path.join(root, relative)], {
    stdio: "pipe",
  });
}

console.log("Phase 3 RC12 bilingual homepage and shared-backend safeguards passed.");
