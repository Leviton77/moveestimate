<?php
/**
 * Standalone checks for TME_Updater's channel selection and update
 * injection. No WordPress required -- the WP primitives touched are stubbed.
 *
 * Run:  php tests/updater-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('TME_FILE', '/srv/wp-content/plugins/tom-moving-estimate/tom-moving-estimate.php');
define('TME_VERSION', '1.2.0-rc19');

$GLOBALS['__transients'] = array();
$GLOBALS['__staging'] = false;

function add_filter(...$a) {}
function add_action(...$a) {}
function plugin_basename($file) { return 'tom-moving-estimate/tom-moving-estimate.php'; }
function get_site_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_site_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_site_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function is_wp_error($v) { return false; }

final class TME_Plugin
{
    public static function is_staging(): bool { return $GLOBALS['__staging']; }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-updater.php';

$failures = 0;
function check(string $label, $actual, $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "  ok  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n       expected: " . var_export($expected, true) . "\n       actual:   " . var_export($actual, true) . "\n";
}

function channel(array $manifest, bool $testing)
{
    $ref = new ReflectionMethod('TME_Updater', 'channel_entry');
    $ref->setAccessible(true);
    return $ref->invoke(null, $manifest, $testing);
}

function entry(string $version): array
{
    return array(
        'version' => $version,
        'package' => "https://github.com/Leviton77/moveestimate/releases/download/plugin-v{$version}/tom-moving-estimate-{$version}.zip",
        'notes'   => "notes {$version}",
    );
}

// --- the shipped manifest parses and is usable -----------------------------

$shipped = json_decode((string) file_get_contents(__DIR__ . '/../wordpress-plugin/update.json'), true);
check('update.json: valid JSON', is_array($shipped), true);
check('update.json: stable usable', channel($shipped, false) !== null, true);
check('update.json: testing usable', channel($shipped, true) !== null, true);

// --- channel_entry -------------------------------------------------------

$m = array('stable' => entry('1.2.0-rc19'), 'testing' => entry('1.2.0-rc20'));
check('production takes stable', channel($m, false)['version'], '1.2.0-rc19');
check('staging takes newer testing', channel($m, true)['version'], '1.2.0-rc20');

$m = array('stable' => entry('1.2.0'), 'testing' => entry('1.2.0-rc20'));
check('staging falls back when stable is newer (rc < final)', channel($m, true)['version'], '1.2.0');

check('staging with no testing entry takes stable', channel(array('stable' => entry('1.2.0-rc19')), true)['version'], '1.2.0-rc19');
check('production ignores testing-only manifest', channel(array('testing' => entry('1.2.0-rc20')), false), null);

$bad = entry('1.2.0-rc21');
$bad['package'] = 'https://evil.example/tom-moving-estimate.zip';
check('package outside this repo rejected', channel(array('stable' => $bad), false), null);
$bad = entry('1.2.0-rc21');
$bad['version'] = '1.2.0; rm -rf';
check('malformed version rejected', channel(array('stable' => $bad), false), null);
check('empty manifest', channel(array(), true), null);

// --- inject_update ------------------------------------------------------

function fresh_transient(): object
{
    return (object) array('checked' => array('tom-moving-estimate/tom-moving-estimate.php' => TME_VERSION), 'response' => array(), 'no_update' => array());
}
$base = 'tom-moving-estimate/tom-moving-estimate.php';

$GLOBALS['__transients']['tme_update_manifest'] = array('stable' => entry('1.2.0-rc20'));
$t = TME_Updater::inject_update(fresh_transient());
check('newer stable → offered as update', $t->response[$base]->new_version ?? null, '1.2.0-rc20');
check('newer stable → package passed through', str_ends_with($t->response[$base]->package ?? '', 'tom-moving-estimate-1.2.0-rc20.zip'), true);

$GLOBALS['__transients']['tme_update_manifest'] = array('stable' => entry('1.2.0-rc19'));
$t = TME_Updater::inject_update(fresh_transient());
check('same version → no update offered', isset($t->response[$base]), false);
check('same version → listed in no_update (enables auto-update toggle)', isset($t->no_update[$base]), true);

$GLOBALS['__transients']['tme_update_manifest'] = array('stable' => entry('1.2.0-rc19'), 'testing' => entry('1.2.0-rc20'));
$t = TME_Updater::inject_update(fresh_transient());
check('production does not see testing build', isset($t->response[$base]), false);
$GLOBALS['__staging'] = true;
$t = TME_Updater::inject_update(fresh_transient());
check('staging sees testing build', $t->response[$base]->new_version ?? null, '1.2.0-rc20');
$GLOBALS['__staging'] = false;

$empty = (object) array('response' => array());
check('transient without checked is left alone', TME_Updater::inject_update($empty), $empty);

echo "\n" . ($failures === 0 ? "All updater harness checks passed.\n" : "{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
