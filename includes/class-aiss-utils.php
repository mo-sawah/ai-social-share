<?php
namespace AISS;
if (!defined('ABSPATH')) { exit; }

final class Utils {
    public static function get_settings() : array {
        $defaults = [
            'openrouter_api_key' => '', 'openrouter_model' => 'openai/gpt-4o-mini', 'openrouter_site' => home_url(), 'openrouter_app' => 'AI Social Share', 'enable_web_search' => false,
            'schedule_minutes' => 30, 'max_posts_per_run' => 2, 'filter_mode' => 'all', 'filter_terms' => '',
            'fb_app_id' => '', 'fb_app_secret' => '', 'fb_api_version' => 'v24.0', 'fb_page_id' => '', 'fb_page_name' => '', 'fb_page_token' => '', 'prompt_facebook' => "Write a Facebook post...",
            'x_enabled' => false, 
            'x_consumer_key' => '', 'x_consumer_secret' => '', // APP Keys
            'x_access_token' => '', 'x_access_secret' => '',   // USER Tokens
            'x_username' => '', // NEW: To show "@MyClient"
            'prompt_x' => "Write a Tweet..."
        ];
        $saved = get_option('aiss_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }
    public static function update_settings(array $new) : bool { return update_option('aiss_settings', array_merge(self::get_settings(), $new)); }
    public static function admin_url_settings(array $args=[]) : string { $u=admin_url('options-general.php?page=ai-social-share'); return $args?add_query_arg($args,$u):$u; }
    public static function clean_text(string $h, int $m=4000) : string { $t=trim(preg_replace('/\s+/',' ',wp_strip_all_tags($h,true))); return strlen($t)>$m?substr($t,0,$m).'…':$t; }
}