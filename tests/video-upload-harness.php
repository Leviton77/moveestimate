<?php
/**
 * Standalone checks for the multipart video upload and the reminder sent when
 * a customer chose photos or video but nothing arrived. R2 is faked through a
 * wp_remote_request stub; no WordPress or network needed.
 *
 * Run:  php tests/video-upload-harness.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);

$GLOBALS['__mail'] = array();
$GLOBALS['__requests'] = array();
$GLOBALS['__r2_parts'] = array();

function __($text, $domain = null) { return $text; }
function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '') { return 'https://demenagementtom.ca' . $path; }
function admin_url($path = '') { return 'https://demenagementtom.ca/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value; }
function is_email($email) { return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL); }
function sanitize_email($email) { return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_transient($key) { return 0; }
function set_transient($key, $value, $ttl) { return true; }
function wp_salt($scheme = 'auth') { return 'salt'; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_mail($to, $subject, $message) { $GLOBALS['__mail'][] = compact('to', 'subject', 'message'); return true; }
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
final class WP_REST_Request implements ArrayAccess
{
    public function __construct(private array $json = array(), private array $params = array(), private array $headers = array()) {}
    public function get_json_params() { return $this->json; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? null; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}

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
final class TME_DB
{
    public static ?object $session = null;
    public static function get_by_token(string $token) { return self::$session; }
    public static function get(int $id) { return self::$session; }
    public static function update(int $id, array $data, array $formats = array()): bool
    {
        foreach ($data as $k => $v) {
            self::$session->$k = $v;
        }
        return true;
    }
    public static function photos(object $session, bool $pending = false): array
    {
        $decoded = json_decode((string) ($session->{$pending ? 'pending_photos' : 'photos'} ?? '[]'), true);
        return is_array($decoded) ? $decoded : array();
    }
}
function wp_schedule_single_event() { return true; }

require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-i18n.php';
require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-r2.php';
require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-public.php';
require __DIR__ . '/../wordpress-plugin/tom-moving-estimate/includes/class-tme-retention.php';

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

function call(string $method, WP_REST_Request $request)
{
    $ref = new ReflectionMethod('TME_Public', 'localized');
    $ref->setAccessible(true);
    return ($ref->invoke(null, $method))($request);
}

function video_session(array $overrides = array()): object
{
    return (object) array_merge(array(
        'id' => 9, 'public_token' => str_repeat('b', 64), 'client_name' => 'Ann Smith', 'email' => 'ann@example.test', 'phone' => '613-555-0100',
        'move_date' => '2027-07-01', 'submission_type' => 'video', 'submitted_at' => null, 'video_uploaded_at' => null, 'video_deleted_at' => null,
        'pending_video_key' => null, 'pending_upload_id' => null, 'video_size' => null, 'video_content_type' => null, 'language' => 'en',
        'form_url' => 'https://demenagementtom.ca/estimate/', 'reminder_sent_at' => null, 'pending_photos' => '[]', 'photos' => '[]',
        'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ), $overrides);
}

echo "R2 helpers\n";
$parts = TME_R2::parse_parts('<ListPartsResult><Part><PartNumber>2</PartNumber><ETag>&quot;b&quot;</ETag></Part><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part></ListPartsResult>');
check('parse_parts orders parts and decodes ETags', $parts === array(1 => '"a"', 2 => '"b"'), print_r($parts, true));
$url = (new TME_R2())->part_url('sessions/9/video/x.mp4', 'up-123', 3);
check('part URL carries partNumber and uploadId', str_contains($url, 'partNumber=3') && str_contains($url, 'uploadId=up-123') && str_contains($url, 'X-Amz-Signature='));

echo "Multipart upload\n";
TME_DB::$session = video_session();
$size = 20 * 1048576;
$start = call('start_video_upload', new WP_REST_Request(array('contentType' => 'video/mp4', 'size' => $size), array('token' => str_repeat('b', 64))));
check('start returns 3 parts of 8 MB for a 20 MB video', is_array($start) && $start['partCount'] === 3 && $start['partSize'] === 8 * 1048576, print_r($start, true));
check('upload id kept server-side', TME_DB::$session->pending_upload_id === 'up-123' && !isset($start['uploadId']));

$urls = call('video_part_urls', new WP_REST_Request(array('parts' => array(1, 2, 3, 4)), array('token' => str_repeat('b', 64))));
check('part URLs only for parts that exist', is_array($urls) && array_keys($urls['urls']) === array(1, 2, 3), print_r($urls, true));

$GLOBALS['__r2_parts'] = array(1 => 'e1', 2 => 'e2');
$early = call('complete_upload', new WP_REST_Request(array('key' => $start['key']), array('token' => str_repeat('b', 64))));
check('complete refuses while a part is missing', $early instanceof WP_Error && $early->code === 'tme_upload_missing');

$GLOBALS['__r2_parts'] = array(1 => 'e1', 2 => 'e2', 3 => 'e3');
$GLOBALS['__requests'] = array();
$GLOBALS['__mail'] = array();
$done = call('complete_upload', new WP_REST_Request(array('key' => $start['key']), array('token' => str_repeat('b', 64))));
$complete = array_values(array_filter($GLOBALS['__requests'], static fn($r) => $r['method'] === 'POST'));
check('complete finishes the multipart upload', ($done['ok'] ?? false) === true, print_r($done, true));
check('completion lists all parts in order', isset($complete[0]) && str_contains($complete[0]['body'], '<PartNumber>1</PartNumber><ETag>"e1"</ETag>') && str_contains($complete[0]['body'], '<PartNumber>3</PartNumber>'));
check('session marked uploaded', TME_DB::$session->video_uploaded_at !== null && TME_DB::$session->pending_upload_id === null);

echo "Reminder\n";
TME_DB::$session = video_session(array('language' => 'fr', 'client_name' => 'Luc'));
$GLOBALS['__mail'] = array();
TME_Retention::send_unfinished_reminder(9);
check('office told nothing arrived', str_contains($GLOBALS['__mail'][0]['message'] ?? '', 'did not send anything yet'));
$customer = $GLOBALS['__mail'][1] ?? array();
check('customer reminder in French with a link back', str_contains($customer['message'] ?? '', "nous n'avons pas reçu votre vidéo") && str_contains($customer['message'] ?? '', 'https://demenagementtom.ca/estimate/?session=' . str_repeat('b', 64)), $customer['message'] ?? '');
$GLOBALS['__mail'] = array();
TME_Retention::send_unfinished_reminder(9);
check('reminder only sent once', count($GLOBALS['__mail']) === 0);

TME_DB::$session = video_session(array('email' => '', 'pending_video_key' => 'sessions/9/video/a.mp4'));
$GLOBALS['__mail'] = array();
TME_Retention::send_unfinished_reminder(9);
check('no email: office asked to call, upload noted as started', count($GLOBALS['__mail']) === 1 && str_contains($GLOBALS['__mail'][0]['message'], 'started uploading but did not finish') && str_contains($GLOBALS['__mail'][0]['message'], 'a call is the best follow-up'));

echo "\n" . ($failures ? "{$failures} check(s) failed\n" : "All checks passed\n");
exit($failures ? 1 : 0);
