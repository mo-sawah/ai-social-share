<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Utils {

    public static function get_settings() : array {
        $defaults = [
            // --- AI Settings ---
            'openrouter_api_key' => '',
            'openrouter_model'   => 'openai/gpt-4o-mini',
            'openrouter_site'    => home_url(),
            'openrouter_app'     => 'AI Social Share',
            'enable_web_search'  => false,

            // --- Scheduler ---
            'schedule_minutes'   => 30,
            'max_posts_per_run'  => 2,
            'filter_mode'        => 'all', // all, category, or tag
            'filter_terms'       => '',
            
            // --- Debug ---
            'enable_debug_logs'  => false,
            
            // --- Facebook ---
            'fb_app_id'          => '',
            'fb_app_secret'      => '',
            'fb_api_version'     => 'v24.0',
            'fb_page_id'         => '',
            'fb_page_name'       => '',
            'fb_page_token'      => '', // Encrypted
            'fb_connected_at'    => 0,
            'prompt_facebook'    => "You are a professional social media editor.\n\nWrite a Facebook post for this article.\n- Friendly and engaging tone.\n- 1–2 paragraphs.\n- 2–3 hashtags.\n- No links (link is attached).\n\nReturn ONLY the post text.",

            // --- X (Twitter) ---
            'x_enabled'          => false,
            'x_consumer_key'     => '',  // App Key
            'x_consumer_secret'  => '',  // App Secret
            'x_access_token'     => '',  // User Token (saved after login)
            'x_access_secret'    => '',  // User Secret (saved after login)
            'x_username'         => '',  // Connected Account Name
            'prompt_x'           => "You are a social media manager.\n\nWrite a Tweet for this article.\n- Under 200 chars.\n- Punchy and viral.\n- Max 2 hashtags.\n- No links.\n\nReturn ONLY the tweet text.",
        ];

        $saved = get_option('aiss_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge($defaults, $saved);
    }

    public static function update_settings(array $new_settings) : bool {
        $current = self::get_settings();
        $merged = array_merge($current, $new_settings);
        
        // Validate before saving
        $validated = self::validate_settings($merged);
        
        return update_option('aiss_settings', $validated, false);
    }

    /**
     * Validate settings before saving
     */
    public static function validate_settings(array $settings) : array {
        // Validate schedule_minutes
        if (isset($settings['schedule_minutes'])) {
            $settings['schedule_minutes'] = max(5, min(1440, (int)$settings['schedule_minutes']));
        }
        
        // Validate max_posts_per_run
        if (isset($settings['max_posts_per_run'])) {
            $settings['max_posts_per_run'] = max(1, min(20, (int)$settings['max_posts_per_run']));
        }
        
        // Validate filter_mode
        if (isset($settings['filter_mode'])) {
            $valid_modes = ['all', 'category', 'tag'];
            if (!in_array($settings['filter_mode'], $valid_modes, true)) {
                $settings['filter_mode'] = 'all';
            }
        }
        
        // Trim text fields
        $text_fields = [
            'openrouter_api_key', 'openrouter_model', 'fb_app_id', 
            'fb_app_secret', 'fb_api_version', 'x_consumer_key', 
            'x_consumer_secret', 'filter_terms'
        ];
        
        foreach ($text_fields as $field) {
            if (isset($settings[$field])) {
                $settings[$field] = trim($settings[$field]);
            }
        }
        
        return $settings;
    }

    /**
     * Check if all required settings are configured
     */
    public static function is_configured() : array {
        $settings = self::get_settings();
        $issues = [];
        
        // Check OpenRouter
        if (empty($settings['openrouter_api_key'])) {
            $issues[] = 'OpenRouter API Key is missing';
        }
        
        // Check if at least one network is connected
        $fb_connected = !empty($settings['fb_page_id']) && !empty($settings['fb_page_token']);
        $x_connected = !empty($settings['x_enabled']) && 
                       !empty($settings['x_consumer_key']) && 
                       !empty($settings['x_access_token']);
        
        if (!$fb_connected && !$x_connected) {
            $issues[] = 'No social networks are connected';
        }
        
        return [
            'configured' => empty($issues),
            'issues' => $issues
        ];
    }

    public static function admin_url_settings(array $args = []) : string {
        $url = admin_url('options-general.php?page=ai-social-share');
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        return $url;
    }

    /**
     * Improved encryption with better fallback
     */
    public static function encrypt(string $plain) : string {
        if ($plain === '') return '';
        
        if (!function_exists('openssl_encrypt')) {
            // Fallback to base64 if openssl not available
            return 'BASE64:' . base64_encode($plain);
        }
        
        $key = self::get_encryption_key();
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($cipher === false) {
            return 'BASE64:' . base64_encode($plain);
        }
        
        return 'ENCRYPTED:' . base64_encode($iv . $cipher);
    }

    /**
     * Improved decryption with better fallback
     */
    public static function decrypt(string $stored) : string {
        if ($stored === '') return '';
        
        // Handle base64 fallback
        if (strpos($stored, 'BASE64:') === 0) {
            $decoded = base64_decode(substr($stored, 7), true);
            return $decoded !== false ? $decoded : '';
        }
        
        // Handle encrypted data
        if (strpos($stored, 'ENCRYPTED:') === 0) {
            $stored = substr($stored, 10);
        }
        
        if (!function_exists('openssl_decrypt')) {
            return $stored;
        }
        
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 17) return '';
        
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = self::get_encryption_key();
        
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }

    /**
     * Get encryption key consistently
     */
    private static function get_encryption_key() : string {
        $keys = '';
        if (defined('AUTH_KEY')) $keys .= AUTH_KEY;
        if (defined('SECURE_AUTH_KEY')) $keys .= SECURE_AUTH_KEY;
        if ($keys === '') $keys = 'fallback_key_' . home_url();
        
        return hash('sha256', $keys, true);
    }

    /**
     * Clean text for AI processing
     */
    public static function clean_text(string $html, int $max_chars = 4000) : string {
        // Remove shortcodes
        $html = strip_shortcodes($html);
        
        // Strip all tags
        $text = wp_strip_all_tags($html, true);
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Truncate if needed
        if (function_exists('mb_substr')) {
            if (mb_strlen($text) > $max_chars) {
                $text = mb_substr($text, 0, $max_chars) . '…';
            }
        } else {
            if (strlen($text) > $max_chars) {
                $text = substr($text, 0, $max_chars) . '…';
            }
        }
        
        return $text;
    }

    /**
     * Format timestamp for display
     */
    public static function format_time_ago($timestamp) : string {
        if (empty($timestamp)) return 'Never';
        
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins != 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
        } else {
            return date_i18n('M j, Y g:i a', $timestamp);
        }
    }

    /**
     * Get plugin info
     */
    public static function get_plugin_info() : array {
        return [
            'version' => AISS_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'openssl_available' => function_exists('openssl_encrypt'),
            'curl_available' => function_exists('curl_init'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];
    }

    /**
     * Admin notice helper
     */
    public static function show_admin_notice($message, $type = 'info') : void {
        $class = 'notice notice-' . $type;
        echo '<div class="' . esc_attr($class) . '"><p>' . wp_kses_post($message) . '</p></div>';
    }
}