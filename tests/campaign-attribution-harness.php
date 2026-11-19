<?php
// Isolated WordPress/database doubles: no site connection, mail, or real database.
$root = $argv[1] ?? dirname(__DIR__) . '/wordpress-plugin/tom-moving-estimate';
define('ABSPATH', sys_get_temp_dir() . '/tme-attribution-test-' . getmypid() . '/');
define('TME_VERSION', '1.2.0-rc31-attribution-draft');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
@mkdir(ABSPATH . 'wp-admin/includes', 0700, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', '<?php');
function wp_json_encode($v) { return json_encode($v); }
function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_parse_url($value, $component = -1) { return parse_url($value, $component); }
function home_url($p = '/') { return 'https://staging.example.test' . $p; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function sanitize_email($v) { return filter_var($v, FILTER_SANITIZE_EMAIL); }
function esc_url_raw($v) { return $v; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function __($v, $domain = '') { return $v; }
function wp_unslash($v) { return $v; }
function wp_salt($v) { return 'fixture-only'; }
function get_transient($k) { return 0; }
function set_transient(...$args) { return true; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function dbDelta($sql) { $GLOBALS['schema'] = $sql; }
function wp_clear_scheduled_hook(...$args) { return true; }
function wp_next_scheduled(...$args) { return false; }
function wp_schedule_single_event(...$args) { return true; }
function wp_mail(...$args) { throw new RuntimeException('No emails may be sent by tests'); }
class WP_Error { public function __construct(public $code, public $message = '', public $data = null) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_REST_Request {
    public function __construct(private array $data) {}
    public function get_json_params() { return $this->data; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200) {}
}
class TME_Plugin {
    public static function settings() { return array('notification_email' => '', 'retention_days' => 30); }
    public static function is_configured() { return false; }
}
class FakeDatabase {
    public $prefix = 'fixture_';
    public $insert_id = 0;
    public $last_error = '';
    public $suppressed = false;
    public $failCampaign = false;
    public $throwCampaign = false;
    public $failInsert = false;
    public $rows = array();
    public function get_charset_collate() { return ''; }
    public function insert($table, $data, $formats) {
        if ($this->failInsert) return false;
        $this->insert_id++;
        $this->rows[$this->insert_id] = array_merge(array('id' => $this->insert_id), $data);
        return 1;
    }
    public function update($table, $data, $where, $formats, $whereFormats) {
        if (isset($data['campaign_attribution']) && $this->throwCampaign) throw new RuntimeException('Optional column unavailable');
        if (isset($data['campaign_attribution']) && $this->failCampaign) return false;
        $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $data);
        return 1;
    }
    public function suppress_errors($value) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
    public function prepare($query, ...$args) { return str_replace('%d', (string) ($args[0] ?? 0), $query); }
    public function get_row($query) {
        if (str_starts_with($query, 'SHOW COLUMNS')) return null;
        preg_match('/WHERE id = (\d+)/', $query, $m);
        return isset($this->rows[(int) ($m[1] ?? 0)]) ? (object) $this->rows[(int) $m[1]] : null;
    }
}
require $root . '/includes/class-tme-campaign-attribution.php';
require $root . '/includes/class-tme-google-click.php';
require $root . '/includes/class-tme-db.php';
require $root . '/includes/class-tme-public.php';
$checks = 0;
function check($ok, $message) {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS {$message}\n";
}
$now = (int) floor(microtime(true) * 1000);
$campaign = array('consent' => 'granted', 'consentAt' => $now - 1000, 'values' => array(
    'utm_source' => 'chatgpt', 'utm_medium' => 'paid', 'utm_campaign' => 'qa_20261119',
    'utm_content' => 'form', 'utm_term' => 'moving', 'private' => 'never saved',
));
$valid = TME_Campaign_Attribution::validate($campaign, $now);
check(count(json_decode($valid['campaign_attribution'], true)) === 5, 'only the five campaign keys survive');
check(!str_contains($valid['campaign_attribution'], 'private'), 'unrelated input is discarded');
check($valid['campaign_consent_at'] === gmdate('Y-m-d H:i:s', intdiv($now - 1000, 1000)), 'consent timestamp saved in UTC');
foreach (array(null, array(), array_merge($campaign, array('consent' => 'denied')),
    array_merge($campaign, array('consentAt' => $now + 1)),
    array_merge($campaign, array('consentAt' => $now - 365 * 86400 * 1000)),
    array_merge($campaign, array('consentAt' => (string) $now)),
    array_merge($campaign, array('values' => 'invalid'))) as $input) {
    check(TME_Campaign_Attribution::validate($input, $now) === array(), 'invalid/absent/expired/unconsented envelope rejected');
}
foreach (array('person@example.test', 'https://example.test', str_repeat('x', 121), array('x'), true, 42) as $value) {
    $input = $campaign;
    $input['values'] = array('utm_campaign' => $value);
    check(TME_Campaign_Attribution::validate($input, $now) === array(), 'invalid campaign value rejected');
}

$wpdb = new FakeDatabase();
TME_DB::install();
check(str_contains($GLOBALS['schema'], 'campaign_attribution longtext NULL'), 'schema adds nullable campaign metadata');
check(str_contains($GLOBALS['schema'], 'campaign_consent_at datetime NULL'), 'schema adds nullable consent time');
check(str_contains($GLOBALS['schema'], 'google_click_id varchar(512) NULL'), 'GCLID schema is retained');
check($GLOBALS['options']['tme_db_version'] === TME_VERSION, 'schema version advances');

function payload($campaign) {
    return array('clientName' => 'QA synthetic', 'email' => '', 'phone' => '2025550100',
        'currentAddress' => 'QA synthetic address', 'consent' => true, 'submissionType' => 'info',
        'language' => 'fr', 'formUrl' => 'https://staging.example.test/soumission/?private=never#secret',
        'campaignAttribution' => $campaign);
}
$response = TME_Public::create_session(new WP_REST_Request(payload($campaign)));
check($response instanceof WP_REST_Response && $response->status === 201 && $response->data['completed'] === true, 'real create_session handler completes successful initial save');
$row = $wpdb->rows[$wpdb->insert_id];
check(json_decode($row['campaign_attribution'], true)['utm_campaign'] === 'qa_20261119', 'validated campaign reaches the saved lead row through API and DB code');
check($row['form_url'] === 'https://staging.example.test/soumission/', 'form URL remains clean and separate from attribution');
check(!isset($response->data['campaign_attribution']), 'public create response does not expose attribution');

$clickPayload = payload($campaign);
$clickPayload['googleClick'] = array('gclid' => 'QA_AbC-123', 'capturedAt' => $now, 'consentAt' => $now - 1000, 'consent' => 'granted');
TME_Public::create_session(new WP_REST_Request($clickPayload));
check($wpdb->rows[$wpdb->insert_id]['google_click_id'] === 'QA_AbC-123', 'existing GCLID persistence is retained alongside UTM');

$denied = payload(array_merge($campaign, array('consent' => 'denied')));
TME_Public::create_session(new WP_REST_Request($denied));
check(!isset($wpdb->rows[$wpdb->insert_id]['campaign_attribution']), 'declined advertising consent leaves campaign fields unset');
$absent = payload(null);
TME_Public::create_session(new WP_REST_Request($absent));
check(!isset($wpdb->rows[$wpdb->insert_id]['campaign_attribution']), 'a lead can be saved without attribution');
$invalidLead = payload($campaign); $invalidLead['phone'] = '';
$count = count($wpdb->rows);
check(is_wp_error(TME_Public::create_session(new WP_REST_Request($invalidLead))) && count($wpdb->rows) === $count, 'invalid lead does not save any row');
$spam = payload($campaign); $spam['website'] = 'bot';
$spamResponse = TME_Public::create_session(new WP_REST_Request($spam));
check(!isset($spamResponse->data['completed']) && count($wpdb->rows) === $count, 'honeypot reply has no completed lead or row');

foreach (array('failCampaign', 'throwCampaign') as $failure) {
    $wpdb->$failure = true;
    $count = count($wpdb->rows);
    $reply = TME_Public::create_session(new WP_REST_Request(payload($campaign)));
    check($reply instanceof WP_REST_Response && count($wpdb->rows) === $count + 1, 'optional attribution failure does not lose or duplicate the saved lead');
    check(!$wpdb->suppressed, 'database error reporting state is restored');
    $wpdb->$failure = false;
}
$wpdb->failInsert = true;
check(is_wp_error(TME_Public::create_session(new WP_REST_Request(payload($campaign)))), 'primary database insert failure remains an error');
echo "{$checks} checks passed; no WordPress connection, database, mail, or analytics services were used.\n";
