<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-update from GitHub. Reads wordpress-plugin/update.json on the repo's
 * main branch, which names the current "stable" and "testing" builds, and
 * feeds the matching one into WordPress's normal plugin-update check -- so a
 * new release shows as "Update available" (and installs itself when
 * auto-updates are on for this plugin).
 *
 * Staging follows "testing", production follows "stable": a new build is
 * published as testing first, and only promoted to stable once it has been
 * checked on staging.
 */
final class TME_Updater
{
    private const MANIFEST_URL   = 'https://raw.githubusercontent.com/Leviton77/moveestimate/main/wordpress-plugin/update.json';
    private const PACKAGE_PREFIX = 'https://github.com/Leviton77/moveestimate/releases/download/';
    private const CACHE_KEY      = 'tme_update_manifest';

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array(__CLASS__, 'fix_source_dir'), 10, 4);
        // Dashboard → Updates → "Check again" should see a just-published build.
        add_action('load-update-core.php', array(__CLASS__, 'maybe_flush'));
    }

    private static function basename(): string
    {
        return plugin_basename(TME_FILE);
    }

    private static function slug(): string
    {
        return dirname(self::basename());
    }

    public static function maybe_flush(): void
    {
        if (isset($_GET['force-check'])) {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    /**
     * @return array decoded update.json, or array() when unavailable
     */
    private static function manifest(): array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get(self::MANIFEST_URL, array('timeout' => 10));
        $data = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
        }
        if (!is_array($data)) {
            // Don't hammer GitHub while it's unreachable; try again in an hour.
            set_site_transient(self::CACHE_KEY, array(), HOUR_IN_SECONDS);
            return array();
        }
        set_site_transient(self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * The build this site should run: "testing" on staging when it's at least
     * as new as "stable", otherwise "stable". Null when the manifest has no
     * usable entry (missing version, or a package outside this repo's
     * releases).
     */
    private static function channel_entry(array $manifest, bool $testing): ?array
    {
        $valid = static function ($entry): ?array {
            if (!is_array($entry)) {
                return null;
            }
            $version = (string) ($entry['version'] ?? '');
            $package = (string) ($entry['package'] ?? '');
            if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)
                || !str_starts_with($package, self::PACKAGE_PREFIX)
                || !str_ends_with($package, '.zip')) {
                return null;
            }
            return array(
                'version' => $version,
                'package' => $package,
                'notes'   => (string) ($entry['notes'] ?? ''),
            );
        };
        $stable = $valid($manifest['stable'] ?? null);
        $test   = $testing ? $valid($manifest['testing'] ?? null) : null;
        if ($test && (!$stable || version_compare($test['version'], $stable['version'], '>='))) {
            return $test;
        }
        return $stable;
    }

    private static function wanted(): ?array
    {
        return self::channel_entry(self::manifest(), TME_Plugin::is_staging());
    }

    /**
     * @param mixed $transient the update_plugins site transient
     * @return mixed
     */
    public static function inject_update($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }
        $entry = self::wanted();
        if (!$entry) {
            return $transient;
        }
        $item = (object) array(
            'id'          => self::basename(),
            'slug'        => self::slug(),
            'plugin'      => self::basename(),
            'new_version' => $entry['version'],
            'package'     => $entry['package'],
            'url'         => 'https://github.com/Leviton77/moveestimate',
        );
        // Listing it under no_update when current is what lets WordPress show
        // the "Enable auto-updates" toggle for this plugin.
        if (version_compare($entry['version'], TME_VERSION, '>')) {
            $transient->response[self::basename()] = $item;
            unset($transient->no_update[self::basename()]);
        } else {
            $item->new_version = TME_VERSION;
            $transient->no_update[self::basename()] = $item;
            unset($transient->response[self::basename()]);
        }
        return $transient;
    }

    /**
     * "View details" popup on the Plugins / Updates screens.
     *
     * @param false|object|array $result
     * @return false|object|array
     */
    public static function plugin_info($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::slug()) {
            return $result;
        }
        $entry = self::wanted();
        if (!$entry) {
            return $result;
        }
        return (object) array(
            'name'          => 'Tom Moving Estimate',
            'slug'          => self::slug(),
            'version'       => $entry['version'],
            'author'        => 'Tom Moving',
            'requires'      => '6.5',
            'requires_php'  => '8.1',
            'download_link' => $entry['package'],
            'sections'      => array(
                'changelog' => $entry['notes'] !== '' ? wpautop(esc_html($entry['notes'])) : esc_html__('See the plugin readme.', 'tom-moving-estimate'),
            ),
        );
    }

    /**
     * The release zip's root folder is "tom-moving-estimate/". If this site
     * has the plugin in a differently named folder (e.g. a manual upload that
     * became "tom-moving-estimate-1"), install over that folder instead of
     * leaving a second copy beside it.
     *
     * @param string|WP_Error $source
     * @return string|WP_Error
     */
    public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array())
    {
        if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== self::basename()) {
            return $source;
        }
        $want = trailingslashit($remote_source) . self::slug() . '/';
        if (untrailingslashit($source) === untrailingslashit($want)) {
            return $source;
        }
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move(untrailingslashit($source), untrailingslashit($want), true)) {
            return $want;
        }
        return new WP_Error('tme_update_rename', __('Could not prepare the Tom Moving Estimate update folder.', 'tom-moving-estimate'));
    }
}
