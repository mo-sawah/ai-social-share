<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

/**
 * Reliable Scheduler using Action Scheduler
 * 
 * Action Scheduler is the same library used by WooCommerce for background processing.
 * It's much more reliable than WP-Cron because:
 * - Uses database queue (doesn't rely on site visits)
 * - Automatic retry on failure
 * - Better logging
 * - Self-healing
 */
final class Scheduler {
    
    private $openrouter;
    private $facebook;
    private $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;

        // Initialize Action Scheduler
        add_action('plugins_loaded', [$this, 'init_action_scheduler']);
        
        // Register our recurring action
        add_action('aiss_process_queue', [$this, 'process_queue']);
        
        // Share on publish (immediate option)
        add_action('transition_post_status', [$this, 'share_on_publish'], 10, 3);
        
        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    /**
     * Initialize Action Scheduler
     */
    public function init_action_scheduler() {
        // Check if Action Scheduler is already loaded (by WooCommerce or another plugin)
        if (!class_exists('ActionScheduler')) {
            // Load our bundled copy
            require_once AISS_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
        }
        
        // Ensure our recurring action is scheduled
        $this->ensure_scheduled();
    }

    /**
     * Ensure Action Scheduler task is scheduled
     */
    public function ensure_scheduled() {
        $settings = Utils::get_settings();
        $interval = max(5, (int)$settings['schedule_minutes']) * 60; // Convert to seconds
        
        // Check if already scheduled
        if (!as_next_scheduled_action('aiss_process_queue')) {
            // Schedule recurring action
            as_schedule_recurring_action(time() + 60, $interval, 'aiss_process_queue', [], 'aiss');
            
            self::log('Action Scheduler: Scheduled recurring task', 'info');
        } else {
            // Check if interval changed
            $next = as_next_scheduled_action('aiss_process_queue');
            $args = as_get_scheduled_actions([
                'hook' => 'aiss_process_queue',
                'status' => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1
            ]);
            
            if (!empty($args)) {
                $action = reset($args);
                $schedule = $action->get_schedule();
                if (method_exists($schedule, 'get_recurrence')) {
                    $current_interval = $schedule->get_recurrence();
                    
                    // If interval changed, reschedule
                    if ($current_interval !== $interval) {
                        $this->clear_scheduled();
                        as_schedule_recurring_action(time() + 60, $interval, 'aiss_process_queue', [], 'aiss');
                        self::log('Action Scheduler: Rescheduled with new interval', 'info');
                    }
                }
            }
        }
    }

    /**
     * Clear scheduled actions
     */
    public function clear_scheduled() {
        as_unschedule_all_actions('aiss_process_queue', [], 'aiss');
        self::log('Action Scheduler: Cleared all scheduled tasks', 'info');
    }

    /**
     * Process the queue (main execution)
     */
    public function process_queue() {
        $start_time = microtime(true);
        $run_id = uniqid('run_');
        
        self::log("=== QUEUE PROCESS START [{$run_id}] ===");
        
        try {
            // Update last run time
            update_option('aiss_last_run_time', time(), false);
            
            $settings = Utils::get_settings();
            $fb_connected = $this->facebook->is_connected();
            $x_connected  = $this->x->is_connected();

            // If no networks connected, skip
            if (!$fb_connected && !$x_connected) {
                self::log('No networks connected - skipping', 'info');
                return;
            }

            self::log("Networks: FB=" . ($fb_connected ? 'YES' : 'NO') . ", X=" . ($x_connected ? 'YES' : 'NO'));

            // Build query
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => (int)$settings['max_posts_per_run'],
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'date_query'     => [
                    ['after' => '7 days ago']
                ]
            ];

            // Apply filters
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

            self::log('Querying posts with args: ' . json_encode($args));
            $posts = get_posts($args);
            $total_posts = count($posts);
            
            self::log("Found {$total_posts} posts to process");

            if ($total_posts === 0) {
                self::log('No posts found matching criteria');
                return;
            }

            $processed = 0;
            $shared = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($posts as $post_id) {
                // Timeout protection
                if ((microtime(true) - $start_time) > 45) {
                    self::log('Timeout protection triggered - stopping batch', 'warning');
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
            self::log('Stack trace: ' . $e->getTraceAsString(), 'error');
        }
    }

    /**
     * Share on publish (immediate sharing)
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
        
        // Check if share on publish is enabled
        $settings = Utils::get_settings();
        if (empty($settings['share_on_publish'])) {
            return;
        }
        
        self::log("Share on publish triggered for post #{$post->ID}: " . get_the_title($post->ID));
        
        // Schedule immediate action (runs in background)
        as_enqueue_async_action('aiss_share_single_post', ['post_id' => $post->ID], 'aiss');
    }

    /**
     * Process single post (called by action hook)
     */
    public function process_single_post($post_id) {
        $settings = Utils::get_settings();
        
        self::log("Processing single post #{$post_id}: " . get_the_title($post_id));
        
        $result = $this->process_post($post_id, $settings);
        
        self::log("Single post complete: Shared={$result['shared']}, Skipped={$result['skipped']}, Errors={$result['errors']}");
    }

    /**
     * Process a single post (for both queue and on-publish)
     */
    private function process_post($post_id, $settings = null) {
        if (!$settings) {
            $settings = Utils::get_settings();
        }
        
        $result = ['shared' => 0, 'skipped' => 0, 'errors' => 0];
        
        self::log("Processing post #{$post_id}: " . get_the_title($post_id));
        
        // --- Facebook ---
        if ($this->facebook->is_connected()) {
            $fb_done = get_post_meta($post_id, '_aiss_fb_shared_at', true);
            
            if ($fb_done) {
                self::log("  FB: Already shared");
                $result['skipped']++;
            } else {
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_facebook']);
                
                if ($gen['ok']) {
                    self::log("  FB: AI generated text (" . strlen($gen['text']) . " chars)");
                    
                    $res = $this->facebook->share_link_post($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_fb_shared_at', time());
                        update_post_meta($post_id, '_aiss_fb_remote_id', $res['id']);
                        update_post_meta($post_id, '_aiss_fb_last_generated', $gen['text']);
                        delete_post_meta($post_id, '_aiss_fb_last_error');
                        
                        self::log("  FB: ✓ Shared successfully (ID: {$res['id']})");
                        $result['shared']++;
                    } else {
                        update_post_meta($post_id, '_aiss_fb_last_error', $res['error']);
                        self::log("  FB: ✗ Share failed: {$res['error']}", 'error');
                        $result['errors']++;
                    }
                } else {
                    update_post_meta($post_id, '_aiss_fb_last_error', $gen['error']);
                    self::log("  FB: ✗ AI generation failed: {$gen['error']}", 'error');
                    $result['errors']++;
                }
            }
        }

        // --- Delay ---
        if ($this->facebook->is_connected() && $this->x->is_connected()) {
            $delay = apply_filters('aiss_platform_delay', 15);
            self::log("  Waiting {$delay}s before X...");
            sleep($delay);
        }

        // --- X (Twitter) ---
        if ($this->x->is_connected()) {
            $x_done = get_post_meta($post_id, '_aiss_x_shared_at', true);
            
            if ($x_done) {
                self::log("  X: Already shared");
                $result['skipped']++;
            } else {
                $gen = $this->openrouter->generate_post($post_id, $settings['prompt_x']);
                
                if ($gen['ok']) {
                    self::log("  X: AI generated text (" . strlen($gen['text']) . " chars)");
                    
                    $res = $this->x->post_tweet($post_id, $gen['text']);
                    
                    if ($res['ok']) {
                        update_post_meta($post_id, '_aiss_x_shared_at', time());
                        update_post_meta($post_id, '_aiss_x_remote_id', $res['id']);
                        update_post_meta($post_id, '_aiss_x_last_generated', $gen['text']);
                        delete_post_meta($post_id, '_aiss_x_last_error');
                        
                        self::log("  X: ✓ Shared successfully (ID: {$res['id']})");
                        $result['shared']++;
                    } else {
                        update_post_meta($post_id, '_aiss_x_last_error', $res['error']);
                        self::log("  X: ✗ Share failed: {$res['error']}", 'error');
                        $result['errors']++;
                    }
                } else {
                    update_post_meta($post_id, '_aiss_x_last_error', $gen['error']);
                    self::log("  X: ✗ AI generation failed: {$gen['error']}", 'error');
                    $result['errors']++;
                }
            }
        }
        
        return $result;
    }

    /**
     * Admin notices
     */
    public function admin_notices() {
        // Check if Action Scheduler is having issues
        if (class_exists('ActionScheduler_Store')) {
            $failed_actions = as_get_scheduled_actions([
                'hook' => 'aiss_process_queue',
                'status' => ActionScheduler_Store::STATUS_FAILED,
                'per_page' => 1
            ]);
            
            if (!empty($failed_actions)) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>AI Social Share:</strong> Background task failed. ';
                echo '<a href="' . admin_url('tools.php?page=action-scheduler&s=aiss_process_queue') . '">View logs</a> or ';
                echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_reset_scheduler'), 'aiss_reset_scheduler') . '">Reset Scheduler</a>';
                echo '</p></div>';
            }
        }
    }

    /**
     * Get scheduler status
     */
    public static function get_status() : array {
        if (!function_exists('as_next_scheduled_action')) {
            return [
                'active' => false,
                'next_run' => null,
                'last_run' => (int)get_option('aiss_last_run_time', 0),
                'error' => 'Action Scheduler not loaded'
            ];
        }
        
        $next = as_next_scheduled_action('aiss_process_queue');
        $last = (int)get_option('aiss_last_run_time', 0);
        
        return [
            'active' => ($next !== false),
            'next_run' => $next ? $next : null,
            'last_run' => $last,
            'pending_count' => self::get_pending_count(),
            'failed_count' => self::get_failed_count()
        ];
    }

    /**
     * Get pending actions count
     */
    private static function get_pending_count() : int {
        if (!class_exists('ActionScheduler_Store')) {
            return 0;
        }
        
        $actions = as_get_scheduled_actions([
            'hook' => 'aiss_process_queue',
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100
        ]);
        
        return count($actions);
    }

    /**
     * Get failed actions count
     */
    private static function get_failed_count() : int {
        if (!class_exists('ActionScheduler_Store')) {
            return 0;
        }
        
        $actions = as_get_scheduled_actions([
            'hook' => 'aiss_process_queue',
            'status' => ActionScheduler_Store::STATUS_FAILED,
            'per_page' => 100
        ]);
        
        return count($actions);
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