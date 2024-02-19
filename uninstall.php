<?php
defined('ABSPATH') || die;
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/* Delete options */
$options = array(
    'wpuerrorlogs_options',
    'wpuerrorlogs__cron_hook_croninterval',
    'wpuerrorlogs_wpuerrorlogs_version'
);
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}

/* Delete tables */
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpuerrorlogs_logs");
