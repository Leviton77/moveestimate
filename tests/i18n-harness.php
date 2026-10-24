<?php
/**
 * Standalone checks for the French / English customer form: shortcode text,
 * optional fields on create_session, REST error language and the customer
 * email. No WordPress required — the WP primitives used are stubbed below.
 *
 * Run:  php tests/i18n-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('TME_URL', 'https://example.test/wp-content/plugins/tom-moving-estimate/');
define('TME_VERSION', 'test');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);

$GLOBALS['__locale'] = 'en_CA';
$GLOBALS['__localized'] = array();
$GLOBALS['__mail'] = array();
$GLOBALS['__created'] = array();

function __($text, $domain = null) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url_raw($url) { return $url; }
function wp_kses($html, $allowed) { return $html; }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp ?? time()); }
function wp_unslash($v) { return $v; }
function is_email($email) { return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL); }
function sanitize_email($email) { return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function shortcode_atts($defaults, $atts) { return array_merge($defaults, array_intersect_key((array) $atts, $defaults)); }
function determine_locale() { return $GLOBALS['__locale']; }
function get_locale() { return $GLOBALS['__locale']; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script($handle, $name, $data) { $GLOBALS['__localized'] = $data; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . $path; }
function get_permalink() { return 'https://example.test/soumission/'; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_transient($key) { return 0; }
function set_transient($key, $value, $ttl) { return true; }
function wp_salt($scheme = 'auth') { return 'salt'; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_mail($to, $subject, $message) { $GLOBALS['__mail'][] = compact('to', 'subject', 'message'); return true; }

final class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public array $data = array()) {}
}
final class WP_REST_Response
{
    public function __construct(public $data = null, public int $status = 200) {}
}
final class WP_REST_Request
{
    public function __construct(private array $json = array(), private array $headers = array()) {}
    public function get_json_params() { return $this->json; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? null; }
}
final class WP_REST_Server { public const CREATABLE = 'POST'; public const READABLE = 'GET'; }

final class TME_Plugin
{
    public static function is_configured(): bool { return true; }
    public static function settings(): array
    {
        return array('notification_email' => 'office@example.test', 'retention_days' => 30, 'max_video_mb' => 350, 'max_photo_mb' => 15, 'max_photos' => 50);
    }
}
final class TME_DB
{
    public static function create(array $data)
    {
        $GLOBALS['__created'][] = $data;
        return (object) array_merge($data, array('id' => 7, 'public_token' => str_repeat('a', 64)));
    }
    public static function get_by_token(string $token) { return null; }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-i18n.php';
require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-public.php';

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

function localized_call(string $method, WP_REST_Request $request)
{
    $ref = new ReflectionMethod('TME_Public', 'localized');
    $ref->setAccessible(true);
    $callback = $ref->invoke(null, $method);
    return $callback($request);
}

function base_payload(array $overrides = array()): array
{
    return array_merge(array(
        'clientName'     => 'Marie Tremblay',
        'phone'          => '819-555-0100',
        'moveDate'       => gmdate('Y-m-d', time() + 10 * 86400),
        'currentAddress' => '12 rue Principale, Gatineau',
        'consent'        => true,
        'submissionType' => 'info',
    ), $overrides);
}

echo "Shortcode\n";
$html = TME_Public::shortcode(array('lang' => 'fr'));
check('lang="fr" shows French heading', str_contains($html, 'Obtenir ma soumission'));
check('lang="fr" shows French home sizes', str_contains($html, '2 chambres (4 ½)') && str_contains($html, 'value="2 bedrooms"'));
check('lang="fr" marks the form fr-CA', str_contains($html, 'lang="fr-CA"'));
check('French strings passed to the browser', ($GLOBALS['__localized']['lang'] ?? '') === 'fr' && isset($GLOBALS['__localized']['strings']['Submitting...']));
check('email is not required', (bool) preg_match('/name="email"[^>]*maxlength="190">/', $html));
check('destination is not required', (bool) preg_match('/name="destinationAddress" maxlength="255">/', $html));
check('home size is not required', str_contains($html, '<select name="estimatedSize">'));
check('phone stays required', (bool) preg_match('/name="phone"[^>]*required/', $html));

$GLOBALS['__locale'] = 'fr_CA';
check('no lang attribute follows a French page locale', str_contains(TME_Public::shortcode(), 'Obtenir ma soumission'));
$GLOBALS['__locale'] = 'en_CA';
$html_en = TME_Public::shortcode();
check('English page keeps English text', str_contains($html_en, 'Request your moving estimate') && !str_contains($html_en, 'Obtenir'));

echo "create_session\n";
$GLOBALS['__mail'] = array();
$result = localized_call('create_session', new WP_REST_Request(base_payload(), array('x-tme-lang' => 'fr')));
check('accepted without email, destination or size', $result instanceof WP_REST_Response && $result->status === 201, is_object($result) ? print_r($result, true) : '');
check('language stored as fr', ($GLOBALS['__created'][0]['language'] ?? '') === 'fr');
check('no customer email when none given', count($GLOBALS['__mail']) === 1 && $GLOBALS['__mail'][0]['to'] === 'office@example.test');
check('office email names the language', str_contains($GLOBALS['__mail'][0]['message'] ?? '', 'Language: French'));

$GLOBALS['__mail'] = array();
localized_call('create_session', new WP_REST_Request(base_payload(array('email' => 'marie@example.test')), array('x-tme-lang' => 'fr')));
$customer = $GLOBALS['__mail'][1] ?? array();
check('customer email in French', ($customer['subject'] ?? '') === 'Nous avons bien reçu votre demande de soumission' && str_contains($customer['message'] ?? '', 'Bonjour Marie Tremblay'));

$bad = localized_call('create_session', new WP_REST_Request(base_payload(array('email' => 'not-an-email')), array('x-tme-lang' => 'fr')));
check('a typed but invalid email is still refused, in French', $bad instanceof WP_Error && $bad->message === 'Veuillez entrer une adresse courriel valide.');
$missing = localized_call('create_session', new WP_REST_Request(base_payload(array('phone' => '')), array('x-tme-lang' => 'en')));
check('missing phone refused in English', $missing instanceof WP_Error && $missing->message === 'Please complete every required field.');
$size = localized_call('create_session', new WP_REST_Request(base_payload(array('estimatedSize' => 'Castle'))));
check('unknown home size refused', $size instanceof WP_Error && $size->code === 'tme_size');

echo "\n" . ($failures ? "{$failures} check(s) failed\n" : "All checks passed\n");
exit($failures ? 1 : 0);
