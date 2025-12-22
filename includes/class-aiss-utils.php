<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Utils {

    public static function get_settings() : array {
        $defaults = [
            'openrouter_api_key' => '',
            'openrouter_model'   => 'openai/gpt-4o-mini',
            'openrouter_site'    => home_url(),
            'openrouter_app'     => 'AI Social Share',

            'schedule_minutes'   => 30,
            'max_posts_per_run'  => 2,

            'filter_mode'        => 'all',
            'filter_terms'       => '',

            // Facebook
            'fb_app_id'          => '',
            'fb_app_secret'      => '',
            'fb_api_version'     => 'v24.0',
            'fb_page_id'         => '',
            'fb_page_name'       => '',
            'fb_page_token'      => '', 
            'fb_connected_at'    => 0,
            'prompt_facebook'    => "You are a professional social media editor.\n\nWrite a Facebook post that is clear, friendly, and fits best practices.\n- 1–2 short paragraphs.\n- Optional short hook question.\n- 1–3 hashtags at the end.\n- Light emoji use allowed.\n- Do NOT include the link (it is attached automatically).\n\nReturn ONLY the post text.",

            // X (Twitter)
            'x_enabled'          => false,
            'x_consumer_key'     => '',
            'x_consumer_secret'  => '',
            'x_access_token'     => '',
            'x_access_secret'    => '',
            'prompt_x'           => "You are a social media manager.\n\nWrite a Tweet (X post) for this article.\n- Must be under 200 characters.\n- Engaging and punchy.\n- Max 2 hashtags.\n- No links (link is attached automatically).\n\nReturn ONLY the tweet text.",
        ];
        $saved = get_option('aiss_settings', []);
        if (!is_array($saved)) { $saved = []; }
        return array_merge($defaults, $saved);
    }

    public static function update_settings(array $new) : bool {
        $merged = array_merge(self::get_settings(), $new);
        return update_option('aiss_settings', $merged, false);
    }

    public static function admin_url_settings(array $args = []) : string {
        $url = admin_url('options-general.php?page=ai-social-share');
        return !empty($args) ? add_query_arg($args, $url) : $url;
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
        if (strlen($text) > $max_chars) $text = substr($text, 0, $max_chars) . '…';
        return $text;
    }
}