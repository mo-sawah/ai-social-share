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
            'enable_web_search'  => false, // Restored feature

            // --- Scheduler ---
            'schedule_minutes'   => 30,
            'max_posts_per_run'  => 2,
            'filter_mode'        => 'all', // all, category, or tag
            'filter_terms'       => '',
            
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
        return update_option('aiss_settings', $merged, false);
    }

    public static function admin_url_settings(array $args = []) : string {
        $url = admin_url('options-general.php?page=ai-social-share');
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        return $url;
    }

    public static function encrypt(string $plain) : string {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) return $plain;
        
        $key = hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : ''), true);
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $stored) : string {
        if ($stored === '') return '';
        if (!function_exists('openssl_decrypt')) return $stored;
        
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 17) return $stored;
        
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : ''), true);
        
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }

    public static function clean_text(string $html, int $max_chars = 4000) : string {
        $text = wp_strip_all_tags($html, true);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        if (function_exists('mb_substr')) {
            if (mb_strlen($text) > $max_chars) $text = mb_substr($text, 0, $max_chars) . '…';
        } else {
            if (strlen($text) > $max_chars) $text = substr($text, 0, $max_chars) . '…';
        }
        return $text;
    }
}