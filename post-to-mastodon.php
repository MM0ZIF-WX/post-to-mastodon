<?php
/*
Plugin Name: Post to Mastodon
Description: Posts weather updates from clientraw.txt to Mastodon with timestamp logging and debug features
Version: 1.4
Author: Marcus Hazel-McGown - MM0ZIF
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize plugin
function wtm_init() {
    if (!get_option('wtm_settings')) {
        add_option('wtm_settings', array(
            'initialized' => true,
            'version' => '1.4'
        ));
    }
    wtm_log_debug('Plugin initialized on page load');
}
add_action('init', 'wtm_init');

// Ensure cron is scheduled
function wtm_ensure_cron_scheduled() {
    $interval = get_option('wtm_post_interval', 'hourly');
    if (!wp_next_scheduled('weather_mastodon_post_event')) {
        $result = wp_schedule_event(time(), $interval, 'weather_mastodon_post_event');
        wtm_log_debug('Cron rescheduled: weather_mastodon_post_event, interval: ' . $interval . ', result: ' . ($result === false ? 'Failed' : 'Success'));
        error_log('Cron rescheduled: weather_mastodon_post_event, interval: ' . $interval . ', result: ' . ($result === false ? 'Failed' : 'Success'));
    }
}
add_action('admin_init', 'wtm_ensure_cron_scheduled');
add_action('init', 'wtm_ensure_cron_scheduled');

// Test database writes and reset debug log
add_action('admin_init', function() {
    if (isset($_GET['reset_wtm_debug_log'])) {
        update_option('wtm_debug_log', [], false);
        wtm_log_debug('Debug log reset');
        error_log('Debug log reset');
    }
    update_option('wtm_test_option', 'test_value', false);
    wtm_log_debug('Test database write on admin_init');
    error_log('Test database write attempted');
});

// Custom cron intervals
function wtm_custom_intervals($schedules) {
    $schedules['twohourly'] = array(
        'interval' => 7200,
        'display' => 'Every Two Hours'
    );
    $schedules['sixhourly'] = array(
        'interval' => 21600,
        'display' => 'Every Six Hours'
    );
    return $schedules;
}
add_filter('cron_schedules', 'wtm_custom_intervals');

// Log debug messages
function wtm_log_debug($message) {
    $log = get_option('wtm_debug_log', []);
    $log[] = array(
        'time' => current_time('mysql'),
        'message' => $message
    );
    $result = update_option('wtm_debug_log', $log, false);
    error_log('wtm_log_debug: ' . $message . ' | Result: ' . ($result ? 'Success' : 'Failed'));
    if (!$result) {
        $fallback_log = get_transient('wtm_fallback_log') ?: [];
        $fallback_log[] = array('time' => current_time('mysql'), 'message' => $message);
        set_transient('wtm_fallback_log', $fallback_log, 3600);
    }
    if (count($log) > 50) {
        $log = array_slice($log, -50);
        update_option('wtm_debug_log', $log, false);
    }
}

// Log clientraw.txt upload timestamp
function wtm_log_upload($status, $details = '') {
    $log = get_option('wtm_upload_log', []);
    $log[] = array(
        'time' => current_time('mysql'),
        'status' => $status,
        'details' => $details
    );
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    update_option('wtm_upload_log', $log, false);
}

// Handle test post
function wtm_handle_test_post() {
    wtm_log_debug('Test post action triggered');
    if (!isset($_POST['wtm_test_post']) || !check_admin_referer('wtm_test_post')) {
        wtm_log_debug('Test post failed: Invalid nonce or missing POST data');
        wp_redirect(admin_url('options-general.php?page=wtm'));
        exit;
    }
    
    $data = wtm_read_clientraw();
    $debug_message = "Test Post - Raw Weather Data:\n" . print_r($data, true);
    
    if (!$data) {
        $error = 'Failed to read weather data';
        wtm_log_debug($error . "\n" . $debug_message);
        set_transient('wtm_admin_notice', array(
            'message' => $error . '<br><pre>' . $debug_message . '</pre>',
            'type' => 'error'
        ), 45);
        wp_redirect(admin_url('options-general.php?page=wtm'));
        exit;
    }
    
    $result = wtm_post_to_mastodon($data);
    $debug_message .= "\nMastodon API Response:\n" . print_r($result, true);
    
    $message = $result['success'] ? 'Test post successful!' : 'Test post failed: ' . $result['error'];
    wtm_log_debug("Test Post Result: $message\n$debug_message");
    
    set_transient('wtm_admin_notice', array(
        'message' => $message . '<br><pre>' . $debug_message . '</pre>',
        'type' => $result['success'] ? 'success' : 'error'
    ), 45);
    
    wp_redirect(admin_url('options-general.php?page=wtm'));
    exit;
}
add_action('admin_post_wtm_test_post', 'wtm_handle_test_post');

// Manual cron trigger
function wtm_handle_manual_cron() {
    if (!isset($_POST['wtm_manual_cron']) || !check_admin_referer('wtm_manual_cron')) {
        wtm_log_debug('Manual cron failed: Invalid nonce or missing POST data');
        wp_redirect(admin_url('options-general.php?page=wtm'));
        exit;
    }
    
    wtm_cron_exec();
    set_transient('wtm_admin_notice', array(
        'message' => 'Manual cron executed. Check debug log for results.',
        'type' => 'success'
    ), 45);
    wp_redirect(admin_url('options-general.php?page=wtm'));
    exit;
}
add_action('admin_post_wtm_manual_cron', 'wtm_handle_manual_cron');

// Display admin notices
function wtm_admin_notices() {
    $notice = get_transient('wtm_admin_notice');
    if ($notice) {
        echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . wp_kses_post($notice['message']) . '</p></div>';
        delete_transient('wtm_admin_notice');
    }
}
add_action('admin_notices', 'wtm_admin_notices');

// Register settings with sanitization
function wtm_register_settings() {
    register_setting('wtm_options_group', 'wtm_clientraw_path', array(
        'sanitize_callback' => 'wtm_sanitize_url'
    ));
    register_setting('wtm_options_group', 'wtm_mastodon_instance', array(
        'sanitize_callback' => 'wtm_sanitize_url'
    ));
    register_setting('wtm_options_group', 'wtm_client_key', 'sanitize_text_field');
    register_setting('wtm_options_group', 'wtm_client_secret', 'sanitize_text_field');
    register_setting('wtm_options_group', 'wtm_mastodon_token', 'sanitize_text_field');
    register_setting('wtm_options_group', 'wtm_post_title', 'sanitize_text_field');
    register_setting('wtm_options_group', 'wtm_location', 'sanitize_text_field');
    register_setting('wtm_options_group', 'wtm_url', array(
        'sanitize_callback' => 'wtm_sanitize_url'
    ));
    register_setting('wtm_options_group', 'wtm_post_interval', array(
        'sanitize_callback' => 'wtm_sanitize_interval'
    ));
}
add_action('admin_init', 'wtm_register_settings');

// Sanitization callbacks
function wtm_sanitize_url($input) {
    $input = trim($input);
    if (empty($input)) {
        return $input;
    }
    if (!preg_match('#^https?://.+#', $input)) {
        $input = 'https://' . $input;
    }
    return esc_url_raw($input);
}

function wtm_sanitize_interval($input) {
    $valid = array('hourly', 'twohourly', 'sixhourly');
    return in_array($input, $valid) ? $input : 'hourly';
}

// Options page
function wtm_options_page() {
    $next_cron = wp_next_scheduled('weather_mastodon_post_event');
    $interval = get_option('wtm_post_interval', 'hourly');
    $fallback_log = get_transient('wtm_fallback_log') ?: [];
    ?>
    <div class="wrap">
        <h1>Weather to Mastodon Settings</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
            <a href="#debug" class="nav-tab">Debug Log</a>
            <a href="#cron" class="nav-tab">Cron Status</a>
        </h2>

        <!-- Settings Tab -->
        <div id="settings" class="tab-content">
            <form method="post" action="options.php">
                <?php settings_fields('wtm_options_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Clientraw.txt URL</th>
                        <td><input type="text" name="wtm_clientraw_path" size="60" value="<?php echo esc_attr(get_option('wtm_clientraw_path')); ?>" /><p class="description">Enter the full URL to your clientraw.txt file (e.g., https://mm0zif.radio/clientraw.txt).</p></td>
                    </tr>
                    <tr>
                        <th>Mastodon Instance URL</th>
                        <td><input type="text" name="wtm_mastodon_instance" size="60" value="<?php echo esc_attr(get_option('wtm_mastodon_instance')); ?>" /><p class="description">E.g., https://mastodon.social</p></td>
                    </tr>
                    <tr>
                        <th>Client Key</th>
                        <td><input type="text" name="wtm_client_key" size="60" value="<?php echo esc_attr(get_option('wtm_client_key')); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input type="text" name="wtm_client_secret" size="60" value="<?php echo esc_attr(get_option('wtm_client_secret')); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Access Token</th>
                        <td><input type="text" name="wtm_mastodon_token" size="60" value="<?php echo esc_attr(get_option('wtm_mastodon_token')); ?>" /><p class="description">Obtain from your Mastodon app settings.</p></td>
                    </tr>
                    <tr>
                        <th>Post Title</th>
                        <td><input type="text" name="wtm_post_title" size="60" value="<?php echo esc_attr(get_option('wtm_post_title')); ?>" /><p class="description">E.g., MM0ZIF_WX Weather Update</p></td>
                    </tr>
                    <tr>
                        <th>Location</th>
                        <td><input type="text" name="wtm_location" size="60" value="<?php echo esc_attr(get_option('wtm_location')); ?>" /><p class="description">E.g., Dundee, Scotland</p></td>
                    </tr>
                    <tr>
                        <th>Website URL</th>
                        <td><input type="text" name="wtm_url" size="60" value="<?php echo esc_attr(get_option('wtm_url')); ?>" /><p class="description">E.g., https://mm0zif.radio/current/WX/</p></td>
                    </tr>
                    <tr>
                        <th>Posting Interval</th>
                        <td>
                            <select name="wtm_post_interval">
                                <option value="hourly" <?php selected($interval, 'hourly'); ?>>Hourly</option>
                                <option value="twohourly" <?php selected($interval, 'twohourly'); ?>>Every 2 Hours</option>
                                <option value="sixhourly" <?php selected($interval, 'sixhourly'); ?>>Every 6 Hours</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wtm_test_post" />
                <?php wp_nonce_field('wtm_test_post'); ?>
                <?php submit_button('Test Post', 'secondary', 'wtm_test_post', false); ?>
            </form>
        </div>

        <!-- Debug Log Tab -->
        <div id="debug" class="tab-content" style="display:none;">
            <h2>Debug Log</h2>
            <p>Recent plugin activity (last 50 entries):</p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $log = get_option('wtm_debug_log', []);
                    if (!empty($fallback_log)) {
                        $log = array_merge($log, $fallback_log);
                        usort($log, function($a, $b) {
                            return strtotime($b['time']) - strtotime($a['time']);
                        });
                        $log = array_slice($log, 0, 50);
                    }
                    if (empty($log)) {
                        echo '<tr><td colspan="2">No debug entries yet.</td></tr>';
                    } else {
                        foreach ($log as $entry) {
                            echo '<tr><td>' . esc_html($entry['time']) . '</td><td><pre>' . esc_html($entry['message']) . '</pre></td></tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
            <form method="post" action="">
                <input type="hidden" name="wtm_clear_log" value="1" />
                <?php wp_nonce_field('wtm_clear_log'); ?>
                <?php submit_button('Clear Debug Log', 'secondary', 'wtm_clear_log_submit', false); ?>
            </form>
        </div>

        <!-- Cron Status Tab -->
        <div id="cron" class="tab-content" style="display:block;">
            <h2>Cron Status</h2>
            <p><strong>Current Interval:</strong> <?php echo esc_html($interval); ?></p>
            <p><strong>Next Scheduled Run:</strong> <?php echo $next_cron ? esc_html(date('Y-m-d H:i:s', $next_cron)) : 'Not scheduled'; ?></p>
            <p><strong>Upload Log (last 10 entries):</strong></p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $upload_log = get_option('wtm_upload_log', []);
                    if (empty($upload_log)) {
                        echo '<tr><td colspan="3">No upload entries yet.</td></tr>';
                    } else {
                        foreach (array_slice(array_reverse($upload_log), 0, 10) as $entry) {
                            echo '<tr><td>' . esc_html($entry['time']) . '</td><td>' . esc_html($entry['status']) . '</td><td>' . esc_html($entry['details']) . '</td></tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wtm_manual_cron" />
                <?php wp_nonce_field('wtm_manual_cron'); ?>
                <?php submit_button('Run Cron Manually', 'secondary', 'wtm_manual_cron', false); ?>
            </form>
        </div>

        <style>
            .nav-tab { cursor: pointer; }
            .tab-content { margin-top: 20px; }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tabs = document.querySelectorAll('.nav-tab');
                const contents = document.querySelectorAll('.tab-content');
                tabs.forEach(tab => {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        tabs.forEach(t => t.classList.remove('nav-tab-active'));
                        contents.forEach(c => c.style.display = 'none');
                        this.classList.add('nav-tab-active');
                        document.querySelector(this.getAttribute('href')).style.display = 'block';
                    });
                });
            });
        </script>
    </div>
    <?php
}

// Handle clear debug log
function wtm_handle_clear_log() {
    if (!isset($_POST['wtm_clear_log']) || !check_admin_referer('wtm_clear_log')) {
        wp_redirect(admin_url('options-general.php?page=wtm'));
        exit;
    }
    update_option('wtm_debug_log', [], false);
    delete_transient('wtm_fallback_log');
    set_transient('wtm_admin_notice', array(
        'message' => 'Debug log cleared.',
        'type' => 'success'
    ), 45);
    wp_redirect(admin_url('options-general.php?page=wtm'));
    exit;
}
add_action('admin_post_wtm_clear_log', 'wtm_handle_clear_log');

add_action('admin_menu', function() {
    add_options_page('Weather to Mastodon', 'Weather to Mastodon', 'manage_options', 'wtm', 'wtm_options_page');
});

function wtm_read_clientraw() {
    $file_path = get_option('wtm_clientraw_path');
    
    if (empty($file_path)) {
        wtm_log_upload('Failed', 'Clientraw.txt URL is empty');
        wtm_log_debug('Clientraw read failed: Empty URL');
        error_log('Clientraw read failed: Empty URL');
        return false;
    }
    
    $args = array(
        'timeout' => 30,
        'sslverify' => false,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    );
    
    $response = wp_remote_get($file_path, $args);
    
    if (is_wp_error($response)) {
        $error = 'HTTP Error: ' . $response->get_error_message();
        wtm_log_upload('Failed', $error);
        wtm_log_debug("Clientraw read failed: $error");
        error_log("Clientraw read failed: $error");
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $error = "HTTP $response_code";
        wtm_log_upload('Failed', $error);
        wtm_log_debug("Clientraw read failed: $error");
        error_log("Clientraw read failed: $error");
        return false;
    }
    
    $content = wp_remote_retrieve_body($response);
    wtm_log_debug('Clientraw response: ' . substr($content, 0, 100));
    error_log('Clientraw response: ' . substr($content, 0, 100));
    if (empty($content)) {
        wtm_log_upload('Failed', 'Empty response body');
        wtm_log_debug('Clientraw read failed: Empty response');
        error_log('Clientraw read failed: Empty response');
        return false;
    }
    
    $data = explode(' ', trim($content));
    
    if (count($data) < 6) {
        wtm_log_upload('Failed', 'Invalid clientraw.txt format');
        wtm_log_debug('Clientraw read failed: Invalid format, data: ' . print_r($data, true));
        error_log('Clientraw read failed: Invalid format');
        return false;
    }
    
    $result = array(
        'temperature' => isset($data[4]) ? round(floatval($data[4]), 1) : 0,
        'humidity' => isset($data[5]) ? round(floatval($data[5])) : 0,
        'wind_speed' => isset($data[1]) ? round(floatval($data[1])) : 0,
        'wind_direction' => isset($data[3]) ? wtm_get_wind_direction($data[3]) : 'N/A'
    );
    
    wtm_log_upload('Success', 'Data parsed: ' . print_r($result, true));
    wtm_log_debug('Clientraw read successful: ' . print_r($result, true));
    error_log('Clientraw read successful');
    return $result;
}

function wtm_get_wind_direction($degrees) {
    $degrees = floatval($degrees);
    $directions = array('N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW');
    $index = round($degrees / 22.5) % 16;
    return $directions[$index];
}

function wtm_format_weather_output($data) {
    $title = get_option('wtm_post_title', 'Weather Update');
    $location = get_option('wtm_location', 'Unknown Location');
    $url = get_option('wtm_url', '');
    
    $status = "$title\n";
    $status .= "Temperature: {$data['temperature']}°C\n";
    $status .= "Humidity: {$data['humidity']}%\n";
    $status .= "Wind: {$data['wind_speed']} km/h {$data['wind_direction']}\n";
    $status .= "Location: $location\n";
    
    if (!empty($url)) {
        $status .= "$url\n";
    }
    
    $status .= "#weather";
    
    wtm_log_debug('Formatted status: ' . $status);
    error_log('Formatted status: ' . $status);
    return $status;
}

function wtm_post_to_mastodon($data) {
    if (!$data) {
        $error = 'No weather data available';
        wtm_log_debug('Mastodon post failed: ' . $error);
        error_log('Mastodon post failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    $instance = get_option('wtm_mastodon_instance');
    $token = get_option('wtm_mastodon_token');

    if (empty($instance)) {
        $error = 'Mastodon instance URL not configured';
        wtm_log_debug('Mastodon post failed: ' . $error);
        error_log('Mastodon post failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    if (empty($token)) {
        $error = 'Mastodon access token not configured';
        wtm_log_debug('Mastodon post failed: ' . $error);
        error_log('Mastodon post failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    $instance = rtrim(preg_replace('#^(https?:)?//#', 'https://', $instance), '/');
    $status = wtm_format_weather_output($data);

    $response = wp_remote_post("$instance/api/v1/statuses", [
        'headers' => [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
            'User-Agent' => 'MM0ZIF_WX_WordPress_Plugin/1.4'
        ],
        'body' => json_encode([
            'status' => $status,
            'visibility' => 'public'
        ]),
        'timeout' => 30,
        'sslverify' => false
    ]);

    if (is_wp_error($response)) {
        $error = 'API Error: ' . $response->get_error_message();
        wtm_log_debug('Mastodon post failed: ' . $error);
        error_log('Mastodon post failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if ($response_code !== 200) {
        $error_message = isset($response_body['error']) ? $response_body['error'] : 'Unknown error';
        $error = "HTTP $response_code: $error_message";
        wtm_log_debug('Mastodon post failed: ' . $error . "\nResponse: " . print_r($response_body, true));
        error_log('Mastodon post failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    wtm_save_post_history($status, $response_body);
    wtm_log_debug('Mastodon post successful: ' . print_r($response_body, true));
    error_log('Mastodon post successful');
    return ['success' => true, 'response' => $response_body];
}

function wtm_save_post_history($status, $response) {
    $history = get_option('wtm_post_history', []);
    $history[] = array(
        'time' => current_time('mysql'),
        'status' => $status,
        'response' => $response
    );
    
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    
    update_option('wtm_post_history', $history, false);
}

register_activation_hook(__FILE__, 'wtm_activation');
register_deactivation_hook(__FILE__, 'wtm_deactivation');
add_action('weather_mastodon_post_event', 'wtm_cron_exec');

function wtm_activation() {
    wp_clear_scheduled_hook('weather_mastodon_post_event');
    $interval = get_option('wtm_post_interval', 'hourly');
    $result = wp_schedule_event(time(), $interval, 'weather_mastodon_post_event');
    wtm_log_debug('Plugin activated, interval: ' . $interval . ', weather_mastodon_post_event result: ' . ($result === false ? 'Failed' : 'Success'));
    error_log('Plugin activated, interval: ' . $interval . ', weather_mastodon_post_event result: ' . ($result === false ? 'Failed' : 'Success'));
}

function wtm_deactivation() {
    wp_clear_scheduled_hook('weather_mastodon_post_event');
    wtm_log_debug('Plugin deactivated, cron cleared');
    error_log('Plugin deactivated, cron cleared');
}

function wtm_cron_exec() {
    wtm_log_debug('Cron execution started. User: ' . (is_user_logged_in() ? wp_get_current_user()->user_login : 'None'));
    error_log('Cron execution started');
    $data = wtm_read_clientraw();
    wtm_log_debug('Clientraw data: ' . print_r($data, true));
    error_log('Clientraw data retrieved');
    if ($data) {
        $result = wtm_post_to_mastodon($data);
        wtm_log_debug('Cron execution result: ' . ($result['success'] ? 'Success' : 'Failed - ' . $result['error']));
        error_log('Cron execution result: ' . ($result['success'] ? 'Success' : 'Failed'));
    } else {
        wtm_log_debug('Cron execution failed: No weather data');
        error_log('Cron execution failed: No weather data');
    }
}
?>