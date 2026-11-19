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
function home_url($p = '/') { return rtrim($GLOBALS['fixture_origin'], '/') . $p; }
function site_url($p = '/') { return rtrim($GLOBALS['fixture_site_origin'] ?? $GLOBALS['fixture_origin'], '/') . $p; }
function current_user_can($capability) { return $GLOBALS['fixture_admin'] ?? true; }
function get_option($key, $default = false) { return $GLOBALS['fixture_options'][$key] ?? $default; }
function register_activation_hook($file, $callback) { $GLOBALS['fixture_activation'] = $callback; }
function add_filter($tag, $callback, $priority = 10, $argc = 1) { $GLOBALS['fixture_filters'][$tag][$priority][] = $callback; }
function remove_filter($tag, $callback, $priority = 10) { unset($GLOBALS['fixture_filters'][$tag][$priority]); }
function admin_url($path = '') { return 'https://1227937.us19.myftpupload.com/wp-admin/' . $path; }
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
function update_option($key, $value, $autoload = false) { $GLOBALS['fixture_options'][$key] = $value; }
function dbDelta($sql) { $GLOBALS['schema'] = $sql; }
function wp_clear_scheduled_hook(...$args) { return true; }
function wp_next_scheduled(...$args) { return false; }
function wp_schedule_single_event(...$args) { return true; }
function wp_mail(...$args) {
    $return = null;
    if (!empty($GLOBALS['fixture_ignore_mail_filter'])) return false;
    $filters = $GLOBALS['fixture_filters']['pre_wp_mail'] ?? array();
    ksort($filters);
    foreach ($filters as $group) foreach ($group as $callback) $return = $callback($return, array());
    if ($return === null) throw new RuntimeException('No mail transport is available in tests');
    return $return;
}
class WP_Error { public function __construct(public $code, public $message = '', public $data = null) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_REST_Request {
    private array $data;
    private string $route;
    public function get_route() { return $this->route; }
    public function get_method() { return 'POST'; }
    public function __construct($payload, $route = '') { $this->data = is_array($payload) ? $payload : array(); $this->route = $route; }
    public function set_header($key, $value) {}
    public function set_body($body) { $this->data = json_decode($body, true); }
    public function get_json_params() { return $this->data; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200) {}
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class TME_Plugin {
    public static function settings() { return array('notification_email' => 'qa@example.invalid', 'retention_days' => 30); }
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
    public $missingColumn = false;
    public $failDelete = false;
    public $deleteCalls = array();
    public function prepare($query, ...$args) {
        $index = 0;
        return preg_replace_callback('/%[ds]/', static function ($match) use (&$index, $args) {
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", $value) . "'";
        }, $query);
    }
    public function get_col($query, $index = 0) {
        return $this->missingColumn ? array('google_click_id') : array('campaign_attribution', 'campaign_consent_at', 'google_click_id');
    }
    public function get_var($query) { return count($this->rows); }
    public function get_row($query) {
        if (preg_match("/public_token = '([a-f0-9]{64})'/", $query, $match)) {
            foreach ($this->rows as $row) if (($row['public_token'] ?? null) === $match[1]) return (object) $row;
            return null;
        }
        preg_match('/WHERE id = (\\d+)/', $query, $match);
        return isset($this->rows[(int) ($match[1] ?? 0)]) ? (object) $this->rows[(int) $match[1]] : null;
    }
    public function get_results($query) {
        preg_match("/client_name = '([^']+)'/", $query, $match);
        return array_values(array_map(static fn($row) => (object) $row, array_filter($this->rows,
            static fn($row) => $row['client_name'] === ($match[1] ?? '') && $row['phone'] === '2025550100' && $row['email'] === '')));
    }
    public function delete($table, $where, $formats) {
        $this->deleteCalls[] = $where;
        if ($this->failDelete) return false;
        $row = $this->rows[$where['id']] ?? null;
        if (!$row) return 0;
        foreach ($where as $key => $value) if ($row[$key] !== $value) return 0;
        unset($this->rows[$where['id']]);
        return 1;
    }
}
require $root . '/includes/class-tme-campaign-attribution.php';
require $root . '/includes/class-tme-google-click.php';
require $root . '/includes/class-tme-db.php';
require $root . '/includes/class-tme-public.php';

function register_deactivation_hook($file, $callback) {}
function doing_action($hook) { return $GLOBALS['delegated'] ?? false; }
function plugin_basename($file) { return basename($file); }
function add_option($key, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($key, $GLOBALS['fixture_options'])) return false;
    $GLOBALS['fixture_options'][$key] = $value; return true;
}
require dirname(__DIR__) . '/tools/staging-browser-acceptance/tme-rc31-browser-qa/acceptance.php';
$tests = 0;
function assertion($condition, $message) {
    global $tests;
    if (!$condition) throw new RuntimeException($message);
    $tests++; echo "PASS {$message}\n";
}
function reset_fixture() {
    global $wpdb;
    $wpdb = new FakeDatabase();
    $wpdb->insert_id = 77;
    $wpdb->rows = array(77 => array('id' => 77, 'public_token' => str_repeat('b', 64),
        'client_name' => 'Existing synthetic sentinel', 'phone' => '2025550100', 'email' => ''));
    $GLOBALS['fixture_origin'] = 'https://1227937.us19.myftpupload.com';
    unset($GLOBALS['fixture_site_origin']);
    $GLOBALS['fixture_admin'] = true;
    $GLOBALS['fixture_options'] = array('tme_db_version' => TME_VERSION);
    $GLOBALS['fixture_filters'] = array();
    $GLOBALS['fixture_ignore_mail_filter'] = false;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
}

function payload($lang = 'fr') {
    $now = (int) floor(microtime(true) * 1000);
    return array('clientName' => 'QA-E2E-' . TME_RC31_Browser_QA::BATCH . '-' . $lang,
        'phone' => '2025550100', 'email' => '', 'currentAddress' => 'QA synthetic address - no contact',
        'consent' => true, 'submissionType' => 'info', 'language' => $lang,
        'formUrl' => TME_RC31_Browser_QA::ORIGIN . ($lang === 'fr' ? '/soumission/' : '/en/estimate/'),
        'campaignAttribution' => array('consent' => 'granted', 'consentAt' => $now - 1000,
            'values' => array('utm_source' => 'chatgpt', 'utm_medium' => 'paid', 'utm_campaign' => 'qa_e2e_20261119')),
        'googleClick' => array('consent' => 'granted', 'consentAt' => $now - 1000, 'capturedAt' => $now - 500, 'gclid' => 'QA_AbC-123'));
}
function request($p) { return new WP_REST_Request($p, '/tme/v1/sessions'); }
$stateKey = 'tme_browser_qa_' . TME_RC31_Browser_QA::BATCH;
reset_fixture(); $original = $wpdb->rows;
TME_RC31_Browser_QA::activate();
assertion(count($wpdb->rows) === 1, 'arming creates no quote');
foreach (array('fr', 'en') as $lang) {
    $req = request(payload($lang));
    assertion(TME_RC31_Browser_QA::before(null, array(), $req) === null, 'valid fixture passes real callback');
    $response = TME_Public::create_session($req);
    $actual = TME_RC31_Browser_QA::after($response, array(), $req);
    assertion($actual === $response && $response->status === 201, 'real status and token preserved');
    assertion(!array_filter($GLOBALS['fixture_options'][$stateKey]['results'][$lang], static fn($v) => !$v), 'actual DB metadata and mail verified');
    assertion(empty($GLOBALS['fixture_filters']['pre_wp_mail']), 'mail filter removed at end of request');
    assertion(TME_RC31_Browser_QA::before(null, array(), $req) instanceof WP_Error, 'same language cannot create duplicate fixture');
}
assertion(count($wpdb->rows) === 3, 'real fixture rows retained until browser verification ends');
TME_RC31_Browser_QA::deactivate();
$reportPath = dirname(__DIR__) . '/tools/staging-browser-acceptance/tme-rc31-browser-qa/report.php';
$report = json_decode(explode("\n", file_get_contents($reportPath), 2)[1], true);
assertion($report['success'] && $report['synthetic_deleted'] === 2 && $report['mail_intercepted'] === 4, 'deactivation records successful verification and cleanup');
assertion($wpdb->rows === $original && count($wpdb->deleteCalls[0]) === 5, 'five ownership predicates protect unrelated rows');
assertion(!str_contains(file_get_contents($reportPath), 'QA-E2E-'), 'report omits names and contact data');
assertion(TME_RC31_Browser_QA::before(null, array(), request(payload())) instanceof WP_Error, 'closed batch cannot be reused');
unlink($reportPath);
foreach (array('https://demenagementtom.ca', 'https://tommoving.ca', 'http://1227937.us19.myftpupload.com', 'https://1227937.us19.myftpupload.com.example.test') as $origin) {
    reset_fixture(); $GLOBALS['fixture_origin'] = $origin;
    TME_RC31_Browser_QA::activate();
    assertion(!isset($GLOBALS['fixture_options'][$stateKey]) && TME_RC31_Browser_QA::before(null, array(), request(payload())) === null, 'other origins stay inert');
}
reset_fixture(); $GLOBALS['fixture_admin'] = false;
TME_RC31_Browser_QA::activate();
assertion(!isset($GLOBALS['fixture_options'][$stateKey]), 'anonymous activation cannot arm batch');
$GLOBALS['delegated'] = true; TME_RC31_Browser_QA::activate(); $GLOBALS['delegated'] = false;
assertion(isset($GLOBALS['fixture_options'][$stateKey]), 'authenticated delegated lifecycle can arm batch');
reset_fixture(); TME_RC31_Browser_QA::activate();
$other = payload(); $other['clientName'] = 'Unrelated sentinel';
assertion(TME_RC31_Browser_QA::before(null, array(), request($other)) === null && empty($GLOBALS['fixture_filters']['pre_wp_mail']), 'ordinary requests untouched');
foreach (array('email' => 'qa@example.invalid', 'phone' => '123', 'currentAddress' => 'Other', 'language' => 'en', 'formUrl' => 'https://demenagementtom.ca/soumission/', 'submissionType' => 'video', 'consent' => false) as $key => $bad) {
    $p = payload(); $p[$key] = $bad;
    assertion(TME_RC31_Browser_QA::before(null, array(), request($p)) instanceof WP_Error, 'invalid fixture field rejected: ' . $key);
}
$GLOBALS['fixture_options'][$stateKey]['expires'] = time() - 1;
assertion(TME_RC31_Browser_QA::before(null, array(), request(payload())) instanceof WP_Error, 'expired window cannot send mail or save');
reset_fixture(); TME_RC31_Browser_QA::activate(); $GLOBALS['fixture_ignore_mail_filter'] = true;
assertion(TME_RC31_Browser_QA::before(null, array(), request(payload())) instanceof WP_Error && count($wpdb->rows) === 1, 'failed mail self-check blocks actual callback');
echo $tests . " browser helper checks passed; all mail and database were doubles.\n";
