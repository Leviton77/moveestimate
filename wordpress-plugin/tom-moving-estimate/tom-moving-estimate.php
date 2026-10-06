<?php
/**
 * Plugin Name: Tom Moving Estimate
 * Description: Private moving-estimate information, photo and video submissions using WordPress and Cloudflare R2.
 * Version: 1.2.0-rc20
 * Author: Tom Moving
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: tom-moving-estimate
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TME_VERSION', '1.2.0-rc20');
define('TME_FILE', __FILE__);
define('TME_DIR', plugin_dir_path(__FILE__));
define('TME_URL', plugin_dir_url(__FILE__));

require_once TME_DIR . 'includes/class-tme-secrets.php';
require_once TME_DIR . 'includes/class-tme-db.php';
require_once TME_DIR . 'includes/class-tme-ai-report.php';
$tme_ai_export_file = TME_DIR . 'includes/class-tme-ai-export.php';
if (is_readable($tme_ai_export_file)) {
    require_once $tme_ai_export_file;
}
unset($tme_ai_export_file);
require_once TME_DIR . 'includes/class-tme-lead-report.php';
require_once TME_DIR . 'includes/class-tme-r2.php';
require_once TME_DIR . 'includes/class-tme-retention.php';
require_once TME_DIR . 'includes/class-tme-live-call.php';
require_once TME_DIR . 'includes/class-tme-public.php';
require_once TME_DIR . 'includes/class-tme-admin.php';
require_once TME_DIR . 'includes/class-tme-updater.php';

final class TME_Plugin
{
    public const SETTINGS_OPTION = 'tme_settings';

    public static function init(): void
    {
        self::maybe_upgrade();
        TME_Public::init();
        TME_Retention::init();
        TME_Live_Call::init();
        TME_Updater::init();

        if (is_admin()) {
            TME_Admin::init();
        }
    }

    public static function defaults(): array
    {
        return array(
            'account_id'       => '6eb2850f957754c71f18c8e9b915cb3c',
            'bucket_name'      => self::is_staging() ? 'tom-moving-estimate-staging' : 'tom-moving-estimate-production',
            'access_key_enc'   => '',
            'secret_key_enc'   => '',
            'retention_days'   => 30,
            'warning_days'     => 3,
            'notification_email' => 'tom@tommoving.ca',
            'max_video_mb'     => 350,
            'max_photo_mb'     => 15,
            'max_photos'       => 50,
        );
    }

    private static function maybe_upgrade(): void
    {
        $installed_version = (string) get_option('tme_db_version', '');
        if ($installed_version !== TME_VERSION) {
            TME_DB::install();
            self::maybe_upgrade_photo_limit($installed_version);
        }
    }

    private static function maybe_upgrade_photo_limit(string $installed_version): void
    {
        if ($installed_version === '' || version_compare($installed_version, TME_VERSION, '>=')) {
            return;
        }
        $settings = get_option(self::SETTINGS_OPTION, array());
        if (is_array($settings) && (int) ($settings['max_photos'] ?? 30) === 30) {
            $settings['max_photos'] = 50;
            update_option(self::SETTINGS_OPTION, $settings, false);
        }
    }

    public static function is_staging(): bool
    {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return str_contains($host, 'myftpupload.com');
    }

    public static function settings(): array
    {
        $saved = get_option(self::SETTINGS_OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    public static function credentials(): array
    {
        $settings = self::settings();
        return array(
            'account_id'  => (string) $settings['account_id'],
            'bucket_name' => (string) $settings['bucket_name'],
            'access_key'  => TME_Secrets::decrypt((string) $settings['access_key_enc']),
            'secret_key'  => TME_Secrets::decrypt((string) $settings['secret_key_enc']),
        );
    }

    public static function is_configured(): bool
    {
        $credentials = self::credentials();
        return (bool) (
            preg_match('/^[a-f0-9]{32}$/i', $credentials['account_id'])
            && preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $credentials['bucket_name'])
            && $credentials['access_key']
            && $credentials['secret_key']
        );
    }

    public static function activate(): void
    {
        TME_DB::install();
        add_role('tme_estimate_rep', 'Moving Estimate Rep', array(
            'read'                 => true,
            'tme_manage_estimates' => true,
        ));
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap('tme_manage_estimates');
        }
        TME_Retention::schedule_sweep();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('tme_retention_sweep');
        TME_Live_Call::deactivate();
    }
}

register_activation_hook(__FILE__, array('TME_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('TME_Plugin', 'deactivate'));
add_action('plugins_loaded', array('TME_Plugin', 'init'));
