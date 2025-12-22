<?php
/**
 * Uninstall script for AI Social Share
 * Cleans up all plugin data when uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('aiss_settings');

// Clean up post meta
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_aiss_%'");

// Clean up transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aiss_%' OR option_name LIKE '_transient_timeout_aiss_%'");

// Clear any scheduled events
wp_clear_scheduled_hook('aiss_cron_tick');
