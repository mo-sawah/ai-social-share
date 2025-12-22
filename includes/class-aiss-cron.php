<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Cron {
    const HOOK = 'aiss_cron_tick';

    private $openrouter;
    private $facebook;
    private $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(self::HOOK, [$this, 'run']);
        
        // Auto-reschedule if settings change
        add_action('update_option_aiss_settings', function() {
            self::ensure_scheduled(true);
        });
    }

    public static function ensure_scheduled($force = false) : void {
        $settings = Utils::get_settings();
        $minutes = max(5, (int)$settings['schedule_minutes']);
        $schedule_key = 'aiss_every_' . $minutes . '_minutes';

        if ($force) {
            self::clear_schedule();
        }

        if (!wp_next_scheduled(self::HOOK)) {
            // Force WP to refresh cron schedule cache
            delete_transient('cron_schedules');
            wp_schedule_event(time() + 60, $schedule_key, self::HOOK);
        }
    }
    
    public static function clear_schedule() : void {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function add_cron_schedules($schedules) {
        $minutes = max(5, (int)Utils::get_settings()['schedule_minutes']);
        $key = 'aiss_every_' . $minutes . '_minutes';
        
        $schedules[$key] = [
            'interval' => $minutes * 60,
            'display'  => "Every $minutes Minutes (AI Social Share)"
        ];
        return $schedules;
    }

    public function run() : void {
        $settings = Utils::get_settings();
        $fb_connected = $this->facebook->is_connected();
        $x_connected  = $this->x->is_connected();

        // If no networks are connected, abort.
        if (!$fb_connected && !$x_connected) {
            return;
        }

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int)$settings['max_posts_per_run'],
            'fields'         => 'ids',
            'date_query'     => [
                ['after' => '3 days ago'] // Optimization: don't scan ancient posts
            ]
        ];

        // Apply Filters
        if ($settings['filter_mode'] !== 'all' && !empty($settings['filter_terms'])) {
            $tax = $settings['filter_mode'] === 'category' ? 'category' : 'post_tag';
            $slugs = explode(',', $settings['filter_terms']);
            $args['tax_query'] = [
                [
                    'taxonomy' => $tax,
                    'field'    => 'slug',
                    'terms'    => array_map('trim', $slugs)
                ]
            ];
        }

        $posts = get_posts($args);

        foreach ($posts as $post_id) {
            $this->process_post($post_id, $settings);
        }
    }

    private function process_post($post_id, $settings) {
        // --- 1. Process Facebook ---
        if ($this->facebook->is_connected()) {
            $fb_done = get_post_meta($post_id, '_aiss_fb_shared_at', true);
            
            if (!$fb_done) {
                // Generate Content
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_facebook']);
                
                if ($gen['ok']) {
                    // Share
                    $res = $this->facebook->share_link_post($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_fb_shared_at', time());
                        update_post_meta($post_id, '_aiss_fb_remote_id', $res['id']);
                        // Save the text we actually sent
                        update_post_meta($post_id, '_aiss_fb_last_generated', $gen['text']); 
                    } else {
                        update_post_meta($post_id, '_aiss_fb_last_error', $res['error']);
                    }
                }
            }
        }

        // --- 2. Process X (Twitter) ---
        if ($this->x->is_connected()) {
            $x_done = get_post_meta($post_id, '_aiss_x_shared_at', true);
            
            if (!$x_done) {
                // Generate Content (Using separate X Prompt)
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_x']);
                
                if ($gen['ok']) {
                    // Share
                    $res = $this->x->post_tweet($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_x_shared_at', time());
                        update_post_meta($post_id, '_aiss_x_remote_id', $res['id']);
                        update_post_meta($post_id, '_aiss_x_last_generated', $gen['text']); 
                    } else {
                        update_post_meta($post_id, '_aiss_x_last_error', $res['error']);
                    }
                }
            }
        }
    }
}