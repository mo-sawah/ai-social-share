<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Cron {

    const HOOK = 'aiss_cron_tick';

    /** @var OpenRouter */
    private $openrouter;

    /** @var Facebook */
    private $facebook;

    public function __construct(OpenRouter $openrouter, Facebook $facebook) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(self::HOOK, [$this, 'run']);

        // Reschedule if settings change
        add_action('update_option_aiss_settings', function($old, $new) {
            self::ensure_scheduled(true);
        }, 10, 2);
    }

    public static function ensure_scheduled(bool $force_reschedule = false) : void {
        $settings = Utils::get_settings();
        $minutes = max(5, (int)$settings['schedule_minutes']);
        $schedule_key = 'aiss_every_' . $minutes . '_minutes';

        if ($force_reschedule) {
            self::clear_schedule();
        }

        if (!wp_next_scheduled(self::HOOK)) {
            // FIX: Force WP to refresh the list of allowed schedules (intervals)
            // This ensures the new '5 minute' interval is recognized immediately.
            delete_transient('cron_schedules'); // Clear cache
            
            // Re-register the schedule manually for this request to be safe
            add_filter('cron_schedules', [__CLASS__, 'static_add_schedule_fallback']);

            wp_schedule_event(time() + 60, $schedule_key, self::HOOK);
        }
    }

    public static function static_add_schedule_fallback($schedules) {
        $settings = Utils::get_settings();
        $minutes = max(5, (int)$settings['schedule_minutes']);
        $key = 'aiss_every_' . $minutes . '_minutes';
        if (!isset($schedules[$key])) {
            $schedules[$key] = [
                'interval' => $minutes * MINUTE_IN_SECONDS,
                'display'  => sprintf('AI Social Share: Every %d minutes', $minutes),
            ];
        }
        return $schedules;
    }

    public static function clear_schedule() : void {
        $ts = wp_next_scheduled(self::HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::HOOK);
            $ts = wp_next_scheduled(self::HOOK);
        }
    }

    public function add_cron_schedules(array $schedules) : array {
        return self::static_add_schedule_fallback($schedules);
    }

    public function run() : void {
        $settings = Utils::get_settings();

        // Safety checks
        if (!$this->facebook->is_connected()) { return; }
        if (trim((string)$settings['openrouter_api_key']) === '') { return; }

        $max = max(1, (int)$settings['max_posts_per_run']);

        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $max,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_aiss_fb_shared_at',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ];

        // Apply Filters
        $mode = (string)$settings['filter_mode'];
        $terms_raw = trim((string)$settings['filter_terms']);
        if ($terms_raw !== '' && in_array($mode, ['category','tag'], true)) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', $terms_raw))));
            if (!empty($slugs)) {
                $tax = $mode === 'category' ? 'category' : 'post_tag';
                $args['tax_query'] = [
                    [
                        'taxonomy' => $tax,
                        'field' => 'slug',
                        'terms' => $slugs,
                    ]
                ];
            }
        }

        $q = new \WP_Query($args);
        if (empty($q->posts)) { return; }

        foreach ($q->posts as $post_id) {
            $this->process_post((int)$post_id);
        }
    }

    public function process_post(int $post_id) : array {
        // Double-check to prevent duplicates
        $already = (int)get_post_meta($post_id, '_aiss_fb_shared_at', true);
        if ($already > 0) {
            return ['ok' => true, 'skipped' => true];
        }

        // 1. Generate Content
        $gen = $this->openrouter->generate_facebook_post($post_id);
        if (!$gen['ok']) {
            update_post_meta($post_id, '_aiss_fb_last_error', sanitize_text_field((string)$gen['error']));
            return ['ok' => false, 'error' => (string)$gen['error']];
        }

        $message = (string)$gen['text'];
        update_post_meta($post_id, '_aiss_fb_last_generated', wp_kses_post($message));

        // 2. Share to Facebook
        $share = $this->facebook->share_link_post($post_id, $message);
        if (!$share['ok']) {
            update_post_meta($post_id, '_aiss_fb_last_error', sanitize_text_field((string)$share['error']));
            return ['ok' => false, 'error' => (string)$share['error']];
        }

        // 3. Success
        update_post_meta($post_id, '_aiss_fb_shared_at', time());
        if (!empty($share['id'])) {
            update_post_meta($post_id, '_aiss_fb_remote_id', sanitize_text_field((string)$share['id']));
        }
        delete_post_meta($post_id, '_aiss_fb_last_error');

        return ['ok' => true, 'id' => $share['id'] ?? ''];
    }
}