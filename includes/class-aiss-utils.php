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

            'filter_mode'        => 'all', // all|category|tag
            'filter_terms'       => '',    // comma-separated slugs

            'fb_app_id'          => '',
            'fb_app_secret'      => '',
            'fb_api_version'     => 'v24.0',
            'fb_page_id'         => '',
            'fb_page_name'       => '',
            'fb_page_token'      => '', // encrypted
            'fb_connected_at'    => 0,

            'prompt_facebook'    => self::default_prompt_facebook(),
        ];
        $saved = get_option('aiss_settings', []);
        if (!is_array($saved)) { $saved = []; }
        return array_merge($defaults, $saved);
    }

    public static function update_settings(array $new) : bool {
        $settings = self::get_settings();
        $merged = array_merge($settings, $new);
        return update_option('aiss_settings', $merged, false);
    }

    public static function admin_url_settings(array $args = []) : string {
        $url = admin_url('options-general.php?page=ai-social-share');
        if (!empty($args)) { $url = add_query_arg($args, $url); }
        return $url;
    }

    public static function default_prompt_facebook() : string {
        return "You are a professional social media editor.\n\nWrite a Facebook post that is clear, friendly, and fits best practices.\n- 1–2 short paragraphs.\n- Optional short hook question.\n- 1–3 hashtags at the end.\n- Light emoji use allowed but optional.\n- Must NOT invent facts. Use only the provided article text.\n- Include the URL exactly once.\n\nReturn ONLY the final post text.";
    }

    public static function encrypt(string $plain) : string {
        if ($plain === '') { return ''; }
        if (!function_exists('openssl_encrypt')) {
            // fallback: store plain (not ideal) but keeps plugin functional
            return $plain;
        }
        $key = hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : ''), true);
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $stored) : string {
        if ($stored === '') { return ''; }
        if (!function_exists('openssl_decrypt')) {
            return $stored;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 17) { return $stored; }
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

    public static function boolval($v) : bool {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
