<?php
/*
Plugin Name: WPU Error Logs
Plugin URI: https://github.com/WordPressUtilities/wpuerrorlogs
Update URI: https://github.com/WordPressUtilities/wpuerrorlogs
Description: Make sense of your log files
Version: 0.9.1
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
    private $plugin_version = '0.9.1';
    private $plugin_settings = array(
        'id' => 'wpuerrorlogs',
        'name' => 'WPU Error Logs'
    );
    private $basetoolbox;
    private $adminpages;
    private $plugin_description;

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'init'));
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
            echo 'Debug logs are not enabled';
            return;
        }

        $number_of_days = $this->number_of_days;
        if (isset($_GET['number_of_days']) && is_numeric($_GET['number_of_days']) && $_GET['number_of_days'] <= $this->number_of_days) {
            $number_of_days = intval($_GET['number_of_days']);
        }

        $search = '';
        if (isset($_GET['s'])) {
            $search = stripslashes(stripslashes($_GET['s']));
        }

        $errors = $this->get_logs(array(
            'number_of_days' => $number_of_days,
            'search_string' => $search
        ));

        /* Keep only first five and extract data */
        $colnames = array(
            'count' => __('Count', 'wpuerrorlogs'),
            'date' => __('Date', 'wpuerrorlogs'),
            'type' => __('Type', 'wpuerrorlogs'),
            'text' => __('Text', 'wpuerrorlogs')
        );

        echo '<details ' . ($search || isset($_GET['has_action']) ? 'open' : '') . '>';
        echo '<summary>' . __('Filter results', 'wpuerrorlogs') . '</summary>';

        /* Select number of days */
        echo '<p>';
        echo '<label for="wpuerrorlogs_switch_day">' . __('Check the last :', 'wpuerrorlogs') . '</label><br />';
        echo '<select name="number_of_days" id="wpuerrorlogs_switch_day">';
        for ($i = $this->number_of_days; $i > 0; $i--) {
            echo '<option value="' . $i . '"' . ($number_of_days == $i ? ' selected' : '') . '>' . ($i < 2 ? __('1 day', 'wpuerrorlogs') : sprintf(__('%s days', 'wpuerrorlogs'), $i)) . '</option>';
        }
        echo '</select>';
        echo '<input type="hidden" name="wpuerrorlogs_search_action" value="1">';
        echo '</p>';

        /* Search bar */
        echo '<p>';
        echo '<label>' . __('Search in errors', 'wpuerrorlogs') . '</label><br />';
        echo '<input name="wpuerrorlogs_search" type="search" placeholder="' . __('Search', 'wpuerrorlogs') . '" value="' . htmlentities($search) . '" id="wpuerrorlogs_search" />';
        echo '</p>';

        submit_button(__('Filter results', 'wpuerrorlogs'));

        echo '</details>';

        /* Top errors */
        $top_errors = $this->sort_errors_by_top($errors, 10);
        echo '<h2>' . __('Top errors', 'wpuerrorlogs') . '</h2>';
        $html_errors = $this->basetoolbox->array_to_html_table($top_errors, array(
            'table_classname' => 'widefat striped',
            'htmlspecialchars_td' => false,
            'colnames' => $colnames
        ));
        echo $html_errors ? $html_errors : '<p>' . __('No errors at the moment.', 'wpuerrorlogs') . '</p>';

        /* Latest errors */
        $latest_errors = $this->sort_errors_by_latest($errors, 10);
        echo '<h2>' . __('Latest errors', 'wpuerrorlogs') . '</h2>';
        $html_errors = $this->basetoolbox->array_to_html_table($latest_errors, array(
            'table_classname' => 'widefat striped',
            'htmlspecialchars_td' => false,
            'colnames' => $colnames
        ));
        echo $html_errors ? $html_errors : '<p>' . __('No errors at the moment.', 'wpuerrorlogs') . '</p>';

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
            echo '<h2>' . __('Latest fatal errors', 'wpuerrorlogs') . '</h2>';
            echo $html_errors;
        }

    }

    public function page_action__main() {
        $new_url = $this->adminpages->get_page_url('main');
        if (isset($_POST['number_of_days'])) {
            $new_url = add_query_arg('number_of_days', urlencode($_POST['number_of_days']), $new_url);
        }
        if (isset($_POST['wpuerrorlogs_search'])) {
            $new_url = add_query_arg('s', urlencode($_POST['wpuerrorlogs_search']), $new_url);
        }
        if (isset($_POST['wpuerrorlogs_search_action'])) {
            $new_url = add_query_arg('has_action', 1, $new_url);
        }

        wp_redirect($new_url);
        die;
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

    /* ----------------------------------------------------------
      Extract logs from file
    ---------------------------------------------------------- */

    public function get_logs($args = array()) {
        $args = wp_parse_args($args, array(
            'number_of_days' => 5,
            'search_string' => ''
        ));

        $excluded_strings = array();
        $included_strings = array();

        $args['search_string'] = str_replace('-"', '"-', $args['search_string']);
        $search_parts = explode('"', $args['search_string']);
        $search_parts = array_map(function ($a) {
            return str_replace('"', '', $a);
        }, $search_parts);
        if (!empty($search_parts)) {
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

        /* Try to obtain previous files */
        $file = ABSPATH . '/wp-content/debug.log';
        $debug_dir = dirname(WP_DEBUG_LOG);
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

        $args = wp_parse_args($args, array(
            'max_date' => time(),
            'included_strings' => array(),
            'excluded_strings' => array()
        ));

        $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
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
        $content = strip_tags($content);
        $content_parts = explode("\n", $content);
        $minimized_error = str_replace(ABSPATH, '', $content_parts[0]);
        if ($minimized_error == $content) {
            return $content;
        }
        $content = $minimized_error;
        $content .= '<details><summary>' . __('Full error', 'wpuerrorlogs') . '</summary><pre style="overflow:auto;white-space: normal;">' . implode("\n", $content_parts) . '</pre></details>';
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
