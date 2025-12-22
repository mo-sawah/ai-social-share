<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Utils {

    public static function get_settings() : array {
        $defaults = [
            // AI
            'openrouter_api_key' => '',
            'openrouter_model'   => 'openai/gpt-4o-mini',
            'openrouter_site'    => home_url(),
            'openrouter_app'     => 'AI Social Share',
            'enable_web_search'  => false, // New Setting

            // Scheduler
            'schedule_minutes'   => 30,
            'max_posts_per_run'  => 2,
            'filter_mode'        => 'all',
            'filter_terms'       => '',
            'last_run_time'      => 0, // Track last run

            // Facebook
            'fb_app_id'          => '',
            'fb_app_secret'      => '',
            'fb_api_version'     => 'v24.0',
            'fb_page_id'         => '',
            'fb_page_name'       => '',
            'fb_page_token'      => '',
            'prompt_facebook'    => "You are a professional social media editor.\n\nWrite a Facebook post for this article.\n- Friendly and engaging tone.\n- 1–2 paragraphs.\n- 2–3 hashtags.\n- No links (link is attached).\n\nReturn ONLY the post text.",

            // X
            'x_enabled'          => false,
            'x_consumer_key'     => '',
            'x_consumer_secret'  => '',
            'x_access_token'     => '',
            'x_access_secret'    => '',
            'prompt_x'           => "You are a social media manager.\n\nWrite a Tweet for this article.\n- Under 200 chars.\n- Punchy and viral.\n- Max 2 hashtags.\n- No links.\n\nReturn ONLY the tweet text.",
        ];
        $saved = get_option('aiss_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    public static function update_settings(array $new) : bool {
        return update_option('aiss_settings', array_merge(self::get_settings(), $new));
    }

    public static function admin_url_settings(array $args = []) : string {
        $url = admin_url('options-general.php?page=ai-social-share');
        return $args ? add_query_arg($args, $url) : $url;
    }

    public static function encrypt(string $p) : string {
        if (!$p || !function_exists('openssl_encrypt')) return $p;
        $k = hash('sha256', (defined('AUTH_KEY')?AUTH_KEY:'').(defined('SECURE_AUTH_KEY')?SECURE_AUTH_KEY:''), true);
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($p, 'aes-256-cbc', $k, OPENSSL_RAW_DATA, $iv));
    }

    public static function decrypt(string $s) : string {
        if (!$s || !function_exists('openssl_decrypt')) return $s;
        $r = base64_decode($s, true);
        if ($r === false || strlen($r) < 17) return $s;
        $k = hash('sha256', (defined('AUTH_KEY')?AUTH_KEY:'').(defined('SECURE_AUTH_KEY')?SECURE_AUTH_KEY:''), true);
        return openssl_decrypt(substr($r, 16), 'aes-256-cbc', $k, OPENSSL_RAW_DATA, substr($r, 0, 16)) ?: '';
    }

    public static function clean_text(string $h, int $max = 4000) : string {
        $t = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($h, true)));
        return (strlen($t) > $max) ? substr($t, 0, $max) . '…' : $t;
    }
}