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
    public function __construct($payload, $route = '') { $this->data = is_array($payload) ? $payload : array(); }
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

require dirname(__DIR__) . '/tools/staging-acceptance/tme-rc31-acceptance/acceptance.php';
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
reset_fixture();
$original = $wpdb->rows;
$report = TME_RC31_Staging_Acceptance::run();
assertion($report['success'] === true, 'allowed staging run passes actual handler/DB doubles');
assertion($report['synthetic_deleted'] === 2, 'both owned fixture records are removed');
assertion($wpdb->rows === $original, 'unrelated existing row is unchanged');
assertion($report['mail_intercepted'] === 3, 'self-check and both notifications are short-circuited');
assertion(count($wpdb->deleteCalls) === 2 && count($wpdb->deleteCalls[0]) === 5, 'cleanup requires five exact ownership fields');
assertion(empty($GLOBALS['fixture_filters']['pre_wp_mail']), 'mail filter is removed after run');
assertion($_SERVER['REMOTE_ADDR'] === '192.0.2.10', 'original request context restored');
foreach (array('https://demenagementtom.ca', 'https://tommoving.ca', 'http://1227937.us19.myftpupload.com',
    'https://1227937.us19.myftpupload.com.example.test') as $origin) {
    reset_fixture(); $GLOBALS['fixture_origin'] = $origin;
    $before = $wpdb->rows; $report = TME_RC31_Staging_Acceptance::run();
    assertion(!$report['success'] && !$report['target_allowed'] && $wpdb->rows === $before && !$wpdb->deleteCalls,
        'production/lookalike/non-HTTPS origin is inert');
}
reset_fixture(); $GLOBALS['fixture_site_origin'] = 'https://demenagementtom.ca';
assertion(!TME_RC31_Staging_Acceptance::run()['success'], 'mismatched site URL blocks run');
reset_fixture(); $GLOBALS['fixture_admin'] = false;
assertion(!TME_RC31_Staging_Acceptance::run()['success'] && count($wpdb->rows) === 1, 'non-administrator cannot run fixture');
reset_fixture(); $wpdb->missingColumn = true;
assertion(!TME_RC31_Staging_Acceptance::run()['success'] && count($wpdb->rows) === 1, 'missing live columns stop before inserts');
reset_fixture(); $GLOBALS['fixture_ignore_mail_filter'] = true;
assertion(!TME_RC31_Staging_Acceptance::run()['success'] && count($wpdb->rows) === 1, 'failed mail interception stops before leads');
reset_fixture(); $wpdb->failCampaign = true;
$report = TME_RC31_Staging_Acceptance::run();
assertion(!$report['success'] && $report['synthetic_deleted'] === 1 && count($wpdb->rows) === 1, 'metadata failure still cleans the owned partial fixture');
reset_fixture(); $wpdb->failDelete = true;
$report = TME_RC31_Staging_Acceptance::run();
assertion(!$report['success'] && !empty($report['cleanup_failed']), 'cleanup failure is reported as failure');
echo "{$tests} staging-helper checks passed; all data and mail were doubles.\n";
