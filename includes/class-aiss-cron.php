<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Cron {
    const HOOK = 'aiss_cron_tick';
    private $openrouter, $facebook, $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(self::HOOK, [$this, 'run']);
        add_action('update_option_aiss_settings', function() { self::ensure_scheduled(true); });
    }

    public static function ensure_scheduled($force = false) : void {
        $min = max(5, (int)Utils::get_settings()['schedule_minutes']);
        if ($force) self::clear_schedule();
        if (!wp_next_scheduled(self::HOOK)) {
            delete_transient('cron_schedules'); 
            wp_schedule_event(time() + 60, 'aiss_every_'.$min.'_minutes', self::HOOK);
        }
    }
    
    public static function clear_schedule() : void { wp_clear_scheduled_hook(self::HOOK); }

    public function add_cron_schedules($s) {
        $min = max(5, (int)Utils::get_settings()['schedule_minutes']);
        $s['aiss_every_'.$min.'_minutes'] = ['interval' => $min * 60, 'display' => "Every $min Min"];
        return $s;
    }

    public function run() : void {
        $s = Utils::get_settings();
        $fb_on = $this->facebook->is_connected();
        $x_on  = $this->x->is_connected();

        if (!$fb_on && !$x_on) return;

        // Query posts that MIGHT need sharing (published recently)
        // We do not filter by meta NOT EXISTS here because we have 2 separate flags now.
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => (int)$s['max_posts_per_run'],
            'date_query' => [['after' => '2 days ago']], // Only look at recent posts
            'fields' => 'ids',
        ];

        // Apply category filters if set
        if ($s['filter_mode'] !== 'all' && !empty($s['filter_terms'])) {
            $args['tax_query'] = [[
                'taxonomy' => $s['filter_mode'] === 'category' ? 'category' : 'post_tag',
                'field' => 'slug',
                'terms' => explode(',', $s['filter_terms'])
            ]];
        }

        $posts = get_posts($args);
        foreach ($posts as $pid) {
            $this->process_post($pid, $s);
        }
    }

    private function process_post($pid, $s) {
        // --- FACEBOOK ---
        $fb_done = get_post_meta($pid, '_aiss_fb_shared_at', true);
        if (!$fb_done && $this->facebook->is_connected()) {
            $gen = $this->openrouter->generate_post($pid, $s['prompt_facebook']);
            if ($gen['ok']) {
                $res = $this->facebook->share_link_post($pid, $gen['text']);
                if ($res['ok']) {
                    update_post_meta($pid, '_aiss_fb_shared_at', time());
                    update_post_meta($pid, '_aiss_fb_remote_id', $res['id']);
                }
            }
        }

        // --- X (TWITTER) ---
        $x_done = get_post_meta($pid, '_aiss_x_shared_at', true);
        if (!$x_done && $this->x->is_connected()) {
            $gen = $this->openrouter->generate_post($pid, $s['prompt_x']);
            if ($gen['ok']) {
                $res = $this->x->post_tweet($pid, $gen['text']);
                if ($res['ok']) {
                    update_post_meta($pid, '_aiss_x_shared_at', time());
                    update_post_meta($pid, '_aiss_x_remote_id', $res['id']);
                }
            }
        }
    }
}