<?php
/*
Plugin Name: WPU Error Logs
Plugin URI: https://github.com/WordPressUtilities/wpuerrorlogs
Update URI: https://github.com/WordPressUtilities/wpuerrorlogs
Description: Make sense of your log files
Version: 0.7.0
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
    private $plugin_version = '0.7.0';
    private $plugin_settings = array(
        'id' => 'wpuerrorlogs',
        'name' => 'WPU Error Logs'
    );
    private $basetoolbox;
    private $adminpages;
    private $plugin_description;

    public function __construct() {
        add_action('plugins_loaded', array(&$this, 'plugins_loaded'));
    }

    public function plugins_loaded() {
        # TRANSLATION
        if (!load_plugin_textdomain('wpuerrorlogs', false, dirname(plugin_basename(__FILE__)) . '/lang/')) {
            load_muplugin_textdomain('wpuerrorlogs', dirname(plugin_basename(__FILE__)) . '/lang/');
        }
        $this->plugin_description = __('Make sense of your log files', 'wpuerrorlogs');

        # UPDATE
        require_once __DIR__ . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpuerrorlogs\WPUBaseUpdate(
            'WordPressUtilities',
            'wpuerrorlogs',
            $this->plugin_version);

        # TOOLBOX
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpuerrorlogs\WPUBaseToolbox();

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

        $errors = $this->get_logs($number_of_days);

        /* Keep only first five and extract data */
        $colnames = array(
            'count' => __('Count', 'wpuerrorlogs'),
            'date' => __('Date', 'wpuerrorlogs'),
            'type' => __('Type', 'wpuerrorlogs'),
            'text' => __('Text', 'wpuerrorlogs')
        );

        /* Select number of days */
        echo '<label for="wpuerrorlogs_switch_day">' . __('Check the last :', 'wpuerrorlogs') . '</label> ';
        echo '<select id="wpuerrorlogs_switch_day" onchange="document.location.href=\'' . $this->adminpages->get_page_url('main') . '&number_of_days=\' + this.value;">';
        for ($i = $this->number_of_days; $i > 0; $i--) {
            echo '<option value="' . $i . '"' . ($number_of_days == $i ? ' selected' : '') . '>' . ($i < 2 ? __('1 day', 'wpuerrorlogs') : sprintf(__('%s days', 'wpuerrorlogs'), $i)) . '</option>';
        }
        echo '</select>';

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

    /* ----------------------------------------------------------
      Sort errors
    ---------------------------------------------------------- */

    function sort_errors_by_top($errors, $max_number = 5) {
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

    function sort_errors_by_latest($errors, $max_number = 5) {
        $latest_errors = array_slice($errors, 0, $max_number, true);
        foreach ($latest_errors as $i => $error) {
            $latest_errors[$i]['text'] = $this->expand_error_text($error['text']);
        }
        /* Reset keys */
        $latest_errors = array_values($latest_errors);
        $latest_errors = $this->prepare_errors_for_display($latest_errors);
        return $latest_errors;
    }

    function prepare_errors_for_display($errors) {
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

    function get_logs($number_of_days) {

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
                    $previous_files = array_slice($previous_files, 0, $number_of_days);
                }
            }
        }
        if (!is_readable($file)) {
            return array();
        }

        $max_date = time() - 86400 * $number_of_days;

        /* Parse errors in files */
        $errors = $this->get_logs_from_file($file, $max_date);
        if (empty($previous_files)) {
            $previous_files = $this->find_previous_log_files($file, $number_of_days);
        }

        foreach ($previous_files as $previous_file) {
            $errors_previous = $this->get_logs_from_file($previous_file, $max_date);
            foreach ($errors_previous as $error) {
                $errors[] = $error;
            }
        }
        return $errors;
    }

    function find_previous_log_files($file, $number_of_days = 5) {
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

    function get_logs_from_file($file, $max_date) {

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
                    if ($time < $max_date) {
                        break;
                    }
                }

                if ($currentError['date'] == 'none' && !empty($currentError['text'])) {
                    $line_error['text'] = array_merge($currentError['text'], $line_error['text']);
                    $line_error['text'] = $this->minimize_error_text(implode("\n", array_reverse($line_error['text'])));
                    $errors[] = $line_error;
                    $currentError = $default_error;
                    continue;
                }

                $line_error['text'] = implode('', $line_error['text']);
                $errors[] = $line_error;

            } else {
                $currentError['text'][] = $line;
            }
        }

        if (!empty($currentError) && is_array($currentError['text']) && !empty($currentError['text'])) {
            $currentError['text'] = $this->minimize_error_text(implode("\n", array_reverse($currentError['text'])));
            $errors[] = $currentError;
        }

        return $errors;
    }

    function get_error_from_line($line) {
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

    function display_content_with_toggle($content) {
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

    function minimize_get_correspondances() {
        return array(
            'abs' => ABSPATH,
            'plug' => 'wp-content/plugins/'
        );
    }

    function expand_error_text($text) {
        $correspondances = $this->minimize_get_correspondances();
        foreach ($correspondances as $min => $max) {
            $text = str_replace('#!' . $min . '!#', $max, $text);
        }
        return $text;
    }

    function minimize_error_text($text) {
        $correspondances = $this->minimize_get_correspondances();
        foreach ($correspondances as $min => $max) {
            $text = str_replace($max, '#!' . $min . '!#', $text);
        }
        return $text;
    }
}

$WPUErrorLogs = new WPUErrorLogs();
