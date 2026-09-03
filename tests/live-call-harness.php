<?php
/**
 * Standalone checks for TME_Live_Call helper logic. No WordPress required —
 * the few WP primitives the loaded methods touch are stubbed below.
 *
 * Run:  php tests/live-call-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('MB_IN_BYTES', 1048576);

$GLOBALS['__options'] = array();

function __($text, $domain = null) { return $text; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function get_option($name, $default = false) { return $GLOBALS['__options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['__options'][$name] = $value; return true; }
function add_action(...$a) {}
function add_filter(...$a) {}
function wp_next_scheduled(...$a) { return time() + 3600; }
function wp_schedule_event(...$a) {}
function wp_parse_args($args, $defaults = array())
{
    $args = is_array($args) ? $args : array();
    return array_merge($defaults, $args);
}

// WordPress ships mbstring or a polyfill; the bundled test PHP may lack it.
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return $length === null ? substr((string) $string, $start) : substr((string) $string, $start, $length);
    }
}

final class TME_Secrets
{
    public static function encrypt(string $s): string { return $s === '' ? '' : 'plain:' . $s; }
    public static function decrypt(string $s): string { return str_starts_with($s, 'plain:') ? substr($s, 6) : ''; }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-live-call.php';

// --- tiny assert harness ------------------------------------------------

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

function call_private(string $method, array $args = array())
{
    $ref = new ReflectionMethod('TME_Live_Call', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

// --- to_e164 ---------------------------------------------------------

check('to_e164: local 10-digit + default CC', call_private('to_e164', array('613-555-0100', '+1')), '+16135550100');
check('to_e164: already +1 11-digit', call_private('to_e164', array('16135550100', '+1')), '+16135550100');
check('to_e164: explicit +', call_private('to_e164', array('+44 20 7946 0000', '+1')), '+442079460000');
check('to_e164: empty', call_private('to_e164', array('', '+1')), '');
check('to_e164: CC without plus', call_private('to_e164', array('5145550123', '1')), '+15145550123');

// --- link_message --------------------------------------------------

check(
    'link_message: with name',
    call_private('link_message', array('Pat', 'https://x.test/c/1')),
    'Hi Pat, tap to start your Tom Moving video walkthrough: https://x.test/c/1'
);
check(
    'link_message: no name',
    call_private('link_message', array('', 'https://x.test/c/1')),
    'tap to start your Tom Moving video walkthrough: https://x.test/c/1'
);

// --- clip --------------------------------------------------------

check('clip: truncates then trims', call_private('clip', array('hello world', 5)), 'hello');
check('clip: trims surrounding space', call_private('clip', array('  hello world  ', 8)), 'hello');
check('clip: under limit', call_private('clip', array(' hi ', 40)), 'hi');

// --- to_mysql --------------------------------------------------

check('to_mysql: ISO', call_private('to_mysql', array('2026-09-03T19:22:00Z')), '2026-09-03 19:22:00');
check('to_mysql: empty', call_private('to_mysql', array('')), '');
check('to_mysql: junk', call_private('to_mysql', array('not a date')), '');

// --- settings / is_configured -------------------------------------

check('settings: defaults when unset', TME_Live_Call::settings()['country_code'], '+1');
check('is_configured: false when empty', TME_Live_Call::is_configured(), false);

update_option('tme_live_call_settings', array(
    'sites_base_url'    => 'https://sites.test/',
    'shared_secret_enc' => TME_Secrets::encrypt('s3cr3t'),
));
check('is_configured: true with url + secret', TME_Live_Call::is_configured(), true);

// --- cron_schedule ---------------------------------------------

$sched = call_private('cron_schedule', array(array()));
check('cron_schedule: adds five-minute entry', $sched['tme_five_minutes']['interval'], 300);

echo "\n" . ($failures === 0 ? "All live-call harness checks passed.\n" : "{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
