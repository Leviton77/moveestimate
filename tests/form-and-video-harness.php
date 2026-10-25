<?php
/**
 * Standalone checks for the quote form (optional fields, language, customer
 * email) and the multipart video upload. R2 is faked through a
 * wp_remote_request stub; no WordPress or network needed.
 *
 * Run:  php tests/form-and-video-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('TME_URL', 'https://tommoving.ca/wp-content/plugins/tom-moving-estimate/');
define('TME_VERSION', 'test');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);

$GLOBALS['__mail'] = array();
$GLOBALS['__created'] = array();
$GLOBALS['__scheduled'] = 0;
$GLOBALS['__localized'] = array();
$GLOBALS['__requests'] = array();
$GLOBALS['__r2_parts'] = array();

function __($text, $domain = null) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp ?? time()); }
function wp_unslash($v) { return $v; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '') { return 'https://tommoving.ca' . $path; }
function admin_url($path = '') { return 'https://tommoving.ca/wp-admin/' . $path; }
function rest_url($path = '') { return 'https://tommoving.ca/wp-json/' . $path; }
function get_permalink() { return 'https://tommoving.ca/fr/estimation/'; }
function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value; }
function is_email($email) { return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL); }
function sanitize_email($email) { return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_file_name($v) { return (string) $v; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_transient($key) { return 0; }
function set_transient($key, $value, $ttl) { return true; }
function wp_salt($scheme = 'auth') { return 'salt'; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_mail($to, $subject, $message) { $GLOBALS['__mail'][] = compact('to', 'subject', 'message'); return true; }
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_unschedule_event($ts, $hook, $args = array()) { return true; }
function wp_schedule_single_event($ts, $hook, $args = array()) { $GLOBALS['__scheduled']++; return true; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script($handle, $name, $data) { $GLOBALS['__localized'] = $data; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header($r, $name) { return $r['headers'][$name] ?? ''; }
function wp_remote_request($url, $args)
{
    $GLOBALS['__requests'][] = array('method' => $args['method'], 'url' => $url, 'body' => $args['body'] ?? '');
    $query = array();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    if ($args['method'] === 'POST' && array_key_exists('uploads', $query)) {
        return array('code' => 200, 'body' => '<InitiateMultipartUploadResult><UploadId>up-123</UploadId></InitiateMultipartUploadResult>');
    }
    if ($args['method'] === 'GET' && isset($query['uploadId'])) {
        $xml = '<ListPartsResult>';
        foreach ($GLOBALS['__r2_parts'] as $n => $etag) {
            $xml .= "<Part><PartNumber>{$n}</PartNumber><ETag>&quot;{$etag}&quot;</ETag><Size>8</Size></Part>";
        }
        return array('code' => 200, 'body' => $xml . '</ListPartsResult>');
    }
    if ($args['method'] === 'POST' && isset($query['uploadId'])) {
        return array('code' => 200, 'body' => '<CompleteMultipartUploadResult><ETag>"x-3"</ETag></CompleteMultipartUploadResult>');
    }
    if ($args['method'] === 'HEAD') {
        return array('code' => 200, 'headers' => array('content-length' => 20 * 1048576, 'content-type' => 'video/mp4'));
    }
    return array('code' => 204);
}

final class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public array $data = array()) {}
}
final class WP_REST_Response
{
    public function __construct(public $data = null, public int $status = 200) {}
}
final class WP_REST_Request implements ArrayAccess
{
    public function __construct(private array $json = array(), private array $params = array()) {}
    public function get_json_params() { return $this->json; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}
final class WP_REST_Server { public const CREATABLE = 'POST'; public const READABLE = 'GET'; }

final class TME_Plugin
{
    public static function is_configured(): bool { return true; }
    public static function credentials(): array
    {
        return array('account_id' => str_repeat('a', 32), 'bucket_name' => 'tom-test', 'access_key' => 'AK', 'secret_key' => 'SK');
    }
    public static function settings(): array
    {
        return array('notification_email' => 'office@example.test', 'retention_days' => 30, 'warning_days' => 3, 'max_video_mb' => 350, 'max_photo_mb' => 15, 'max_photos' => 50);
    }
}
final class TME_Retention
{
    public static function schedule_for_session(int $id, int $uploaded): void {}
}
final class TME_DB
{
    public static ?object $session = null;
    public static function create(array $data)
    {
        $GLOBALS['__created'][] = $data;
        return (object) array_merge($data, array('id' => 7, 'public_token' => str_repeat('a', 64)));
    }
    public static function get_by_token(string $token) { return self::$session; }
    public static function get(int $id) { return self::$session; }
    public static function update(int $id, array $data, array $formats = array()): bool
    {
        foreach ($data as $k => $v) {
            self::$session->$k = $v;
        }
        return true;
    }
    public static function photos(object $session, bool $pending = false): array { return array(); }
}

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-r2.php';
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

function payload(array $overrides = array()): array
{
    return array_merge(array(
        'clientName'     => 'Marie Tremblay',
        'phone'          => '819-555-0100',
        'currentAddress' => '12 rue Principale, Gatineau',
        'consent'        => true,
        'submissionType' => 'info',
        'language'       => 'fr',
    ), $overrides);
}

function session(array $overrides = array()): object
{
    return (object) array_merge(array(
        'id' => 9, 'public_token' => str_repeat('b', 64), 'client_name' => 'Luc', 'email' => 'luc@example.test', 'phone' => '613-555-0100',
        'move_date' => null, 'submission_type' => 'video', 'submitted_at' => null, 'video_uploaded_at' => null, 'video_deleted_at' => null,
        'pending_video_key' => null, 'pending_upload_id' => null, 'video_size' => null, 'video_content_type' => null, 'language' => 'fr',
        'form_url' => 'https://tommoving.ca/fr/estimation/', 'created_at' => gmdate('Y-m-d H:i:s', time() - 600),
    ), $overrides);
}

echo "Form\n";
$html = TME_Public::shortcode(array('lang' => 'fr'));
check('email field not required', (bool) preg_match('/name="email" type="email" autocomplete="email" maxlength="190">/', $html));
check('home size not required', str_contains($html, '<select name="estimatedSize">'));
check('phone still required', (bool) preg_match('/name="phone"[^>]*required/', $html));
check('retry text passed to the browser in French', str_starts_with($GLOBALS['__localized']['i18n']['retrying_part_prefix'] ?? '', 'Connexion perdue'));

$GLOBALS['__mail'] = array();
$ok = TME_Public::create_session(new WP_REST_Request(payload(array('formUrl' => 'https://tommoving.ca/fr/estimation/?session=old'))));
check('accepted with only name, phone, address and consent', $ok instanceof WP_REST_Response && $ok->status === 201, print_r($ok, true));
check('language stored', ($GLOBALS['__created'][0]['language'] ?? '') === 'fr');
check('form page stored without query', ($GLOBALS['__created'][0]['form_url'] ?? '') === 'https://tommoving.ca/fr/estimation/');
check('office email shows phone, missing email and language', str_contains($GLOBALS['__mail'][0]['message'] ?? '', 'Phone: 819-555-0100') && str_contains($GLOBALS['__mail'][0]['message'], 'Email: (not given)') && str_contains($GLOBALS['__mail'][0]['message'], 'Language: French'));
TME_Public::create_session(new WP_REST_Request(payload(array('formUrl' => 'https://evil.example/x'))));
check('form page from another site ignored', ($GLOBALS['__created'][1]['form_url'] ?? 'x') === '');

$bad = TME_Public::create_session(new WP_REST_Request(payload(array('email' => 'nope'))));
check('typed but invalid email refused in French', $bad instanceof WP_Error && $bad->message === 'Veuillez entrer une adresse courriel valide.');
$missing = TME_Public::create_session(new WP_REST_Request(payload(array('phone' => '', 'language' => 'en'))));
check('missing phone refused in English', $missing instanceof WP_Error && $missing->message === 'Please complete every required field.');
$size = TME_Public::create_session(new WP_REST_Request(payload(array('estimatedSize' => 'Castle'))));
check('unknown home size refused', $size instanceof WP_Error && $size->code === 'tme_size');

echo "Customer email\n";
TME_DB::$session = session(array('submission_type' => 'info', 'submitted_at' => gmdate('Y-m-d H:i:s')));
$GLOBALS['__mail'] = array();
TME_Public::send_customer_progress_email(9);
check('French confirmation', ($GLOBALS['__mail'][0]['subject'] ?? '') === 'Nous avons bien reçu votre demande d’estimation' && str_contains($GLOBALS['__mail'][0]['message'], 'Bonjour Luc'));
TME_DB::$session = session();
$GLOBALS['__mail'] = array();
TME_Public::send_customer_progress_email(9);
check('unfinished video email links back to the form', str_contains($GLOBALS['__mail'][0]['message'] ?? '', 'https://tommoving.ca/fr/estimation/?session=' . str_repeat('b', 64)), $GLOBALS['__mail'][0]['message'] ?? '');
TME_DB::$session = session(array('email' => ''));
$GLOBALS['__mail'] = array();
TME_Public::send_customer_progress_email(9);
check('no customer email when none given', !$GLOBALS['__mail']);

echo "Multipart video upload\n";
TME_DB::$session = session();
$token = array('token' => str_repeat('b', 64));
$start = TME_Public::start_video_upload(new WP_REST_Request(array('contentType' => 'video/mp4', 'size' => 20 * 1048576), $token));
check('3 parts of 8 MB for a 20 MB video', is_array($start) && $start['partCount'] === 3 && $start['partSize'] === 8 * 1048576, print_r($start, true));
check('upload id kept server-side', TME_DB::$session->pending_upload_id === 'up-123' && !isset($start['uploadId']));
$GLOBALS['__scheduled'] = 0;
$urls = TME_Public::video_part_urls(new WP_REST_Request(array('parts' => array(1, 2, 3, 4)), $token));
check('part URLs only for parts that exist', is_array($urls) && array_keys($urls['urls']) === array(1, 2, 3), print_r($urls, true));
check('part URLs carry partNumber and uploadId', str_contains($urls['urls'][2], 'partNumber=2') && str_contains($urls['urls'][2], 'uploadId=up-123'));
check('customer email pushed back while uploading', $GLOBALS['__scheduled'] === 1);

$GLOBALS['__r2_parts'] = array(1 => 'e1', 2 => 'e2');
$early = TME_Public::complete_upload(new WP_REST_Request(array('key' => $start['key']), $token));
check('complete refuses while a part is missing, in French', $early instanceof WP_Error && $early->code === 'tme_upload_missing' && str_contains($early->message, 'pas encore terminé'));

$GLOBALS['__r2_parts'] = array(1 => 'e1', 2 => 'e2', 3 => 'e3');
$GLOBALS['__requests'] = array();
$done = TME_Public::complete_upload(new WP_REST_Request(array('key' => $start['key']), $token));
$complete = array_values(array_filter($GLOBALS['__requests'], static fn($r) => $r['method'] === 'POST'));
check('complete finishes the multipart upload', ($done['ok'] ?? false) === true, print_r($done, true));
check('parts listed in order', isset($complete[0]) && str_contains($complete[0]['body'], '<PartNumber>1</PartNumber><ETag>"e1"</ETag>') && str_contains($complete[0]['body'], '<PartNumber>3</PartNumber>'));
check('session marked uploaded', TME_DB::$session->video_uploaded_at !== null && TME_DB::$session->pending_upload_id === null);

$parts = TME_R2::parse_parts('<ListPartsResult><Part><PartNumber>2</PartNumber><ETag>&quot;b&quot;</ETag></Part><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part></ListPartsResult>');
check('parse_parts orders parts and decodes ETags', $parts === array(1 => '"a"', 2 => '"b"'));

echo "\n" . ($failures ? "{$failures} check(s) failed\n" : "All checks passed\n");
exit($failures ? 1 : 0);
