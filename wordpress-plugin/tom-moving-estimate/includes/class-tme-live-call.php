<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Live walkthrough integration.
 *
 * The real-time call runs on the separate Sites app. This class starts a call,
 * hands the rep the links, and — when the call ends — pulls the recording and
 * captured contact details back into wp_tme_sessions as a new
 * submission_type = 'live' request, so it lands in the normal estimate queue,
 * review screen and retention policy.
 *
 * Transport: a shared secret (WP_SHARED_SECRET on the Sites side) sent as an
 * Authorization: Bearer header. See /api/calls* on the Sites app.
 */
final class TME_Live_Call
{
    private const CAPABILITY  = 'tme_manage_estimates';
    private const OPTION       = 'tme_live_call_settings';
    private const START_TTL     = 604800; // 7 days, in seconds
    private const CRON_HOOK     = 'tme_live_import_sweep';
    private const CRON_SCHEDULE = 'tme_five_minutes';
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('admin_post_tme_live_start', array(__CLASS__, 'handle_start'));
        add_action('admin_post_tme_live_send', array(__CLASS__, 'handle_send'));
        add_action('admin_post_tme_live_import', array(__CLASS__, 'handle_import'));
        add_action('admin_post_tme_live_save_settings', array(__CLASS__, 'handle_save_settings'));

        add_filter('cron_schedules', array(__CLASS__, 'cron_schedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'sweep'));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function cron_schedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every five minutes', 'tom-moving-estimate'),
            );
        }
        return $schedules;
    }

    // --- settings ----------------------------------------------------------

    public static function defaults(): array
    {
        return array(
            'sites_base_url'    => '',
            'shared_secret_enc' => '',
            'twilio_sid'        => '',
            'twilio_token_enc'  => '',
            'twilio_from'       => '',
            'country_code'      => '+1',
        );
    }

    public static function settings(): array
    {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    private static function base_url(): string
    {
        return untrailingslashit(trim((string) self::settings()['sites_base_url']));
    }

    private static function shared_secret(): string
    {
        // trim() heals a value pasted into the settings field with stray
        // whitespace or a trailing newline.
        return trim(TME_Secrets::decrypt((string) self::settings()['shared_secret_enc']));
    }

    public static function is_configured(): bool
    {
        return self::base_url() !== '' && self::shared_secret() !== '';
    }

    private static function twilio_ready(): bool
    {
        $s = self::settings();
        return $s['twilio_sid'] && $s['twilio_from'] && TME_Secrets::decrypt((string) $s['twilio_token_enc']) !== '';
    }

    // --- Sites API -------------------------------------------------------

    /**
     * @return array|WP_Error decoded JSON body, or WP_Error
     */
    private static function api(string $method, string $path, array $body = null)
    {
        if (!self::is_configured()) {
            return new WP_Error('tme_live_unconfigured', __('The live walkthrough service is not configured.', 'tom-moving-estimate'));
        }
        $args = array(
            'method'  => $method,
            'timeout' => 25,
            'headers' => array(
                'Authorization' => 'Bearer ' . self::shared_secret(),
                'Accept'        => 'application/json',
            ),
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::base_url() . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['error'])
                ? (string) $data['error']
                : sprintf(__('The live walkthrough service returned HTTP %d.', 'tom-moving-estimate'), $code);
            return new WP_Error('tme_live_http_' . $code, $message);
        }
        return is_array($data) ? $data : array();
    }

    // --- admin menu + page ---------------------------------------------

    public static function menu(): void
    {
        add_submenu_page(
            'tme-estimates',
            __('Live Walkthrough', 'tom-moving-estimate'),
            __('Live Walkthrough', 'tom-moving-estimate'),
            self::CAPABILITY,
            'tme-live',
            array(__CLASS__, 'render_page')
        );
    }

    private static function page_url(array $extra = array()): string
    {
        return add_query_arg(array_merge(array('page' => 'tme-live'), $extra), admin_url('admin.php'));
    }

    private static function notice_url(string $message, string $type = 'success', array $extra = array()): string
    {
        return add_query_arg(array_merge(array(
            'page'       => 'tme-live',
            'tme_notice' => $message,
            'tme_type'   => $type,
        ), $extra), admin_url('admin.php'));
    }

    private static function render_notice(): void
    {
        if (empty($_GET['tme_notice'])) {
            return;
        }
        $message = sanitize_text_field(wp_unslash($_GET['tme_notice']));
        $type = (!empty($_GET['tme_type']) && $_GET['tme_type'] === 'error') ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage moving estimates.', 'tom-moving-estimate'), '', array('response' => 403));
        }

        $call_id = isset($_GET['call']) ? sanitize_text_field(wp_unslash($_GET['call'])) : '';
        $call = ($call_id && preg_match(self::UUID_RE, $call_id)) ? get_transient('tme_live_' . $call_id) : false;
        ?>
        <div class="wrap tme-admin">
            <div class="tme-admin-heading">
                <div>
                    <h1><?php esc_html_e('Live Walkthrough', 'tom-moving-estimate'); ?></h1>
                    <p><?php esc_html_e('Start a guided video call with a customer. The recording and their contact details come back here as a new estimate request.', 'tom-moving-estimate'); ?></p>
                </div>
            </div>
            <?php self::render_notice(); ?>

            <?php if (!self::is_configured()) : ?>
                <div class="notice notice-warning"><p><strong><?php esc_html_e('Not connected.', 'tom-moving-estimate'); ?></strong> <?php esc_html_e('Add the Sites app URL and shared secret below before starting a call.', 'tom-moving-estimate'); ?></p></div>
            <?php endif; ?>

            <?php if (is_array($call)) : ?>
                <?php self::render_call_ready($call_id, $call); ?>
            <?php elseif (self::is_configured()) : ?>
                <?php self::render_start_form(); ?>
            <?php endif; ?>

            <?php if (current_user_can('manage_options')) : ?>
                <?php self::render_settings_form(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_start_form(): void
    {
        ?>
        <div class="tme-table-card" style="padding:20px;max-width:640px">
            <h2><?php esc_html_e('Start a call', 'tom-moving-estimate'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tme_live_start">
                <?php wp_nonce_field('tme_live_start'); ?>
                <p><label><?php esc_html_e('Customer name (optional)', 'tom-moving-estimate'); ?><br>
                    <input type="text" name="client_name" class="regular-text" maxlength="120"></label></p>
                <p><label><?php esc_html_e('Customer mobile (optional — for the text/email link)', 'tom-moving-estimate'); ?><br>
                    <input type="text" name="client_phone" class="regular-text" maxlength="40"></label></p>
                <p><label><?php esc_html_e('Customer email (optional)', 'tom-moving-estimate'); ?><br>
                    <input type="email" name="client_email" class="regular-text" maxlength="190"></label></p>
                <p><label><?php esc_html_e('Customer’s language', 'tom-moving-estimate'); ?><br>
                    <select name="client_locale">
                        <option value="en"><?php esc_html_e('English', 'tom-moving-estimate'); ?></option>
                        <option value="fr"><?php esc_html_e('French', 'tom-moving-estimate'); ?></option>
                    </select></label>
                    <span class="description"><?php esc_html_e('The customer’s whole call screen, contact form and confirmation messages show in this language. This does not change anything on your (the rep’s) screen.', 'tom-moving-estimate'); ?></span></p>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Start live walkthrough', 'tom-moving-estimate'); ?></button></p>
            </form>
        </div>
        <?php
    }

    private static function render_call_ready(string $call_id, array $call): void
    {
        $client_url = (string) ($call['client_url'] ?? '');
        $rep_url    = (string) ($call['rep_url'] ?? '');
        $name       = (string) ($call['client_name'] ?? '');
        $phone      = (string) ($call['client_phone'] ?? '');
        $email      = (string) ($call['client_email'] ?? '');
        $locale     = (string) ($call['client_locale'] ?? 'en') === 'fr' ? 'fr' : 'en';
        $sms_body   = self::link_message($name, $client_url, $locale);
        ?>
        <div class="tme-table-card" style="padding:20px;max-width:720px">
            <h2><?php esc_html_e('Call ready', 'tom-moving-estimate'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: %s: English or French */
                __('Customer’s language for this call: %s', 'tom-moving-estimate'),
                $locale === 'fr' ? __('French', 'tom-moving-estimate') : __('English', 'tom-moving-estimate')
            )); ?></p>
            <p class="description"><strong><?php esc_html_e('Recommended: copy this link and paste it into a new browser tab yourself (Ctrl/Cmd+T, paste, Enter).', 'tom-moving-estimate'); ?></strong> <?php esc_html_e('On some browsers, clicking a link straight into the call can cause the tab to close itself the moment the call ends, with no way back to Finish in Tom Estimator. Pasting the link into a tab you opened yourself has not shown that problem.', 'tom-moving-estimate'); ?></p>
            <p><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr($rep_url); ?>"></p>
            <p class="description"><?php esc_html_e('Or, if you\'d rather just click through:', 'tom-moving-estimate'); ?> <a class="button button-primary" href="<?php echo esc_url($rep_url); ?>"><?php esc_html_e('Open the call', 'tom-moving-estimate'); ?></a></p>

            <h3><?php esc_html_e('Send the customer their link', 'tom-moving-estimate'); ?></h3>
            <p><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr($client_url); ?>"></p>
            <p>
                <?php if ($phone) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="tme_live_send">
                        <input type="hidden" name="call_id" value="<?php echo esc_attr($call_id); ?>">
                        <input type="hidden" name="method" value="sms">
                        <?php wp_nonce_field('tme_live_send_' . $call_id); ?>
                        <button class="button" type="submit"<?php echo self::twilio_ready() ? '' : ' disabled title="Add Twilio settings below"'; ?>><?php esc_html_e('Text the link', 'tom-moving-estimate'); ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($email) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="tme_live_send">
                        <input type="hidden" name="call_id" value="<?php echo esc_attr($call_id); ?>">
                        <input type="hidden" name="method" value="email">
                        <?php wp_nonce_field('tme_live_send_' . $call_id); ?>
                        <button class="button" type="submit"><?php esc_html_e('Email the link', 'tom-moving-estimate'); ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($phone) : ?>
                    <a class="button" href="<?php echo esc_attr('sms:' . rawurlencode($phone) . '?&body=' . rawurlencode($sms_body)); ?>"><?php esc_html_e('Text from my phone', 'tom-moving-estimate'); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_attr('mailto:' . rawurlencode($email) . '?subject=' . rawurlencode(self::email_subject($locale)) . '&body=' . rawurlencode($sms_body)); ?>"><?php esc_html_e('Email from my mail app', 'tom-moving-estimate'); ?></a>
            </p>

            <p class="description"><?php esc_html_e('When the call ends, click “Finish in Tom Estimator” on the call screen. If you close the tab first, it imports automatically within a few minutes.', 'tom-moving-estimate'); ?></p>
            <p><a href="<?php echo esc_url(self::page_url()); ?>"><?php esc_html_e('Start another call', 'tom-moving-estimate'); ?></a></p>
        </div>
        <?php
    }

    private static function render_settings_form(): void
    {
        $s = self::settings();
        ?>
        <div class="tme-table-card" style="padding:20px;max-width:720px;margin-top:24px">
            <h2><?php esc_html_e('Live walkthrough settings', 'tom-moving-estimate'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tme_live_save_settings">
                <?php wp_nonce_field('tme_live_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="tme-sites-url"><?php esc_html_e('Sites app URL', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="sites_base_url" id="tme-sites-url" type="url" class="regular-text" value="<?php echo esc_attr($s['sites_base_url']); ?>" placeholder="https://moveestimate-tom-moving.temach.chatgpt.site"></td></tr>
                    <tr><th scope="row"><label for="tme-shared-secret"><?php esc_html_e('Shared secret', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="shared_secret" id="tme-shared-secret" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo $s['shared_secret_enc'] ? esc_attr__('•••••• saved', 'tom-moving-estimate') : ''; ?>">
                        <p class="description"><?php esc_html_e('Must match WP_SHARED_SECRET on the Sites deployment. Leave blank to keep the saved value.', 'tom-moving-estimate'); ?></p></td></tr>
                    <tr><th scope="row"><label for="tme-twilio-sid"><?php esc_html_e('Twilio Account SID', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="twilio_sid" id="tme-twilio-sid" type="text" class="regular-text" value="<?php echo esc_attr($s['twilio_sid']); ?>"></td></tr>
                    <tr><th scope="row"><label for="tme-twilio-token"><?php esc_html_e('Twilio Auth Token', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="twilio_token" id="tme-twilio-token" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo $s['twilio_token_enc'] ? esc_attr__('•••••• saved', 'tom-moving-estimate') : ''; ?>"></td></tr>
                    <tr><th scope="row"><label for="tme-twilio-from"><?php esc_html_e('Twilio “from” number', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="twilio_from" id="tme-twilio-from" type="text" class="regular-text" value="<?php echo esc_attr($s['twilio_from']); ?>" placeholder="+16135550100"></td></tr>
                    <tr><th scope="row"><label for="tme-country-code"><?php esc_html_e('Default country code', 'tom-moving-estimate'); ?></label></th>
                        <td><input name="country_code" id="tme-country-code" type="text" class="small-text" value="<?php echo esc_attr($s['country_code']); ?>"></td></tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'tom-moving-estimate'); ?></button></p>
            </form>
        </div>
        <?php
    }

    // --- handlers ------------------------------------------------------

    public static function handle_start(): void
    {
        self::require_cap();
        check_admin_referer('tme_live_start');

        $user = wp_get_current_user();
        $result = self::api('POST', '/api/calls', array(
            'rep_email'     => $user->user_email,
            'rep_name'      => $user->display_name,
            'client_name'   => self::post_text('client_name', 120),
            'client_phone'  => self::post_text('client_phone', 40),
            'client_email'  => self::post_text('client_email', 190),
            'client_locale' => self::post_locale(),
        ));
        if (is_wp_error($result)) {
            wp_safe_redirect(self::notice_url($result->get_error_message(), 'error'));
            exit;
        }

        $call_id = (string) ($result['call_id'] ?? '');
        if (!preg_match(self::UUID_RE, $call_id)) {
            wp_safe_redirect(self::notice_url(__('The service did not return a valid call id.', 'tom-moving-estimate'), 'error'));
            exit;
        }

        set_transient('tme_live_' . $call_id, array(
            'rep_url'       => (string) ($result['rep_url'] ?? ''),
            'client_url'    => (string) ($result['client_url'] ?? ''),
            'client_name'   => self::post_text('client_name', 120),
            'client_phone'  => self::post_text('client_phone', 40),
            'client_email'  => self::post_text('client_email', 190),
            'client_locale' => self::post_locale(),
            'created_by'    => get_current_user_id(),
            'created_at'    => time(),
        ), self::START_TTL);

        wp_safe_redirect(self::page_url(array('call' => $call_id)));
        exit;
    }

    public static function handle_send(): void
    {
        self::require_cap();
        $call_id = isset($_POST['call_id']) ? sanitize_text_field(wp_unslash($_POST['call_id'])) : '';
        check_admin_referer('tme_live_send_' . $call_id);

        $call = preg_match(self::UUID_RE, $call_id) ? get_transient('tme_live_' . $call_id) : false;
        if (!is_array($call)) {
            wp_safe_redirect(self::notice_url(__('That call link has expired. Start a new call.', 'tom-moving-estimate'), 'error'));
            exit;
        }
        $method = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        $locale = (string) ($call['client_locale'] ?? 'en') === 'fr' ? 'fr' : 'en';
        $body   = self::link_message((string) $call['client_name'], (string) $call['client_url'], $locale);

        if ($method === 'email') {
            $to = sanitize_email((string) $call['client_email']);
            $ok = $to && wp_mail($to, self::email_subject($locale), $body);
            $msg = $ok ? __('Email sent.', 'tom-moving-estimate') : __('Could not send the email.', 'tom-moving-estimate');
            wp_safe_redirect(self::notice_url($msg, $ok ? 'success' : 'error', array('call' => $call_id)));
            exit;
        }

        if ($method === 'sms') {
            $sent = self::twilio_send((string) $call['client_phone'], $body);
            if (is_wp_error($sent)) {
                wp_safe_redirect(self::notice_url($sent->get_error_message(), 'error', array('call' => $call_id)));
                exit;
            }
            wp_safe_redirect(self::notice_url(__('Text message sent.', 'tom-moving-estimate'), 'success', array('call' => $call_id)));
            exit;
        }

        wp_safe_redirect(self::notice_url(__('Unknown send method.', 'tom-moving-estimate'), 'error', array('call' => $call_id)));
        exit;
    }

    /**
     * Import a finished call. Linked from the Sites "Finish in Tom Estimator"
     * button (cross-site, so no nonce — the WordPress capability plus the fact
     * that the call id must resolve on the Sites side is the gate).
     */
    public static function handle_import(): void
    {
        self::require_cap();
        $call_id = isset($_REQUEST['call_id']) ? sanitize_text_field(wp_unslash($_REQUEST['call_id'])) : '';
        if (!preg_match(self::UUID_RE, $call_id)) {
            wp_die(esc_html__('Invalid call id.', 'tom-moving-estimate'), '', array('response' => 400));
        }

        $result = self::import_one($call_id);
        if (is_wp_error($result)) {
            // Keep ?call= on the bounce-back so the page shows the call's
            // links/status again instead of falling through to a blank
            // "start a new call" form -- landing there read as an empty
            // page with nothing explaining what happened.
            wp_safe_redirect(self::notice_url(
                $result->get_error_message(),
                'error',
                array('call' => $call_id)
            ));
            exit;
        }
        wp_safe_redirect(add_query_arg(array(
            'page'       => 'tme-estimates',
            'session'    => $result,
            'tme_notice' => rawurlencode(__('Live walkthrough imported.', 'tom-moving-estimate')),
            'tme_type'   => 'success',
        ), admin_url('admin.php')));
        exit;
    }

    public static function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'tom-moving-estimate'), '', array('response' => 403));
        }
        check_admin_referer('tme_live_save_settings');

        $current = self::settings();
        $next = array(
            'sites_base_url' => esc_url_raw(trim((string) wp_unslash($_POST['sites_base_url'] ?? ''))),
            'twilio_sid'     => trim(sanitize_text_field(wp_unslash($_POST['twilio_sid'] ?? ''))),
            'twilio_from'    => trim(sanitize_text_field(wp_unslash($_POST['twilio_from'] ?? ''))),
            'country_code'   => trim(sanitize_text_field(wp_unslash($_POST['country_code'] ?? '+1'))),
        );

        $secret = trim((string) wp_unslash($_POST['shared_secret'] ?? ''));
        $next['shared_secret_enc'] = $secret !== ''
            ? TME_Secrets::encrypt($secret)
            : (string) $current['shared_secret_enc'];

        $token = trim((string) wp_unslash($_POST['twilio_token'] ?? ''));
        $next['twilio_token_enc'] = $token !== ''
            ? TME_Secrets::encrypt($token)
            : (string) $current['twilio_token_enc'];

        update_option(self::OPTION, $next, false);
        wp_safe_redirect(self::notice_url(__('Live walkthrough settings saved.', 'tom-moving-estimate')));
        exit;
    }

    // --- import core --------------------------------------------------

    /**
     * @return int|WP_Error the new wp_tme_sessions id, or WP_Error
     */
    public static function import_one(string $call_id)
    {
        global $wpdb;
        $table = TME_DB::table();

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE live_call_id = %s LIMIT 1",
            $call_id
        ));
        if ($existing) {
            self::ack($call_id, $existing);
            return $existing;
        }

        if (get_transient('tme_live_importing_' . $call_id)) {
            return new WP_Error('tme_live_busy', __('This call is already being imported.', 'tom-moving-estimate'));
        }
        set_transient('tme_live_importing_' . $call_id, 1, 5 * MINUTE_IN_SECONDS);

        $done = static function ($value) use ($call_id) {
            delete_transient('tme_live_importing_' . $call_id);
            return $value;
        };

        $call = self::api('GET', '/api/calls/' . rawurlencode($call_id));
        if (is_wp_error($call)) {
            return $done($call);
        }

        $status = (string) ($call['status'] ?? '');
        $recording = isset($call['recording']) && is_array($call['recording']) ? $call['recording'] : null;
        if (!in_array($status, array('uploaded', 'completed'), true) || !$recording) {
            return $done(new WP_Error('tme_live_not_ready', __('The recording isn\'t uploaded yet. If the call just ended, wait a minute and try Finish again — it will also be picked up automatically within a few minutes. If the customer closed their browser instead of ending the call normally, the recording never uploaded and can\'t be recovered.', 'tom-moving-estimate')));
        }

        if (!TME_Plugin::is_configured()) {
            return $done(new WP_Error('tme_r2_unconfigured', __('Connect Cloudflare R2 in Settings before importing calls.', 'tom-moving-estimate')));
        }

        $max_bytes = (int) TME_Plugin::settings()['max_video_mb'] * MB_IN_BYTES;
        $size = (int) ($recording['size'] ?? 0);
        if ($max_bytes && $size > $max_bytes) {
            return $done(new WP_Error('tme_live_too_big', sprintf(
                __('The recording is %s, larger than the %d MB limit.', 'tom-moving-estimate'),
                size_format($size),
                (int) TME_Plugin::settings()['max_video_mb']
            )));
        }

        $content_type = (string) ($recording['content_type'] ?? 'video/mp4');
        $extension = str_contains($content_type, 'webm') ? 'webm' : 'mp4';
        $tmp = self::download((string) ($recording['url'] ?? ''));
        if (is_wp_error($tmp)) {
            return $done($tmp);
        }

        $key = 'live-calls/' . $call_id . '/' . wp_generate_uuid4() . '.' . $extension;
        $put = self::r2_put_file($key, $tmp, $content_type);
        @unlink($tmp);
        if (is_wp_error($put)) {
            return $done($put);
        }

        $contact = is_array($call['contact'] ?? null) ? $call['contact'] : array();
        $rep = trim((string) ($call['rep_name'] ?? '')) ?: (string) ($call['rep_email'] ?? '');
        $started = self::to_mysql((string) ($call['created_at'] ?? ''));
        $now = current_time('mysql', true);
        $uploaded_ts = time();
        $settings = TME_Plugin::settings();
        $expires = gmdate('Y-m-d H:i:s', $uploaded_ts + ((int) $settings['retention_days'] * DAY_IN_SECONDS));

        // The DB column is a NOT NULL date; the client's form is optional, so
        // fall back to today when they didn't give one (or gave something
        // malformed -- the Sites API already validates the format, but don't
        // trust it blindly here either).
        $move_date = (string) ($contact['move_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $move_date)) {
            $move_date = gmdate('Y-m-d');
        }

        $row = TME_DB::create(array(
            'client_name'         => self::clip((string) ($contact['name'] ?? ''), 120) ?: __('Live walkthrough', 'tom-moving-estimate'),
            'email'               => self::clip(sanitize_email((string) ($contact['email'] ?? '')), 190),
            'phone'               => self::clip((string) ($contact['phone'] ?? ''), 40),
            'move_date'           => $move_date,
            'current_address'     => self::clip((string) ($contact['current_address'] ?? ''), 255),
            'destination_address' => self::clip((string) ($contact['destination_address'] ?? ''), 255),
            'estimated_size'      => self::clip((string) ($contact['home_size'] ?? ''), 32),
            'special_items'       => self::clip((string) ($contact['note'] ?? ''), 1200) ?: null,
            'submission_type'     => 'live',
            'submitted_at'        => $now,
        ));
        if (is_wp_error($row) || !$row) {
            (new TME_R2())->delete($key);
            return $done(new WP_Error('tme_live_db', __('Could not create the estimate request.', 'tom-moving-estimate')));
        }
        $id = (int) $row->id;

        TME_DB::update($id, array(
            'video_key'          => $key,
            'video_content_type' => $content_type,
            'video_size'         => $size,
            'video_uploaded_at'  => $now,
            'video_expires_at'   => $expires,
            'live_call_id'       => $call_id,
            'live_rep'           => self::clip($rep, 190),
            'live_started_at'    => $started ?: $now,
            'rep_notes'          => sprintf(
                /* translators: 1: date, 2: rep name */
                __("Imported from a live walkthrough on %1\$s with %2\$s.", 'tom-moving-estimate'),
                $started ?: $now,
                $rep ?: __('a representative', 'tom-moving-estimate')
            ),
        ));

        TME_Retention::schedule_for_session($id, $uploaded_ts);
        self::ack($call_id, $id);

        return $done($id);
    }

    public static function sweep(): void
    {
        if (!self::is_configured()) {
            return;
        }
        $list = self::api('GET', '/api/calls?ingested=0');
        if (is_wp_error($list) || empty($list['calls']) || !is_array($list['calls'])) {
            return;
        }
        foreach ($list['calls'] as $entry) {
            $call_id = is_array($entry) ? (string) ($entry['call_id'] ?? '') : '';
            if (preg_match(self::UUID_RE, $call_id)) {
                $result = self::import_one($call_id);
                if (is_wp_error($result)) {
                    error_log('[tme-live] import ' . $call_id . ' failed: ' . $result->get_error_message());
                }
            }
        }
    }

    private static function ack(string $call_id, int $wp_id): void
    {
        $result = self::api('POST', '/api/calls/' . rawurlencode($call_id) . '/ingested', array(
            'wp_request_id' => (string) $wp_id,
        ));
        if (is_wp_error($result)) {
            error_log('[tme-live] ingested ack for ' . $call_id . ' failed: ' . $result->get_error_message());
        }
        delete_transient('tme_live_' . $call_id);
    }

    // --- media transfer --------------------------------------------

    /**
     * @return string|WP_Error temp file path
     */
    private static function download(string $url)
    {
        if (!$url) {
            return new WP_Error('tme_live_no_url', __('The service did not provide a recording URL.', 'tom-moving-estimate'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url($url, 300);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        return $tmp;
    }

    /**
     * Streams a local file to R2 with a presigned PUT. Uses cURL so a large
     * recording is not buffered into memory.
     *
     * @return true|WP_Error
     */
    private static function r2_put_file(string $key, string $path, string $content_type)
    {
        $r2 = new TME_R2();
        if (!$r2->ready()) {
            return new WP_Error('tme_r2_unconfigured', __('R2 storage is not configured.', 'tom-moving-estimate'));
        }
        $url = $r2->upload_url($key);
        $size = (int) filesize($path);

        if (function_exists('curl_init')) {
            $handle = fopen($path, 'rb');
            if (!$handle) {
                return new WP_Error('tme_live_read', __('Could not read the downloaded recording.', 'tom-moving-estimate'));
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_PUT            => true,
                CURLOPT_INFILE         => $handle,
                CURLOPT_INFILESIZE     => $size,
                CURLOPT_HTTPHEADER     => array('Content-Type: ' . $content_type),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_FOLLOWLOCATION => false,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($handle);
            if ($code >= 200 && $code < 300) {
                return true;
            }
            return new WP_Error('tme_r2_put', $err ?: sprintf(__('R2 upload failed with HTTP %d.', 'tom-moving-estimate'), $code));
        }

        // Fallback: buffered PUT (only safe for smaller files).
        $response = wp_remote_request($url, array(
            'method'  => 'PUT',
            'timeout' => 600,
            'headers' => array('Content-Type' => $content_type),
            'body'    => file_get_contents($path),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300)
            ? true
            : new WP_Error('tme_r2_put', sprintf(__('R2 upload failed with HTTP %d.', 'tom-moving-estimate'), $code));
    }

    // --- Twilio --------------------------------------------------

    /**
     * @return true|WP_Error
     */
    private static function twilio_send(string $to, string $body)
    {
        $s = self::settings();
        $token = trim(TME_Secrets::decrypt((string) $s['twilio_token_enc']));
        if (!$s['twilio_sid'] || !$token || !$s['twilio_from']) {
            return new WP_Error('tme_twilio_unconfigured', __('Add the Twilio SID, token and from-number in settings.', 'tom-moving-estimate'));
        }
        $e164 = self::to_e164($to, (string) $s['country_code']);
        if (!$e164) {
            return new WP_Error('tme_twilio_number', __('That mobile number does not look valid.', 'tom-moving-estimate'));
        }
        $response = wp_remote_post(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode((string) $s['twilio_sid']) . '/Messages.json',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($s['twilio_sid'] . ':' . $token),
                ),
                'body' => array(
                    'From' => (string) $s['twilio_from'],
                    'To'   => $e164,
                    'Body' => $body,
                ),
            )
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $message = is_array($data) && !empty($data['message']) ? (string) $data['message'] : sprintf(__('Twilio returned HTTP %d.', 'tom-moving-estimate'), $code);
        return new WP_Error('tme_twilio_http', $message);
    }

    private static function to_e164(string $raw, string $country_code): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with(trim($raw), '+')) {
            return '+' . $digits;
        }
        $cc = preg_replace('/\D+/', '', $country_code) ?: '1';
        if (strlen($digits) === 10) {
            return '+' . $cc . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        return '+' . $digits;
    }

    // --- small helpers ------------------------------------------

    private static function require_cap(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage moving estimates.', 'tom-moving-estimate'), '', array('response' => 403));
        }
    }

    private static function post_text(string $field, int $max): string
    {
        return self::clip(sanitize_text_field(wp_unslash($_POST[$field] ?? '')), $max);
    }

    private static function post_locale(): string
    {
        $value = sanitize_key(wp_unslash($_POST['client_locale'] ?? 'en'));
        return $value === 'fr' ? 'fr' : 'en';
    }

    private static function clip(string $value, int $max): string
    {
        return trim(mb_substr($value, 0, $max));
    }

    /**
     * The SMS/email body inviting the customer to the call, in their chosen
     * language. Not run through WordPress __() -- this plugin has no French
     * translation file, and this is customer-facing copy the rep didn't
     * write, not the plugin's own admin UI.
     */
    private static function link_message(string $name, string $url, string $locale = 'en'): string
    {
        if ($locale === 'fr') {
            $greeting = $name !== '' ? "Bonjour {$name}, " : '';
            return $greeting . "touchez pour commencer votre visite vidéo Tom Moving : {$url}";
        }
        $greeting = $name !== '' ? "Hi {$name}, " : '';
        return $greeting . "tap to start your Tom Moving video walkthrough: {$url}";
    }

    private static function email_subject(string $locale = 'en'): string
    {
        return $locale === 'fr' ? 'Votre visite vidéo Tom Moving' : 'Your Tom Moving video walkthrough';
    }

    private static function to_mysql(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }
}
