<?php
/**
 * Standalone checks for TME_Admin::sanitize_annotations() — specifically that
 * a saved laser point round-trips (it used to be silently dropped, since the
 * server only allowed 'draw' and 'note').
 *
 * Run:  php tests/annotations-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');

function __($text, $domain = null) { return $text; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_generate_uuid4() { return '22222222-2222-4222-8222-222222222222'; }
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return $length === null ? substr((string) $string, $start) : substr((string) $string, $start, $length);
    }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-admin.php';

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

function sanitize(array $items): array
{
    $ref = new ReflectionMethod('TME_Admin', 'sanitize_annotations');
    $ref->setAccessible(true);
    return $ref->invoke(null, wp_json_encode_stub($items));
}
function wp_json_encode_stub($v) { return json_encode($v); }

// --- laser round-trips -------------------------------------------------

$out = sanitize(array(array('id' => 'abc', 'type' => 'laser', 'time' => 12.5, 'x' => 40, 'y' => 60)));
check('laser: kept', count($out), 1);
check('laser: type preserved', $out[0]['type'], 'laser');
check('laser: time preserved', $out[0]['time'], 12.5);
check('laser: x preserved', $out[0]['x'], 40.0);
check('laser: y preserved', $out[0]['y'], 60.0);
check('laser: no stray text field', array_key_exists('text', $out[0]), false);
check('laser: no stray points field', array_key_exists('points', $out[0]), false);

// --- laser coordinates are clamped 0-100 --------------------------------

$out = sanitize(array(array('type' => 'laser', 'time' => 1, 'x' => -5, 'y' => 250)));
check('laser: x clamped to 0', (float) $out[0]['x'], 0.0);
check('laser: y clamped to 100', (float) $out[0]['y'], 100.0);

// --- existing types still work (regression) -----------------------------

$out = sanitize(array(array('type' => 'note', 'time' => 2, 'x' => 10, 'y' => 10, 'text' => 'hello')));
check('note: still kept', count($out), 1);

$out = sanitize(array(array('type' => 'note', 'time' => 2, 'x' => 10, 'y' => 10, 'text' => '')));
check('note: dropped when text is empty', count($out), 0);

$out = sanitize(array(array(
    'type' => 'draw', 'time' => 3,
    'points' => array(array('x' => 1, 'y' => 1), array('x' => 2, 'y' => 2)),
)));
check('draw: still kept', count($out), 1);

// --- unknown / malformed types are rejected -----------------------------

$out = sanitize(array(array('type' => 'evil', 'time' => 1, 'x' => 1, 'y' => 1)));
check('unknown type: dropped', count($out), 0);

echo "\n" . ($failures === 0 ? "All annotation harness checks passed.\n" : "{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
