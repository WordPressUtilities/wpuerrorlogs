<?php
/*
Plugin Name: WPU Error Logs
Plugin URI: https://github.com/WordPressUtilities/wpuerrorlogs
Update URI: https://github.com/WordPressUtilities/wpuerrorlogs
Description: Make sense of your log files
Version: 0.14.0
Author: Darklg
Author URI: https://github.com/Darklg
Text Domain: wpuerrorlogs
Network: true
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPUErrorLogs {
    public $settings_update;
    private $number_of_days = 10;
    private $plugin_version = '0.14.0';
    private $plugin_settings = array(
        'id' => 'wpuerrorlogs',
        'name' => 'WPU Error Logs'
    );
    private $basetoolbox;
    private $adminpages;
    private $plugin_description;
    public $max_log_bytes_read = 10485760; // 10 MB
    public $truncated_files = array();

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'init'));
        add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
    }

    public function load_translation() {
        # TRANSLATION
        $lang_dir = dirname(plugin_basename(__FILE__)) . '/lang/';
        if (strpos(__DIR__, 'mu-plugins') !== false) {
            load_muplugin_textdomain('wpuerrorlogs', $lang_dir);
        } else {
            load_plugin_textdomain('wpuerrorlogs', false, $lang_dir);
        }
        $this->plugin_description = __('Make sense of your log files', 'wpuerrorlogs');
    }

    public function init() {

        # UPDATE
        require_once __DIR__ . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpuerrorlogs\WPUBaseUpdate(
            'WordPressUtilities',
            'wpuerrorlogs',
            $this->plugin_version);

        # TOOLBOX
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpuerrorlogs\WPUBaseToolbox(array(
            'plugin_name' => $this->plugin_settings['name'],
            'need_form_js' => false
        ));

        # CUSTOM PAGE
        $admin_pages = array(
            'main' => array(
                'icon_url' => 'dashicons-sos',
                'menu_name' => $this->plugin_settings['name'],
                'name' => $this->plugin_settings['name'],
                'settings_link' => true,
                'settings_name' => __('Settings', 'wpuerrorlogs'),
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                )
            ),
            'graphs' => array(
                'parent' => 'main',
                'name' => __('Graphs', 'wpuerrorlogs'),
                'function_content' => array(&$this,
                    'page_content__graphs'
                ),
                'function_action' => array(&$this,
                    'page_action__graphs'
                )
            )
        );
        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => 'manage_options',
            'network_page' => (defined('MULTISITE') && MULTISITE),
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpuerrorlogs\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);

        # HOOKS
        $this->number_of_days = apply_filters('wpuerrorlogs__number_of_days', $this->number_of_days);
    }

    public function page_content__main() {

        /* Find debug file */
        if (!WP_DEBUG_LOG) {
            echo __('Debug logs are not enabled', 'wpuerrorlogs');
            return;
        }

        $number_of_days = $this->get_request_number_of_days();
        $search = $this->get_request_search();
        $file = $this->get_request_file();
        $errors = $this->get_logs(array(
            'number_of_days' => $number_of_days,
            'search_string' => $search,
            'file' => $file
        ));

        /* Keep only first five and extract data */
        $colnames = array(
            'count' => __('Count', 'wpuerrorlogs'),
            'date' => __('Date', 'wpuerrorlogs'),
            'type' => __('Type', 'wpuerrorlogs'),
            'text' => __('Text', 'wpuerrorlogs')
        );

        $this->display_truncated_notice();
        $this->display_filter_form($number_of_days, $search, $file);

        /* Top errors */
        $top_errors = $this->sort_errors_by_top($errors, 10);
        echo '<h2>' . esc_html__('Top errors', 'wpuerrorlogs') . '</h2>';
        $html_errors = $this->basetoolbox->array_to_html_table($top_errors, array(
            'table_classname' => 'widefat striped',
            'htmlspecialchars_td' => false,
            'colnames' => $colnames
        ));
        echo $html_errors ? $html_errors : wpautop(__('No errors at the moment.', 'wpuerrorlogs'));

        /* Latest errors */
        $latest_errors = $this->sort_errors_by_latest($errors, 10);
        echo '<h2>' . esc_html__('Latest errors', 'wpuerrorlogs') . '</h2>';
        $html_errors = $this->basetoolbox->array_to_html_table($latest_errors, array(
            'table_classname' => 'widefat striped',
            'htmlspecialchars_td' => false,
            'colnames' => $colnames
        ));
        echo $html_errors ? $html_errors : wpautop(__('No errors at the moment.', 'wpuerrorlogs'));

        /* Latest by type */
        $fatal_errors = array_filter($errors, function ($item) {
            return isset($item['type']) && $item['type'] == 'php-fatal';
        });
        $latest_fatal_errors = $this->sort_errors_by_latest($fatal_errors, 10);
        $html_errors = $this->basetoolbox->array_to_html_table($latest_fatal_errors, array(
            'table_classname' => 'widefat striped',
            'htmlspecialchars_td' => false,
            'colnames' => $colnames
        ));
        if ($html_errors) {
            echo '<h2>' . esc_html__('Latest fatal errors', 'wpuerrorlogs') . '</h2>';
            echo $html_errors;
        }

        /* Debug info */
        $debug_dir = dirname(WP_DEBUG_LOG);
        $current_file = $file ? $debug_dir . '/' . $file : WP_DEBUG_LOG;
        if (is_readable($current_file)) {
            echo '<h2>' . esc_html__('Info', 'wpuerrorlogs') . '</h2>';
            echo '<ul>';
            $file_label = $file
                ? __('Selected log file: %s', 'wpuerrorlogs')
                : __('Current debug log file: %s', 'wpuerrorlogs');
            echo '<li>' . sprintf($file_label, '<code>' . str_replace(ABSPATH, '', $current_file) . '</code>') . '</li>';
            echo '<li>' . sprintf(__('File size: %s', 'wpuerrorlogs'), '<code>' . size_format(filesize($current_file)) . '</code>') . '</li>';
            echo '<li>' . sprintf(__('Last modified: %s', 'wpuerrorlogs'), '<code>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($current_file)) . '</code>') . '</li>';
            echo '</ul>';

            $files = array_filter($this->get_available_log_files(), function ($a) use ($current_file) {
                return $a != $current_file;
            });
            if (!empty($files)) {
                $base_url = $this->adminpages->get_page_url('main');
                $html_list = '';
                $total_size = 0;
                foreach ($files as $previous_file) {
                    $size = filesize($previous_file);
                    $total_size += $size;
                    $file_url = add_query_arg('file', urlencode(basename($previous_file)), $base_url);
                    $html_list .= '<li><a href="' . esc_url($file_url) . '"><code>' . str_replace(ABSPATH, '', $previous_file) . '</code></a> (' . size_format($size) . ')</li>';
                }
                echo '<details>';
                echo '<summary>' . __('Previous log files', 'wpuerrorlogs') . '</summary>';
                echo '<p>' . sprintf(__('Total size of previous log files: %s', 'wpuerrorlogs'), '<code>' . size_format($total_size) . '</code>') . '</p>';
                echo '<ul>' . $html_list . '</ul>';
                echo '</details>';
            }
        }
    }

    public function admin_enqueue_scripts() {
        if (!isset($_GET['page']) || strpos($_GET['page'], $this->plugin_settings['id'] . '-') !== 0) {
            return;
        }
        wp_enqueue_script('wpuerrorlogs_charts', plugins_url('assets/chart.js', __FILE__), array('jquery'), $this->plugin_version);
        wp_enqueue_script('wpuerrorlogs_admin', plugins_url('assets/admin.js', __FILE__), array('wpuerrorlogs_charts'), $this->plugin_version);
    }

    public function page_action__main() {
        $this->page_action_filter_redirect('main');
    }

    public function page_action__graphs() {
        $this->page_action_filter_redirect('graphs');
    }

    public function page_action_filter_redirect($page_id) {
        $new_url = $this->adminpages->get_page_url($page_id);
        if (isset($_POST['number_of_days'])) {
            $number_of_days = intval($_POST['number_of_days']);
            $new_url = add_query_arg('number_of_days', urlencode($number_of_days), $new_url);
        }
        if (isset($_POST['wpuerrorlogs_file']) && $_POST['wpuerrorlogs_file'] !== '') {
            $file = basename(sanitize_text_field(wp_unslash($_POST['wpuerrorlogs_file'])));
            $new_url = add_query_arg('file', urlencode($file), $new_url);
        }
        if (isset($_POST['wpuerrorlogs_search'])) {
            $search = sanitize_text_field(wp_unslash($_POST['wpuerrorlogs_search']));
            $new_url = add_query_arg('s', urlencode($search), $new_url);
        }
        if (isset($_POST['wpuerrorlogs_search_action'])) {
            $new_url = add_query_arg('has_action', 1, $new_url);
        }

        wp_redirect($new_url);
        die;
    }

    /* ----------------------------------------------------------
      Filters
    ---------------------------------------------------------- */

    public function get_request_number_of_days() {
        $number_of_days = $this->number_of_days;
        if (isset($_GET['number_of_days']) && is_numeric($_GET['number_of_days']) && $_GET['number_of_days'] <= $this->number_of_days) {
            $number_of_days = intval($_GET['number_of_days']);
        }
        return $number_of_days;
    }

    public function get_request_search() {
        if (!isset($_GET['s'])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_GET['s']));
    }

    public function get_available_log_files() {
        $debug_dir = dirname(WP_DEBUG_LOG);
        if (!is_dir($debug_dir)) {
            return array();
        }
        $files = glob($debug_dir . '/*.log');
        if (!$files) {
            return array();
        }
        /* Most recent first */
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        return $files;
    }

    public function get_request_file() {
        if (!isset($_GET['file']) || $_GET['file'] === '') {
            return '';
        }
        $requested = basename(sanitize_text_field(wp_unslash($_GET['file'])));
        /* Whitelist: only accept a basename that matches an existing log file */
        $available = array_map('basename', $this->get_available_log_files());
        return in_array($requested, $available, true) ? $requested : '';
    }

    public function display_truncated_notice() {
        if (empty($this->truncated_files)) {
            return;
        }
        foreach ($this->truncated_files as $file => $size) {
            echo '<div class="notice notice-warning"><p>';
            echo sprintf(
                /* translators: 1: file name, 2: human-readable size, 3: human-readable read chunk size */
                esc_html__('The log file %1$s is large (%2$s). Only the most recent errors (last %3$s) are displayed.', 'wpuerrorlogs'),
                '<code>' . esc_html(basename($file)) . '</code>',
                esc_html(size_format($size)),
                esc_html(size_format($this->max_log_bytes_read))
            );
            echo '</p></div>';
        }
    }

    public function display_filter_form($number_of_days, $search, $file = '') {
        $has_search = $search || $file || isset($_GET['has_action']);
        echo '<details ' . ($has_search ? 'open' : '') . '>';
        echo '<summary>' . __('Filter results', 'wpuerrorlogs') . '</summary>';

        /* Select log file */
        $log_files = $this->get_available_log_files();
        if (!empty($log_files)) {
            echo '<p>';
            echo '<label for="wpuerrorlogs_switch_file">' . __('Log file :', 'wpuerrorlogs') . '</label><br />';
            echo '<select name="wpuerrorlogs_file" id="wpuerrorlogs_switch_file">';
            echo '<option value="">' . esc_html__('All files', 'wpuerrorlogs') . '</option>';
            foreach ($log_files as $log_file) {
                $log_basename = basename($log_file);
                $label = $log_basename . ' (' . size_format(filesize($log_file)) . ')';
                echo '<option value="' . esc_attr($log_basename) . '"' . ($file === $log_basename ? ' selected' : '') . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }

        /* Select number of days (irrelevant when a specific file is selected) */
        if (!$file) {
            echo '<p>';
            echo '<label for="wpuerrorlogs_switch_day">' . __('Check the last :', 'wpuerrorlogs') . '</label><br />';
            echo '<select name="number_of_days" id="wpuerrorlogs_switch_day">';
            for ($i = $this->number_of_days; $i > 0; $i--) {
                echo '<option value="' . $i . '"' . ($number_of_days == $i ? ' selected' : '') . '>' . ($i < 2 ? __('1 day', 'wpuerrorlogs') : sprintf(__('%s days', 'wpuerrorlogs'), $i)) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }
        echo '<input type="hidden" name="wpuerrorlogs_search_action" value="1">';

        /* Search bar */
        echo '<p>';
        echo '<label>' . __('Search in errors', 'wpuerrorlogs') . '</label><br />';
        echo '<input name="wpuerrorlogs_search" type="search" placeholder="' . esc_attr__('Search', 'wpuerrorlogs') . '" value="' . esc_attr(htmlentities($search)) . '" id="wpuerrorlogs_search" />';
        echo '</p>';

        echo '<p>';
        submit_button(__('Filter results', 'wpuerrorlogs'), 'primary', 'wpuerrorlogs_search_action', false);
        if ($has_search) {
            echo ' ';
            echo '<a href="' . esc_url($this->adminpages->get_page_url('main')) . '" class="button button-secondary">' . __('Reset filters', 'wpuerrorlogs') . '</a>';
        }
        echo '</p>';

        echo '</details>';
    }

    public function page_content__graphs() {

        /* Find debug file */
        if (!WP_DEBUG_LOG) {
            echo __('Debug logs are not enabled', 'wpuerrorlogs');
            return;
        }

        $number_of_days = $this->get_request_number_of_days();
        $search = $this->get_request_search();
        $file = $this->get_request_file();
        $errors = $this->get_logs(array(
            'number_of_days' => $number_of_days,
            'search_string' => $search,
            'file' => $file
        ));

        $this->display_truncated_notice();
        $this->display_filter_form($number_of_days, $search, $file);

        /* Day range: all-files = since today; specific file = actual file dates (capped) */
        if ($file) {
            $bounds = $this->get_errors_date_bounds($errors);
            $day_end = $bounds['max'];
            $day_start = max($bounds['min'], $day_end - 86400 * 60); // ponytail: cap 60 days, raise if a real need
        } else {
            $day_end = time();
            $day_start = time() - 86400 * ($number_of_days - 1);
        }

        $errors_by_day = $this->sort_errors_by_day($errors, $day_start, $day_end);
        if (count($errors_by_day) > 1) {
            echo '<h2>' . esc_html__('Errors by day', 'wpuerrorlogs') . '</h2>';
            echo '<script>var wpuerrorlogs_errors_by_day = ' . json_encode($errors_by_day) . ';</script>';
            echo '<canvas id="wpuerrorlogs_errors_by_day" style="width:100%;height:300px;"></canvas>';
        }

        $errors_by_hour = $this->sort_errors_by_hour($errors);
        if ($errors_by_hour) {
            echo '<h2>' . esc_html__('Errors by hour', 'wpuerrorlogs') . '</h2>';
            echo '<script>var wpuerrorlogs_errors_by_hour = ' . json_encode($errors_by_hour) . ';</script>';
            echo '<canvas id="wpuerrorlogs_errors_by_hour" style="width:100%;height:300px;"></canvas>';
        }

        $number_of_days_for_day_hour = $file ? 3 : min($number_of_days, 3);
        $errors_by_day_hour = $this->sort_errors_by_day_hour($errors, $day_end, $number_of_days_for_day_hour);
        if ($errors_by_day_hour) {
            echo '<h2>' . sprintf(__('Errors hour by hour (last %d days)', 'wpuerrorlogs'), $number_of_days_for_day_hour) . '</h2>';
            echo '<script>var wpuerrorlogs_errors_by_day_hour = ' . json_encode($errors_by_day_hour) . ';</script>';
            echo '<canvas id="wpuerrorlogs_errors_by_day_hour" style="width:100%;height:300px;"></canvas>';
        }
    }

    /* ----------------------------------------------------------
      Sort errors
    ---------------------------------------------------------- */

    public function sort_errors_by_top($errors, $max_number = 5) {
        $top_errors_raw = [];
        foreach ($errors as $error) {
            if (!isset($top_errors_raw[$error['text']])) {
                $top_errors_raw[$error['text']] = 0;
            }
            $top_errors_raw[$error['text']]++;
        }
        arsort($top_errors_raw);

        $top_errors_raw = array_slice($top_errors_raw, 0, $max_number, true);

        $top_errors = array();
        foreach ($top_errors_raw as $text => $count) {
            $top_errors[] = array(
                'count' => $count,
                'text' => $this->expand_error_text($text)
            );
        }
        $top_errors = $this->prepare_errors_for_display($top_errors);
        return $top_errors;
    }

    public function sort_errors_by_latest($errors, $max_number = 5) {
        $latest_errors = array_slice($errors, 0, $max_number, true);
        foreach ($latest_errors as $i => $error) {
            $latest_errors[$i]['text'] = $this->expand_error_text($error['text']);
        }
        /* Reset keys */
        $latest_errors = array_values($latest_errors);
        $latest_errors = $this->prepare_errors_for_display($latest_errors);
        return $latest_errors;
    }

    public function prepare_errors_for_display($errors) {
        /* Prepare for display */
        $errors = array_map(function ($item) {
            $item['text'] = $this->display_content_with_toggle($item['text']);
            return $item;
        }, $errors);
        return $errors;
    }

    public function sort_errors_by_hour($errors) {
        if (!$errors) {
            return array();
        }
        $errors_by_hour = array();
        for ($i = 0; $i < 24; $i++) {
            $errors_by_hour[$i] = 0;
        }
        foreach ($errors as $error) {
            $hour = date('H', strtotime($error['date']));
            $hour = intval($hour, 10);
            if (!isset($errors_by_hour[$hour])) {
                $errors_by_hour[$hour] = 0;
            }
            $errors_by_hour[$hour]++;
        }
        ksort($errors_by_hour, SORT_NATURAL);
        return $errors_by_hour;
    }

    public function get_errors_date_bounds($errors) {
        $times = array();
        foreach ($errors as $error) {
            $t = strtotime($error['date']);
            if ($t) {
                $times[] = $t;
            }
        }
        if (empty($times)) {
            return array('min' => time(), 'max' => time());
        }
        return array('min' => min($times), 'max' => max($times));
    }

    public function sort_errors_by_day($errors, $start_ts, $end_ts) {
        if (!$errors) {
            return array();
        }
        $errors_by_day = array();
        $day_ts = strtotime(date('Y-m-d', $start_ts));
        $end_day = strtotime(date('Y-m-d', $end_ts));
        while ($day_ts <= $end_day) {
            $errors_by_day[date('Y-m-d', $day_ts)] = 0;
            $day_ts = strtotime('+1 day', $day_ts); // DST-safe stepping
        }
        foreach ($errors as $error) {
            $day = date('Y-m-d', strtotime($error['date']));
            if (!isset($errors_by_day[$day])) {
                $errors_by_day[$day] = 0;
            }
            $errors_by_day[$day]++;
        }
        ksort($errors_by_day, SORT_NATURAL);
        return $errors_by_day;
    }

    public function sort_errors_by_day_hour($errors, $end_ts, $number_of_days = 3) {
        if (!$errors) {
            return array();
        }
        $errors_by_day_hour = array();
        $start = strtotime(date('Y-m-d 00:00:00', $end_ts - 86400 * ($number_of_days - 1)));
        for ($i = 0; $i < $number_of_days * 24; $i++) {
            $key = date('Y-m-d H\h', $start + $i * 3600);
            $errors_by_day_hour[$key] = 0;
        }
        foreach ($errors as $error) {
            $key = date('Y-m-d H\h', strtotime($error['date']));
            if (!isset($errors_by_day_hour[$key])) {
                continue;
            }
            $errors_by_day_hour[$key]++;
        }
        return $errors_by_day_hour;
    }

    /* ----------------------------------------------------------
      Extract logs from file
    ---------------------------------------------------------- */

    public function get_logs($args = array()) {
        $args = wp_parse_args($args, array(
            'number_of_days' => 5,
            'search_string' => '',
            'file' => ''
        ));

        $excluded_strings = array();
        $included_strings = array();

        $args['search_string'] = str_replace('-"', '"-', $args['search_string']);
        $search_parts = explode('"', $args['search_string']);
        $search_parts = array_map(function ($a) {
            return str_replace('"', '', $a);
        }, $search_parts);
        if ($args['search_string'] && !empty($search_parts)) {
            $search_parts = array_filter($search_parts);
            foreach ($search_parts as $search_part) {
                if (substr($search_part, 0, 1) == '-') {
                    $excluded_strings[] = trim(substr($search_part, 1));
                } else {
                    $included_strings[] = trim($search_part);
                }
            }
        }

        $previous_files = array();
        $debug_dir = dirname(WP_DEBUG_LOG);

        /* Specific file selected: analyze only this file, no day bound, no aggregation */
        if ($args['file']) {
            $file = $debug_dir . '/' . $args['file'];
            if (!is_readable($file)) {
                return array();
            }
            return $this->get_logs_from_file($file, array(
                'max_date' => 0,
                'included_strings' => $included_strings,
                'excluded_strings' => $excluded_strings
            ));
        }

        /* Try to obtain previous files */
        $file = ABSPATH . '/wp-content/debug.log';
        if (is_dir($debug_dir)) {
            if (is_readable(WP_DEBUG_LOG)) {
                $file = WP_DEBUG_LOG;
            } else {
                /* Find most recent file */
                $previous_files = glob($debug_dir . '/*.log');
                arsort($previous_files);
                if (isset($previous_files[0])) {
                    $file = array_shift($previous_files);
                    $previous_files = array_slice($previous_files, 0, $args['number_of_days']);
                }
            }
        }
        if (!is_readable($file)) {
            return array();
        }

        $max_date = time() - 86400 * $args['number_of_days'];

        /* Parse errors in files */
        $errors = $this->get_logs_from_file($file, array(
            'max_date' => $max_date,
            'included_strings' => $included_strings,
            'excluded_strings' => $excluded_strings
        ));
        if (empty($previous_files)) {
            $previous_files = $this->find_previous_log_files($file, $args['number_of_days']);
        }

        foreach ($previous_files as $previous_file) {
            $errors_previous = $this->get_logs_from_file($previous_file, array(
                'max_date' => $max_date,
                'included_strings' => $included_strings,
                'excluded_strings' => $excluded_strings
            ));
            foreach ($errors_previous as $error) {
                $errors[] = $error;
            }
        }
        return $errors;
    }

    public function find_previous_log_files($file, $number_of_days = 5) {
        $date_formats = array('Ymd', 'dmY');
        $previous_files = array();
        foreach ($date_formats as $date_format) {
            $now_date = date($date_format);
            if (strpos($file, $now_date) === false) {
                continue;
            }
            for ($i = 1; $i <= $number_of_days; $i++) {
                $previous_date = date($date_format, time() - 86400 * $i);
                $previous_file = str_replace($now_date, $previous_date, $file);
                if (is_readable($previous_file)) {
                    $previous_files[] = $previous_file;
                }
            }
        }

        return $previous_files;
    }

    public function get_logs_from_file($file, $args = array()) {
        if (!is_readable($file)) {
            return array();
        }

        $args = wp_parse_args($args, array(
            'max_date' => time(),
            'included_strings' => array(),
            'excluded_strings' => array()
        ));

        $filesize = filesize($file);
        if ($filesize > $this->max_log_bytes_read) {
            /* Read only the tail to bound memory: seek near EOF, drop the first (partial) line */
            $this->truncated_files[$file] = $filesize;
            $fp = fopen($file, 'r');
            fseek($fp, $filesize - $this->max_log_bytes_read);
            $content = fread($fp, $this->max_log_bytes_read);
            fclose($fp);
            $lines = explode("\n", $content);
            array_shift($lines); // drop the cut-off partial line
            $lines = array_reverse(array_filter($lines, 'strlen'));
        } else {
            $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }
        $errors = [];
        $default_error = array(
            'date' => 'none',
            'type' => 'none',
            'text' => array()
        );
        $currentError = $default_error;

        foreach ($lines as $line) {

            $is_error_start = substr($line, 0, 1) == '[' && preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [A-Za-z\/]+\]/', $line, $matches);

            /* Is it a new error */
            if ($is_error_start) {

                $line_error = $this->get_error_from_line($line);

                /* If date is not ok */
                if (isset($matches[0])) {
                    $time = strtotime(str_replace(array('[', ']'), '', $matches[0]));
                    if ($time < $args['max_date']) {
                        break;
                    }
                }

                if ($currentError['date'] == 'none' && !empty($currentError['text'])) {
                    $line_error['text'] = array_merge($currentError['text'], $line_error['text']);
                    $line_error['text'] = $this->minimize_error_text(implode("\n", array_reverse($line_error['text'])));
                    $line_error = $this->filter_visible_error($line_error, $args);
                    if ($line_error) {
                        $errors[] = $line_error;
                    }
                    $currentError = $default_error;
                    continue;
                }

                $line_error['text'] = implode('', $line_error['text']);
                $line_error = $this->filter_visible_error($line_error, $args);
                if ($line_error) {
                    $errors[] = $line_error;
                }

            } else {
                $currentError['text'][] = $line;
            }
        }

        if (!empty($currentError) && is_array($currentError['text']) && !empty($currentError['text'])) {
            $currentError['text'] = $this->minimize_error_text(implode("\n", array_reverse($currentError['text'])));
            $currentError = $this->filter_visible_error($currentError, $args);
            if ($currentError) {
                $errors[] = $currentError;
            }
        }

        return $errors;
    }

    public function filter_visible_error($error, $args) {
        if (!empty($args['included_strings'])) {
            foreach ($args['included_strings'] as $included_string) {
                if (strpos($error['text'], $included_string) === false) {
                    return false;
                }
            }
        }
        if (!empty($args['excluded_strings'])) {
            foreach ($args['excluded_strings'] as $excluded_string) {
                if (strpos($error['text'], $excluded_string) !== false) {
                    return false;
                }
            }
        }

        return $error;
    }

    public function get_error_from_line($line) {
        /* Extract values */
        $date_parts = explode(']', $line);
        $date = str_replace('[', '', $date_parts[0]);
        $text = trim(substr($line, strlen('[' . $date . ']')));

        /* Extract type */
        $type = 'none';
        $text_parts_type = explode(':', $text);
        switch ($text_parts_type[0]) {
        case 'PHP Warning':
            $type = 'php-warning';
            break;
        case 'PHP Parse error':
            $type = 'php-parse';
            break;
        case 'PHP Deprecated':
            $type = 'php-deprecated';
            break;
        case 'PHP Fatal error':
            $type = 'php-fatal';
            break;
        default:
        }

        /* Return value */
        return array(
            'date' => $date,
            'type' => $type,
            'text' => array($text)
        );
    }

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    /* Display content
    -------------------------- */

    public function display_content_with_toggle($content) {
        $content = wp_strip_all_tags($content);
        $content_parts = explode("\n", $content);
        $minimized_error = str_replace(ABSPATH, '', $content_parts[0]);
        if ($minimized_error == $content) {
            return $content;
        }
        $content = $minimized_error;
        $content .= '<details><summary>' . __('Full error', 'wpuerrorlogs') . '</summary><pre style="overflow:auto;max-width:100%;">' . implode("\n", $content_parts) . '</pre></details>';
        return $content;
    }

    /* Minimize text
    -------------------------- */

    public function minimize_get_correspondances() {
        return array(
            'abs' => ABSPATH,
            'plug' => 'wp-content/plugins/'
        );
    }

    public function expand_error_text($text) {
        $correspondances = $this->minimize_get_correspondances();
        foreach ($correspondances as $min => $max) {
            $text = str_replace('#!' . $min . '!#', $max, $text);
        }
        return $text;
    }

    public function minimize_error_text($text) {
        $correspondances = $this->minimize_get_correspondances();
        foreach ($correspondances as $min => $max) {
            $text = str_replace($max, '#!' . $min . '!#', $text);
        }
        return $text;
    }
}

$WPUErrorLogs = new WPUErrorLogs();
