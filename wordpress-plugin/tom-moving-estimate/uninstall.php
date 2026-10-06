<?php
/**
 * Estimate records and encrypted settings are intentionally preserved when the
 * plugin is removed. This prevents an accidental uninstall from erasing client
 * information. Remove them manually only after making a backup.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

