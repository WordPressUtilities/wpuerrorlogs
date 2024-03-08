<?php
/*
Plugin Name: WPU Error Logs
Plugin URI: https://github.com/WordPressUtilities/wpuerrorlogs
Update URI: https://github.com/WordPressUtilities/wpuerrorlogs
Description: Make sense of your log files
Version: 0.4.0
Author: Darklg
Author URI: https://github.com/Darklg
Text Domain: wpuerrorlogs
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
    private $plugin_version = '0.4.0';
    private $plugin_settings = array(
        'id' => 'wpuerrorlogs',
        'name' => 'WPU Error Logs'
    );
    private $basetoolbox;
    private $baseemail;
    private $basecron;
    private $adminpages;
    private $baseadmindatas;
    private $settings;
    private $settings_obj;
    private $settings_details;
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
        # TOOLBOX
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpuerrorlogs\WPUBaseToolbox();
        # EMAIL
        require_once __DIR__ . '/inc/WPUBaseEmail/WPUBaseEmail.php';
        $this->baseemail = new \wpuerrorlogs\WPUBaseEmail();
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
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpuerrorlogs\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);
        # CUSTOM TABLE
        require_once __DIR__ . '/inc/WPUBaseAdminDatas/WPUBaseAdminDatas.php';
        $this->baseadmindatas = new \wpuerrorlogs\WPUBaseAdminDatas();
        $this->baseadmindatas->init(array(
            'handle_database' => false,
            'plugin_id' => $this->plugin_settings['id'],
            'table_name' => 'wpuerrorlogs_logs',
            'table_fields' => array(
                'message' => array(
                    'public_name' => 'message',
                    'type' => 'sql',
                    'sql' => 'MEDIUMTEXT'
                )
            )
        ));
        # SETTINGS
        $this->settings_details = array(
            # Admin page
            'create_page' => false,
            'plugin_basename' => plugin_basename(__FILE__),
            # Default
            'plugin_name' => $this->plugin_settings['name'],
            'plugin_id' => $this->plugin_settings['id'],
            'option_id' => $this->plugin_settings['id'] . '_options',
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpuerrorlogs')
                )
            )
        );
        $this->settings = array(
            //    'value' => array(
            //        'label' => __('My Value', 'wpuerrorlogs'),
            //        'help' => __('A little help.', 'wpuerrorlogs'),
            //        'type' => 'textarea'
            //    )
        );
        require_once __DIR__ . '/inc/WPUBaseSettings/WPUBaseSettings.php';
        $this->settings_obj = new \wpuerrorlogs\WPUBaseSettings($this->settings_details, $this->settings);
        /* Include hooks */
        require_once __DIR__ . '/inc/WPUBaseCron/WPUBaseCron.php';
        $this->basecron = new \wpuerrorlogs\WPUBaseCron(array(
            'pluginname' => $this->plugin_settings['name'],
            'cronhook' => 'wpuerrorlogs__cron_hook',
            'croninterval' => 3600
        ));
        /* Callback when hook is triggered by the cron */
        add_action('wpuerrorlogs__cron_hook', array(&$this,
            'wpuerrorlogs__cron_hook'
        ), 10);
    }

    public function wpuerrorlogs__cron_hook() {

    }

    public function page_content__main() {

        /* Find debug file */
        if (!WP_DEBUG_LOG) {
            echo 'Debug logs are not enabled';
            return;
        }

        $errors = $this->get_logs();

        /* Prepare for display */
        $errors = array_map(function ($item) {
            $item['text'] = $this->display_content_with_toggle($item['text']);
            return $item;
        }, $errors);

        /* Keep only first five and extract data */
        $colnames = array(
            'count' => __('Count', 'wpuerrorlogs'),
            'date' => __('Date', 'wpuerrorlogs'),
            'type' => __('Type', 'wpuerrorlogs'),
            'text' => __('Text', 'wpuerrorlogs')
        );

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
        return $top_errors;
    }

    function sort_errors_by_latest($errors, $max_number = 5) {
        $latest_errors = array_slice($errors, 0, $max_number, true);
        foreach ($latest_errors as $i => $error) {
            $latest_errors[$i]['text'] = $this->expand_error_text($error['text']);
        }
        return $latest_errors;
    }

    /* ----------------------------------------------------------
      Extract logs from file
    ---------------------------------------------------------- */

    function get_logs() {

        $number_of_days = 5;
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

        /* Parse errors in files */
        $errors = $this->get_logs_from_file($file);
        if (empty($previous_files)) {
            $previous_files = $this->find_previous_log_files($file, $number_of_days);
        }
        foreach ($previous_files as $previous_file) {
            $errors_previous = $this->get_logs_from_file($previous_file);
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

    function get_logs_from_file($file) {

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $errors = [];
        $currentError = array();

        foreach ($lines as $line) {

            /* Is it a new error */
            if (substr($line, 0, 1) == '[' && preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\]/', $line, $matches)) {
                if (!empty($currentError)) {
                    $currentError['text'] = $this->minimize_error_text($currentError['text']);
                    $errors[] = $currentError;
                    $currentError = array();
                }

                $currentError = $this->get_error_from_line($line);

            } else {
                /* Is is the next line of an existing error */
                $currentError['text'] .= "\n" . $line;
            }
        }

        if (!empty($currentError)) {
            $currentError['text'] = $this->minimize_error_text($currentError['text']);
            $errors[] = $currentError;
        }

        return array_reverse($errors);
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
            'text' => $text
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
