<?php
/*
Plugin Name: WPU Error Logs
Plugin URI: https://github.com/WordPressUtilities/wpuerrorlogs
Update URI: https://github.com/WordPressUtilities/wpuerrorlogs
Description: Make sense of your log files
Version: 0.1.0
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
    private $plugin_version = '0.1.0';
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
                'icon_url' => 'dashicons-admin-generic',
                'menu_name' => $this->plugin_settings['name'],
                'name' => 'Main page',
                'settings_link' => true,
                'settings_name' => __('Settings'),
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
        $logfile = ABSPATH . '/wp-content/debug.log';
        if (!WP_DEBUG_LOG) {
            echo 'Debug logs are not enabled';
            return;
        }
        if (is_readable(WP_DEBUG_LOG)) {
            $logfile = WP_DEBUG_LOG;
        }

        $errors = $this->extract_logs_from_file($logfile);

        $textCounts = [];

        foreach ($errors as $error) {
            if (!isset($textCounts[$error['text']])) {
                $textCounts[$error['text']] = 0;
            }
            $textCounts[$error['text']]++;
        }
        arsort($textCounts);

        /* Keep only first five and extract data */
        $textCounts = array_slice($textCounts, 0, 5, true);
        $display_values = array();
        foreach ($textCounts as $text => $count) {
            $display_values[] = array(
                'count' => $count,
                'text' => $this->expand_error_text($text)
            );
        }

        $colnames = array(
            'count' => __('Count', 'wpuerrorlogs'),
            'date' => __('Date', 'wpuerrorlogs'),
            'type' => __('Type', 'wpuerrorlogs'),
            'text' => __('Text', 'wpuerrorlogs')
        );

        echo '<h2>' . __('Top errors', 'wpuerrorlogs') . '</h2>';
        echo $this->array_to_html_table($display_values, array(
            'table_classname' => 'widefat',
            'colnames' => $colnames
        ));

        $latest_errors = array_reverse($errors);
        $latest_errors = array_slice($latest_errors, 0, 5, true);
        echo '<h2>' . __('Latest errors', 'wpuerrorlogs') . '</h2>';
        echo $this->array_to_html_table($latest_errors, array(
            'table_classname' => 'widefat',
            'colnames' => $colnames
        ));

    }

    /* ----------------------------------------------------------
      Extract logs from file
    ---------------------------------------------------------- */

    function extract_logs_from_file($file) {
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

        return $errors;
    }

    function get_error_from_line($line) {
        /* Extract values */
        $date_parts = explode(']', $line);
        $date = str_replace('[', '', $date_parts[0]);
        $text = trim(substr($line, strlen('[' . $date . ']'), -1));

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

    function array_to_html_table($array, $args = array()) {
        $default_args = array(
            'table_classname' => 'widefat',
            'colnames' => array()
        );
        if (!is_array($args)) {
            $args = array();
        }
        $args = array_merge($default_args, $args);

        $html = '<table class="' . esc_attr($args['table_classname']) . '">';

        // HEAD
        $html .= '<thead><tr>';
        foreach ($array[0] as $key => $value) {
            $label = htmlspecialchars($key);
            if (isset($args['colnames'][$key])) {
                $label = $args['colnames'][$key];
            }
            $html .= '<th>' . $label . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($array as $key => $value) {
            $html .= '<tr>';
            foreach ($value as $key2 => $value2) {
                $html .= '<td>' . htmlspecialchars($value2) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        $html .= '</table>';
        return $html;
    }

}

$WPUErrorLogs = new WPUErrorLogs();
