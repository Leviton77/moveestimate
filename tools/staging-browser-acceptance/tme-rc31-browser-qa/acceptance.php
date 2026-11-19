<?php
/**
 * Plugin Name: TME rc31 Browser QA
 * Description: Temporary exact-fixture mail isolation and verification on HTTPS Staging only. No public management endpoint.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
final class TME_RC31_Browser_QA
{
    public const ORIGIN = 'https://1227937.us19.myftpupload.com';
    public const VERSION = '1.2.0-rc31-attribution-draft';
    public const BATCH = '85017b1150fcf1661bf59beb';
    private const STATE = 'tme_browser_qa_85017b1150fcf1661bf59beb';
    private static $active = null;
    private static int $mail = 0;
    public static function target(): bool
    {
        return rtrim(home_url('/'), '/') === self::ORIGIN && rtrim(site_url('/'), '/') === self::ORIGIN;
    }
    private static function table(): string
    {
        if (!defined('TME_VERSION') || TME_VERSION !== self::VERSION || !class_exists('TME_DB')
            || get_option('tme_db_version') !== self::VERSION) { throw new RuntimeException('Unexpected plugin'); }
        $table = TME_DB::table();
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) { throw new RuntimeException('Unsafe table'); }
        return $table;
    }
    private static function authorized(string $hook): bool
    {
        return current_user_can('manage_options') || doing_action($hook . '_' . plugin_basename(__FILE__));
    }
    public static function activate(): void
    {
        global $wpdb;
        if (!self::target() || !self::authorized('activate')) { return; }
        $table = self::table();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        foreach (array('campaign_attribution', 'campaign_consent_at', 'google_click_id') as $column) {
            if (!in_array($column, $columns, true)) { throw new RuntimeException('Missing schema'); }
        }
        // Never reset a prior batch or extend its window by reactivation.
        if (!add_option(self::STATE, array('version' => self::VERSION, 'started_utc' => gmdate('c'),
            'expires' => time() + 1800, 'before' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
            'results' => array(), 'mail_intercepted' => 0), '', false)) {
            throw new RuntimeException('Batch already armed');
        }
    }
    public static function block_mail($return, $attributes)
    {
        self::$mail++;
        return true;
    }
    private static function reject(): WP_Error
    {
        return new WP_Error('tme_qa_guard', 'Synthetic QA request did not meet its isolated test guard.', array('status' => 409));
    }
    public static function before($response, $handler, $request)
    {
        if (!self::target() || $request->get_method() !== 'POST' || $request->get_route() !== '/tme/v1/sessions') { return $response; }
        $p = $request->get_json_params();
        if (!is_array($p) || !in_array($p['clientName'] ?? '', array('QA-E2E-' . self::BATCH . '-fr', 'QA-E2E-' . self::BATCH . '-en'), true)) { return $response; }
        if ($response !== null) { return $response; }
        $lang = $p['language'] ?? '';
        $state = get_option(self::STATE);
        if (!is_array($state) || $state['expires'] < time() || isset($state['closed_utc'])
            || !in_array($lang, array('fr', 'en'), true) || $p['clientName'] !== 'QA-E2E-' . self::BATCH . '-' . $lang
            || ($p['phone'] ?? '') !== '2025550100' || ($p['email'] ?? null) !== ''
            || ($p['currentAddress'] ?? '') !== 'QA synthetic address - no contact'
            || empty($p['consent']) || ($p['submissionType'] ?? 'info') !== 'info' || !empty($p['website'])
            || strtok($p['formUrl'] ?? '', '?#') !== self::ORIGIN . ($lang === 'fr' ? '/soumission/' : '/en/estimate/')) { return self::reject(); }
        try { self::table(); } catch (Throwable $e) { return self::reject(); }
        if (!add_option(self::STATE . '_' . $lang, true, '', false)) { return self::reject(); }
        self::$active = array('language' => $lang, 'payload' => $p);
        self::$mail = 0;
        add_filter('pre_wp_mail', array(__CLASS__, 'block_mail'), PHP_INT_MAX, 2);
        wp_mail('qa@example.invalid', 'Synthetic interception self-check', 'Synthetic QA only.');
        if (self::$mail !== 1) {
            remove_filter('pre_wp_mail', array(__CLASS__, 'block_mail'), PHP_INT_MAX);
            self::$active = null;
            return self::reject();
        }
        return $response; // The existing real REST callback runs unchanged.
    }
    public static function after($response, $handler, $request)
    {
        if (self::$active === null || $request->get_method() !== 'POST' || $request->get_route() !== '/tme/v1/sessions') { return $response; }
        $fixture = self::$active;
        $checks = array();
        try {
            $checks['real_http_201_completed'] = $response instanceof WP_REST_Response
                && $response->get_status() === 201 && ($response->get_data()['completed'] ?? false) === true;
            $row = $checks['real_http_201_completed'] ? TME_DB::get_by_token($response->get_data()['token'] ?? '') : null;
            $p = $fixture['payload'];
            $checks['owned_saved_row'] = $row && $row->client_name === $p['clientName'] && $row->phone === '2025550100' && $row->email === '';
            $campaign = $p['campaignAttribution'] ?? array();
            $checks['campaign_saved'] = $checks['owned_saved_row'] && ($campaign['consent'] ?? '') === 'granted'
                && ($campaign['values']['utm_campaign'] ?? '') === 'qa_e2e_20261119'
                && json_decode((string) $row->campaign_attribution, true) === $campaign['values'];
            $checks['consent_time_saved'] = $checks['owned_saved_row'] && !empty($campaign['consentAt'])
                && $row->campaign_consent_at === gmdate('Y-m-d H:i:s', intdiv((int) $campaign['consentAt'], 1000));
            $checks['gclid_saved'] = $checks['owned_saved_row'] && $row->google_click_id === 'QA_AbC-123';
            $checks['mail_intercepted'] = self::$mail >= 2;
            if ($checks['owned_saved_row']) { wp_clear_scheduled_hook('tme_send_customer_progress_email', array((int) $row->id)); }
        } catch (Throwable $e) { $checks['verification_completed'] = false; }
        finally {
            remove_filter('pre_wp_mail', array(__CLASS__, 'block_mail'), PHP_INT_MAX);
            self::$active = null;
            $state = get_option(self::STATE);
            $state['results'][$fixture['language']] = $checks;
            $state['mail_intercepted'] += self::$mail;
            update_option(self::STATE, $state, false);
        }
        return $response; // Real token and status delivered to browser without substitution.
    }
    public static function deactivate(): void
    {
        global $wpdb;
        if (!self::target() || !self::authorized('deactivate')) { return; }
        $state = get_option(self::STATE);
        if (!is_array($state)) { return; }
        $table = self::table();
        $report = array('version' => self::VERSION, 'started_utc' => $state['started_utc'],
            'finished_utc' => gmdate('c'), 'target_allowed' => true, 'checks' => array(),
            'mail_intercepted' => $state['mail_intercepted'], 'synthetic_deleted' => 0, 'success' => false);
        foreach (array('fr', 'en') as $lang) {
            foreach (($state['results'][$lang] ?? array('request_observed' => false)) as $key => $pass) {
                $report['checks'][] = array('name' => $lang . '_' . $key, 'pass' => (bool) $pass);
            }
            $name = 'QA-E2E-' . self::BATCH . '-' . $lang;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, public_token, client_name, phone, email FROM `{$table}` WHERE client_name = %s AND phone = %s AND email = %s LIMIT 3", $name, '2025550100', ''));
            foreach ($rows as $row) {
                if ($row->client_name !== $name || $row->phone !== '2025550100' || $row->email !== ''
                    || !preg_match('/\A[a-f0-9]{64}\z/D', $row->public_token)) { $report['cleanup_failed'] = true; continue; }
                wp_clear_scheduled_hook('tme_send_customer_progress_email', array((int) $row->id));
                $deleted = $wpdb->delete($table, array('id' => (int) $row->id, 'public_token' => $row->public_token,
                    'client_name' => $name, 'phone' => '2025550100', 'email' => ''), array('%d', '%s', '%s', '%s', '%s'));
                if ($deleted !== 1) { $report['cleanup_failed'] = true; } else { $report['synthetic_deleted']++; }
            }
        }
        $report['checks'][] = array('name' => 'original_row_count_restored', 'pass' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") === $state['before']);
        $report['success'] = !isset($report['cleanup_failed']) && $report['synthetic_deleted'] === 2
            && !array_filter($report['checks'], static fn($check) => !$check['pass']);
        // Close even a failed batch; it cannot be rearmed or reused accidentally.
        $state['closed_utc'] = gmdate('c');
        update_option(self::STATE, $state, false);
        if (file_put_contents(__DIR__ . '/report.php', "<?php exit; ?>\n" . wp_json_encode($report, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new RuntimeException('Could not persist protected QA report');
        }
    }
}
register_activation_hook(__FILE__, array('TME_RC31_Browser_QA', 'activate'));
register_deactivation_hook(__FILE__, array('TME_RC31_Browser_QA', 'deactivate'));
add_filter('rest_request_before_callbacks', array('TME_RC31_Browser_QA', 'before'), PHP_INT_MAX, 3);
add_filter('rest_request_after_callbacks', array('TME_RC31_Browser_QA', 'after'), PHP_INT_MAX, 3);
