<?php
/**
 * Standalone checks for TME_Lead_Report and the recipient/email-parsing
 * helpers on TME_Admin. No WordPress required — the few WP primitives the
 * loaded methods touch are stubbed below.
 *
 * Run:  php tests/lead-report-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');

function __($text, $domain = null) { return $text; }
function _n($single, $plural, $number, $domain = null) { return $number == 1 ? $single : $plural; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return $url; }
function absint($n) { return abs((int) $n); }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp ?? time()); }
function wp_unslash($v) { return $v; }
function is_email($email) { return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL); }
function sanitize_email($email) { return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL); }

// Minimal stand-ins for classes TME_Lead_Report touches. The real
// TME_Admin::submission_label() only calls __(), so the real file loads
// cleanly; TME_DB is stubbed since it lives outside the git-tracked plugin
// mirror (it never ships without the rest of the plugin in production).
final class TME_DB
{
    public static $photos = array();
    public static function photos(object $session): array { return self::$photos; }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-admin.php';
require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-lead-report.php';

// --- tiny assert harness ------------------------------------------------

$failures = 0;
function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "  ok  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? "\n       {$detail}" : '') . "\n";
}

function call_private_static(string $class, string $method, array $args = array())
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

function base_session(): object
{
    return (object) array(
        'id' => 42,
        'client_name' => 'Pat Example',
        'email' => 'pat@example.test',
        'phone' => '613-555-0100',
        'move_date' => '2026-11-03',
        'estimated_size' => '2 bedrooms',
        'current_address' => '1 Origin St',
        'destination_address' => '2 Destination Ave',
        'submission_type' => 'video',
        'status' => 'reviewed',
        'created_at' => '2026-08-01 10:00:00',
        'special_items' => '',
        'rep_notes' => '',
        'live_rep' => '',
        'live_started_at' => '',
        'video_key' => 'sessions/42/video.webm',
        'video_deleted_at' => null,
        'media_deleted_at' => null,
    );
}

// --- build_text: core fields always present -----------------------------

$session = base_session();
$text = TME_Lead_Report::build_text($session, null);

check('build_text: contains client name', str_contains($text, 'Pat Example'));
check('build_text: contains email', str_contains($text, 'pat@example.test'));
check('build_text: contains phone', str_contains($text, '613-555-0100'));
check('build_text: move date formatted', str_contains($text, 'November 3, 2026'));
check('build_text: home size', str_contains($text, '2 bedrooms'));
check('build_text: addresses', str_contains($text, '1 Origin St') && str_contains($text, '2 Destination Ave'));
check('build_text: submission type label', str_contains($text, 'Video'));
check('build_text: status capitalized', str_contains($text, 'Reviewed'));
check('build_text: video media summary', str_contains($text, 'Video walkthrough attached'));
check('build_text: no-AI-report note when report is null', str_contains($text, 'No AI moving report has been saved'));
check('build_text: no live-call lines for a non-live submission', !str_contains($text, 'Live walkthrough by'));

// --- build_text: optional sections only appear when populated -----------

$session2 = base_session();
$session2->special_items = "Fragile piano.\nNeeds two movers.";
$session2->rep_notes = 'Called back, confirmed date.';
$text2 = TME_Lead_Report::build_text($session2, null);
check('build_text: customer notes included when present', str_contains($text2, 'Fragile piano.'));
check('build_text: rep notes included when present', str_contains($text2, 'Called back, confirmed date.'));

$session3 = base_session();
check('build_text: customer notes omitted when blank', !str_contains(TME_Lead_Report::build_text($session3, null), 'FROM THE CUSTOMER'));

// --- build_text: live-call submission ------------------------------------

$live = base_session();
$live->submission_type = 'live';
$live->live_rep = 'Jordan Rep';
$live->live_started_at = '2026-08-01 09:30:00';
$live_text = TME_Lead_Report::build_text($live, null);
check('build_text: live submission shows rep', str_contains($live_text, 'Live walkthrough by: Jordan Rep'));
check('build_text: live submission shows call start time', str_contains($live_text, 'Call started'));
check('build_text: live submission type label', str_contains($live_text, 'Live walkthrough'));

// --- build_text: photos submission with TME_DB::photos() ----------------

$photo_session = base_session();
$photo_session->submission_type = 'photos';
$photo_session->video_key = '';
TME_DB::$photos = array(
    array('id' => 'a', 'key' => 'sessions/42/a.jpg', 'deleted_at' => null),
    array('id' => 'b', 'key' => 'sessions/42/b.jpg', 'deleted_at' => null),
    array('id' => 'c', 'key' => '', 'deleted_at' => null), // no key: not counted
);
$photo_text = TME_Lead_Report::build_text($photo_session, null);
check('build_text: photo count excludes entries without a key', str_contains($photo_text, '2 photos attached'));
TME_DB::$photos = array();

// --- build_text: with an AI report ---------------------------------------

$ai_report = array(
    'summary' => array(
        'rooms_observed' => 1,
        'moving_furniture_pieces' => 1,
        'not_moving_furniture_pieces' => 0,
        'uncertain_furniture_pieces' => 0,
        'unresolved_questions' => 1,
    ),
    'box_estimate' => array('total' => array('low' => 5, 'likely' => 7, 'high' => 9)),
    'mattress_bags' => array(
        'total_bags' => 2,
        'by_size' => array(array('size' => 'queen', 'total_bags' => 2)),
    ),
    'access' => array('origin' => array('carry_complexity' => 'medium')),
    'home_layout' => array('distribution_type' => 'distributed_household'),
    'rooms' => array(array(
        'name' => 'Living room',
        'floor' => 'main',
        'inventory' => array(array(
            'name' => 'Sectional sofa',
            'quantity' => 1,
            'move_status' => 'moving',
            'box_equivalents' => array('likely' => 0),
            'notes' => 'Confirm it separates.',
        )),
    )),
    'disassembly_plan' => array('items' => array(array(
        'item' => 'Sectional sofa',
        'likelihood' => 'may_be_needed',
        'expected_work' => 'Separate the sections.',
    ))),
    'questions' => array(array(
        'status' => 'open',
        'priority' => 'high',
        'question' => 'Is the freezer moving?',
    )),
);
$ai_text = TME_Lead_Report::build_text($session, $ai_report);
check('build_text: AI section header (fallback status, no TME_AI_Export loaded)', str_contains($ai_text, 'AI MOVING REPORT — DRAFT'));
check('build_text: AI box estimate range', str_contains($ai_text, '5 / 7 / 9'));
check('build_text: AI room + item', str_contains($ai_text, 'Living room (Main)') && str_contains($ai_text, 'Sectional sofa x1 — Moving'));
check('build_text: AI disassembly line', str_contains($ai_text, 'Separate the sections.'));
check('build_text: AI mattress bags by size', str_contains($ai_text, 'Queen: 2 bags'));
check('build_text: AI open question', str_contains($ai_text, 'Is the freezer moving?'));
check('build_text: no "no report" note once a report exists', !str_contains($ai_text, 'No AI moving report has been saved'));

// --- TME_Admin::parse_emails ----------------------------------------------

check(
    'parse_emails: splits on comma and semicolon, trims, dedupes, drops invalid',
    call_private_static('TME_Admin', 'parse_emails', array('a@x.test, b@x.test; a@x.test ,not-an-email, c@x.test')) === array('a@x.test', 'b@x.test', 'c@x.test')
);
check('parse_emails: empty string yields empty array', call_private_static('TME_Admin', 'parse_emails', array('')) === array());

if ($failures > 0) {
    echo "\n{$failures} check(s) FAILED.\n";
    exit(1);
}
echo "\nAll lead-report harness checks passed.\n";
