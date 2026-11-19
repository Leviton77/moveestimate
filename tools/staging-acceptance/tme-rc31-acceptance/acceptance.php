<?php
/**
 * Plugin Name: TME rc31 Staging Acceptance
 * Description: One-shot, exact-host rc31 database acceptance with synthetic records and intercepted mail.
 * Version: 0.1.1
 */
if (!defined('ABSPATH')) { exit; }

final class TME_RC31_Staging_Acceptance
{
    private const ORIGIN = 'https://1227937.us19.myftpupload.com';
    private const VERSION = '1.2.0-rc31-attribution-draft';

    private static function check(array &$report, string $name, bool $ok): void
    {
        $report['checks'][] = array('name' => $name, 'pass' => $ok);
        if (!$ok) { throw new RuntimeException($name); }
    }

    public static function run(): array
    {
        global $wpdb;
        $report = array('version' => self::VERSION, 'started_utc' => gmdate('c'),
            'target_allowed' => false, 'checks' => array(), 'mail_intercepted' => 0,
            'synthetic_deleted' => 0, 'success' => false);
        $names = array();
        $before = null;
        $table = '';
        $old_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $block = static function ($return, $attributes) use (&$report) {
            $report['mail_intercepted']++;
            return true; // WordPress short-circuits wp_mail; no recipient/body is recorded.
        };
        $filter_installed = false;
        try {
            $report['target_allowed'] = rtrim(home_url('/'), '/') === self::ORIGIN
                && rtrim(site_url('/'), '/') === self::ORIGIN;
            self::check($report, 'exact_https_staging_target', $report['target_allowed']);
            self::check($report, 'administrator_activation', current_user_can('manage_options'));
            self::check($report, 'expected_plugin_loaded', defined('TME_VERSION') && TME_VERSION === self::VERSION
                && class_exists('TME_Public') && class_exists('TME_DB'));
            self::check($report, 'schema_version', get_option('tme_db_version') === self::VERSION);
            $table = TME_DB::table();
            self::check($report, 'safe_table_name', (bool) preg_match('/\A[A-Za-z0-9_]+\z/D', $table));
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
            foreach (array('campaign_attribution', 'campaign_consent_at', 'google_click_id') as $column) {
                self::check($report, 'column_' . $column, in_array($column, $columns, true));
            }
            $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $_SERVER['REMOTE_ADDR'] = '192.0.2.31'; // Isolate the fixture's rate-limit bucket.
            add_filter('pre_wp_mail', $block, PHP_INT_MIN, 2);
            $filter_installed = true;
            wp_mail('qa@example.invalid', 'Synthetic mail interception self-check', 'Synthetic test only.');
            self::check($report, 'mail_interception_self_check', $report['mail_intercepted'] > 0);

            $batch = bin2hex(random_bytes(12));
            foreach (array('fr' => 'granted', 'en' => 'denied') as $language => $choice) {
                $name = 'QA-RC31-' . $batch . '-' . $language;
                $names[] = $name;
                $now = (int) floor(microtime(true) * 1000);
                $values = array('utm_source' => 'chatgpt', 'utm_medium' => 'paid', 'utm_campaign' => 'qa_rc31_20261119');
                $payload = array(
                    'clientName' => $name, 'email' => '', 'phone' => '2025550100',
                    'currentAddress' => 'QA synthetic address - no contact',
                    'consent' => true, 'submissionType' => 'info', 'language' => $language,
                    'formUrl' => self::ORIGIN . ($language === 'fr' ? '/soumission/' : '/en/estimate/'),
                    'campaignAttribution' => array('values' => $values, 'consent' => $choice, 'consentAt' => $now - 1000),
                    'googleClick' => array('gclid' => 'QA_AbC-123', 'capturedAt' => $now - 500,
                        'consentAt' => $now - 1000, 'consent' => $choice),
                );
                $request = new WP_REST_Request('POST', '/tme/v1/sessions');
                $request->set_header('Content-Type', 'application/json');
                $request->set_body(wp_json_encode($payload));
                $response = TME_Public::create_session($request);
                self::check($report, $language . '_saved_response', $response instanceof WP_REST_Response
                    && $response->get_status() === 201 && ($response->get_data()['completed'] ?? null) === true);
                $token = $response->get_data()['token'] ?? '';
                $row = TME_DB::get_by_token($token);
                self::check($report, $language . '_owned_row', $row && $row->client_name === $name
                    && $row->phone === '2025550100' && $row->email === '');
                if ($choice === 'granted') {
                    self::check($report, 'fr_saved_campaign', json_decode((string) ($row->campaign_attribution ?? ''), true) === $values);
                    self::check($report, 'fr_saved_consent_time',
                        $row->campaign_consent_at === gmdate('Y-m-d H:i:s', intdiv($now - 1000, 1000)));
                    self::check($report, 'fr_saved_gclid', $row->google_click_id === 'QA_AbC-123');
                } else {
                    self::check($report, 'en_denied_campaign_absent', empty($row->campaign_attribution)
                        && empty($row->campaign_consent_at) && empty($row->google_click_id));
                }
            }
        } catch (Throwable $error) {
            $report['failure'] = 'acceptance_failed'; // No raw SQL, customer data, or stack trace in the report.
        } finally {
            // Only exact, unpredictable fixture names + reserved phone + empty email can be removed.
            if ($names && $table) {
                try {
                    foreach ($names as $name) {
                        $rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT id, public_token, client_name, phone, email FROM `{$table}` WHERE client_name = %s AND phone = %s AND email = %s LIMIT 3",
                            $name, '2025550100', ''
                        ));
                        foreach ($rows as $row) {
                            if ($row->client_name !== $name || $row->phone !== '2025550100' || $row->email !== ''
                                || !preg_match('/\A[a-f0-9]{64}\z/D', $row->public_token)) {
                                $report['cleanup_failed'] = true;
                                continue;
                            }
                            wp_clear_scheduled_hook('tme_send_customer_progress_email', array((int) $row->id));
                            $deleted = $wpdb->delete($table, array('id' => (int) $row->id,
                                'public_token' => $row->public_token, 'client_name' => $name,
                                'phone' => '2025550100', 'email' => ''), array('%d', '%s', '%s', '%s', '%s'));
                            if ($deleted !== 1) { $report['cleanup_failed'] = true; }
                            else { $report['synthetic_deleted']++; }
                        }
                    }
                    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
                    $report['checks'][] = array('name' => 'original_row_count_restored', 'pass' => $after === $before);
                } catch (Throwable $error) { $report['cleanup_failed'] = true; }
            }
            if ($old_ip === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $old_ip; }
            if ($filter_installed) { remove_filter('pre_wp_mail', $block, PHP_INT_MIN); }
        }
        $all = !array_filter($report['checks'], static fn($check) => !$check['pass']);
        $report['success'] = $all && !isset($report['failure']) && !isset($report['cleanup_failed'])
            && $report['synthetic_deleted'] === 2;
        $report['finished_utc'] = gmdate('c');
        return $report;
    }

    public static function activate(): void
    {
        $report = self::run();
        if (!$report['target_allowed']) { return; } // Inert on any other site, including Production.
        $protected = "<?php exit; ?>\n" . wp_json_encode($report, JSON_PRETTY_PRINT);
        if (file_put_contents(__DIR__ . '/report.php', $protected, LOCK_EX) === false) {
            throw new RuntimeException('Could not write protected acceptance report');
        }
    }
}
register_activation_hook(__FILE__, array('TME_RC31_Staging_Acceptance', 'activate'));
