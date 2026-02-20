<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

/**
 * Simple Reliable Scheduler
 * 
 * Uses multiple approaches for maximum reliability:
 * 1. Share on Publish (instant)
 * 2. Admin heartbeat (runs when admin is active)
 * 3. Database-tracked queue (doesn't rely on WP-Cron)
 */
final class SimpleScheduler {
    
    private $openrouter;
    private $facebook;
    private $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;

        // Method 1: Share immediately on publish
        add_action('transition_post_status', [$this, 'share_on_publish'], 10, 3);
        
        // Method 2: Admin heartbeat check (every 60 seconds when admin is active)
        add_action('admin_init', [$this, 'admin_heartbeat_check']);
        
        // Method 3: Frontend check (every page load, throttled)
        add_action('init', [$this, 'frontend_check'], 1);
        
        // Method 4: Traditional WP-Cron as backup
        add_action('aiss_simple_cron', [$this, 'process_queue']);
        
        // Register custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        
        // Ensure cron is scheduled
        $this->ensure_scheduled();
    }

    /**
     * Add custom cron schedule - uses dynamic interval from settings
     */
    public function add_cron_schedule($schedules) {
        $settings = Utils::get_settings();
        $minutes  = max(5, (int)$settings['schedule_minutes']);
        $interval = $minutes * 60;

        $schedules['aiss_dynamic_interval'] = [
            'interval' => $interval,
            'display'  => "Every {$minutes} Minutes (AI Social Share)"
        ];

        // Keep the old key around so any previously-registered event still
        // has a valid schedule and can be unscheduled cleanly.
        $schedules['aiss_every_30min'] = [
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (AI Social Share - legacy)'
        ];

        return $schedules;
    }
    
    /**
     * Ensure cron is scheduled - always uses current settings interval
     */
    public function ensure_scheduled($force = false) {
        if ($force) {
            $this->clear_scheduled();
        }

        if (!wp_next_scheduled('aiss_simple_cron')) {
            // Re-read settings so we always use the latest saved value
            $settings = Utils::get_settings();
            $minutes  = max(5, (int)$settings['schedule_minutes']);
            wp_schedule_event(time() + 60, 'aiss_dynamic_interval', 'aiss_simple_cron');
            self::log("Cron scheduled successfully (every {$minutes} minutes)");
        }
    }
    
    /**
     * Clear scheduled events
     */
    public function clear_scheduled() {
        $timestamp = wp_next_scheduled('aiss_simple_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'aiss_simple_cron');
            self::log('Cron unscheduled');
        }
    }

    /**
     * Method 1: Share on publish (INSTANT)
     */
    public function share_on_publish($new_status, $old_status, $post) {
        // Only trigger on new publications
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        
        // Only for posts
        if ($post->post_type !== 'post') {
            return;
        }
        
        $settings = Utils::get_settings();
        
        // Check if share on publish is enabled
        if (empty($settings['share_on_publish'])) {
            return;
        }
        
        self::log("Share on publish: Processing post #{$post->ID}");
        
        // Process immediately (with slight delay to allow post meta to save)
        wp_schedule_single_event(time() + 10, 'aiss_process_single_post', [$post->ID]);
        
        // Also try to run it right away in case cron fails
        $this->process_single_post($post->ID);
    }

    /**
     * Method 2: Admin heartbeat (runs when admin is active)
     */
    public function admin_heartbeat_check() {
        // Only run once per minute
        $last_check = (int)get_transient('aiss_admin_heartbeat');
        if ($last_check && time() - $last_check < 60) {
            return;
        }
        
        set_transient('aiss_admin_heartbeat', time(), 120);
        
        // Check if queue needs processing
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $settings = Utils::get_settings();
        $interval = max(5, (int)$settings['schedule_minutes']) * 60;
        
        // If it's been longer than the interval, process queue
        if (time() - $last_run >= $interval) {
            self::log("Admin heartbeat: Triggering queue processing");
            $this->process_queue();
        }
    }

    /**
     * Method 3: Frontend check (throttled heavily)
     */
    public function frontend_check() {
        // Only run once every 5 minutes max
        $last_check = (int)get_transient('aiss_frontend_check');
        if ($last_check) {
            return;
        }
        
        set_transient('aiss_frontend_check', time(), 300); // 5 minutes
        
        // Check if queue needs processing
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $settings = Utils::get_settings();
        $interval = max(5, (int)$settings['schedule_minutes']) * 60;
        
        // If it's been way longer than the interval (2x), process queue
        if (time() - $last_run >= ($interval * 2)) {
            self::log("Frontend check: Triggering overdue queue processing");
            // Spawn async request to not slow down page load
            $this->spawn_async_process();
        }
    }

    /**
     * Spawn async process (doesn't block page load)
     */
    private function spawn_async_process() {
        $url = admin_url('admin-ajax.php?action=aiss_async_process');
        
        wp_remote_post($url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'body' => ['security' => wp_create_nonce('aiss_async')],
        ]);
    }

    /**
     * Handle async process
     */
    public static function handle_async_process() {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'aiss_async')) {
            wp_die('Invalid request');
        }
        
        // Run queue processing
        $instance = new self(
            new OpenRouter(),
            new Facebook(),
            new X()
        );
        $instance->process_queue();
        
        wp_die(); // Required for AJAX
    }

    /**
     * Process single post (for share on publish)
     */
    public function process_single_post($post_id) {
        $settings = Utils::get_settings();
        
        self::log("Processing single post #{$post_id}");
        
        $result = $this->process_post($post_id, $settings);
        
        self::log("Single post result: Shared={$result['shared']}, Skipped={$result['skipped']}, Errors={$result['errors']}");
    }

    /**
     * Main queue processing
     */
    public function process_queue() {
        // Prevent concurrent runs
        $lock = get_transient('aiss_processing_lock');
        if ($lock) {
            self::log("Queue processing already running - skipping");
            return;
        }
        
        set_transient('aiss_processing_lock', time(), 300); // 5-minute lock
        
        $start_time = microtime(true);
        $run_id = uniqid('run_');
        
        self::log("=== QUEUE PROCESS START [{$run_id}] ===");
        
        try {
            // Update last run time
            update_option('aiss_last_run_time', time(), false);
            
            $settings = Utils::get_settings();
            $fb_connected = $this->facebook->is_connected();
            $x_connected  = $this->x->is_connected();

            if (!$fb_connected && !$x_connected) {
                self::log('No networks connected - skipping');
                return;
            }

            self::log("Networks: FB=" . ($fb_connected ? 'YES' : 'NO') . ", X=" . ($x_connected ? 'YES' : 'NO'));

            // Build query with improved date filter
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => (int)$settings['max_posts_per_run'],
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            // FIX 5: Apply post age filter
            $post_age = $settings['filter_post_age'] ?? '7d';
            $date_query = $this->get_date_query_for_age($post_age);
            if ($date_query) {
                $args['date_query'] = [$date_query];
            }

            // Apply category/tag filters
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
            $total_posts = count($posts);
            
            self::log("Found {$total_posts} posts to process (Age filter: {$post_age})");

            if ($total_posts === 0) {
                return;
            }

            $processed = 0;
            $shared = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($posts as $post_id) {
                if ((microtime(true) - $start_time) > 45) {
                    self::log('Timeout protection - stopping', 'warning');
                    break;
                }
                
                $result = $this->process_post($post_id, $settings);
                $processed++;
                $shared += $result['shared'];
                $skipped += $result['skipped'];
                $errors += $result['errors'];
            }

            $duration = round(microtime(true) - $start_time, 2);
            
            // Update stats
            $stats = [
                'last_run' => time(),
                'duration' => $duration,
                'processed' => $processed,
                'shared' => $shared,
                'skipped' => $skipped,
                'errors' => $errors
            ];
            update_option('aiss_last_run_stats', $stats, false);
            
            self::log("=== QUEUE PROCESS COMPLETE [{$run_id}] ===");
            self::log("Duration: {$duration}s | Processed: {$processed} | Shared: {$shared} | Skipped: {$skipped} | Errors: {$errors}");
            
        } catch (\Exception $e) {
            self::log('FATAL ERROR: ' . $e->getMessage(), 'error');
        } finally {
            delete_transient('aiss_processing_lock');
        }
    }
    
    /**
     * Get date query based on age filter
     */
    private function get_date_query_for_age($age) {
        switch ($age) {
            case '24h':
                return ['after' => '24 hours ago'];
            case '48h':
                return ['after' => '48 hours ago'];
            case '7d':
                return ['after' => '7 days ago'];
            case '30d':
                return ['after' => '30 days ago'];
            case 'all':
            default:
                return null;
        }
    }

    /**
     * Process a single post
     */
    private function process_post($post_id, $settings) {
        $result = ['shared' => 0, 'skipped' => 0, 'errors' => 0];
        
        self::log("Processing post #{$post_id}: " . get_the_title($post_id));
        
        // Facebook
        if ($this->facebook->is_connected()) {
            $fb_done = get_post_meta($post_id, '_aiss_fb_shared_at', true);
            
            if ($fb_done) {
                $result['skipped']++;
            } else {
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_facebook']);
                
                if ($gen['ok']) {
                    $res = $this->facebook->share_link_post($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_fb_shared_at', time());
                        update_post_meta($post_id, '_aiss_fb_remote_id', $res['id']);
                        update_post_meta($post_id, '_aiss_fb_last_generated', $gen['text']);
                        delete_post_meta($post_id, '_aiss_fb_last_error');
                        self::log("  FB: ✓ Shared (ID: {$res['id']})");
                        $result['shared']++;
                    } else {
                        update_post_meta($post_id, '_aiss_fb_last_error', $res['error']);
                        self::log("  FB: ✗ Failed: {$res['error']}", 'error');
                        $result['errors']++;
                    }
                } else {
                    update_post_meta($post_id, '_aiss_fb_last_error', $gen['error']);
                    self::log("  FB: ✗ AI failed: {$gen['error']}", 'error');
                    $result['errors']++;
                }
            }
        }

        // Delay between platforms
        if ($this->facebook->is_connected() && $this->x->is_connected()) {
            sleep(15);
        }

        // X (Twitter)
        if ($this->x->is_connected()) {
            $x_done = get_post_meta($post_id, '_aiss_x_shared_at', true);
            
            if ($x_done) {
                $result['skipped']++;
            } else {
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_x']);
                
                if ($gen['ok']) {
                    $res = $this->x->post_tweet($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_x_shared_at', time());
                        update_post_meta($post_id, '_aiss_x_remote_id', $res['id']);
                        update_post_meta($post_id, '_aiss_x_last_generated', $gen['text']);
                        delete_post_meta($post_id, '_aiss_x_last_error');
                        self::log("  X: ✓ Shared (ID: {$res['id']})");
                        $result['shared']++;
                    } else {
                        update_post_meta($post_id, '_aiss_x_last_error', $res['error']);
                        self::log("  X: ✗ Failed: {$res['error']}", 'error');
                        $result['errors']++;
                    }
                } else {
                    update_post_meta($post_id, '_aiss_x_last_error', $gen['error']);
                    self::log("  X: ✗ AI failed: {$gen['error']}", 'error');
                    $result['errors']++;
                }
            }
        }
        
        return $result;
    }

    /**
     * Get status
     */
    public static function get_status() : array {
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $next_cron = wp_next_scheduled('aiss_simple_cron');
        
        return [
            'active' => ($next_cron !== false || $last_run > (time() - 3600)),
            'next_run' => $next_cron,
            'last_run' => $last_run,
            'methods' => [
                'share_on_publish' => Utils::get_settings()['share_on_publish'] ?? false,
                'admin_heartbeat' => true,
                'wp_cron' => ($next_cron !== false),
            ]
        ];
    }

    /**
     * Logging
     */
    public static function log($message, $level = 'info') : void {
        $settings = Utils::get_settings();
        
        if (empty($settings['enable_debug_logs']) && $level !== 'error') {
            return;
        }
        
        $timestamp = current_time('mysql');
        $entry = "[{$timestamp}] [{$level}] {$message}\n";
        
        $log = get_option('aiss_debug_log', '');
        $lines = explode("\n", $log);
        $lines[] = $entry;
        $lines = array_slice($lines, -100);
        update_option('aiss_debug_log', implode("\n", $lines), false);
        
        if ($level === 'error' && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AI Social Share: {$message}");
        }
    }

    /**
     * Clear logs
     */
    public static function clear_log() : void {
        delete_option('aiss_debug_log');
        self::log('Debug log cleared');
    }
}