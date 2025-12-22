<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Cron {
    const HOOK = 'aiss_cron_tick';
    const FALLBACK_HOOK = 'aiss_fallback_check';
    
    // Fixed schedule intervals (more reliable than dynamic)
    const SCHEDULE_5MIN = 'aiss_5min';
    const SCHEDULE_15MIN = 'aiss_15min';
    const SCHEDULE_30MIN = 'aiss_30min';
    const SCHEDULE_60MIN = 'aiss_60min';

    private $openrouter;
    private $facebook;
    private $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(self::HOOK, [$this, 'run']);
        add_action(self::FALLBACK_HOOK, [$this, 'fallback_check']);
        
        // Fallback: Check on admin_init if cron hasn't run recently
        add_action('admin_init', [$this, 'emergency_fallback']);
        
        // Auto-reschedule if settings change
        add_action('update_option_aiss_settings', function() {
            self::ensure_scheduled(true);
        });
    }

    /**
     * Add fixed cron schedules (more reliable than dynamic)
     */
    public function add_cron_schedules($schedules) {
        $schedules[self::SCHEDULE_5MIN] = [
            'interval' => 5 * 60,
            'display'  => 'Every 5 Minutes (AI Social Share)'
        ];
        $schedules[self::SCHEDULE_15MIN] = [
            'interval' => 15 * 60,
            'display'  => 'Every 15 Minutes (AI Social Share)'
        ];
        $schedules[self::SCHEDULE_30MIN] = [
            'interval' => 30 * 60,
            'display'  => 'Every 30 Minutes (AI Social Share)'
        ];
        $schedules[self::SCHEDULE_60MIN] = [
            'interval' => 60 * 60,
            'display'  => 'Every Hour (AI Social Share)'
        ];
        return $schedules;
    }

    /**
     * Ensure the cron is scheduled properly
     */
    public static function ensure_scheduled($force = false) : void {
        $settings = Utils::get_settings();
        $minutes = max(5, (int)$settings['schedule_minutes']);
        
        // Map to fixed schedule
        $schedule_key = self::get_schedule_key($minutes);
        
        $timestamp = wp_next_scheduled(self::HOOK);
        $current_time = time();

        // Need to reschedule if:
        // 1. Forced
        // 2. Not scheduled at all
        // 3. Schedule is in the past (stuck/missed)
        // 4. Next run is too far in the future (wrong interval)
        $needs_reschedule = false;
        
        if ($force) {
            self::log('Force rescheduling requested');
            $needs_reschedule = true;
        } elseif (!$timestamp) {
            self::log('No schedule found - creating new');
            $needs_reschedule = true;
        } elseif ($timestamp < ($current_time - 600)) {
            self::log('Schedule is stuck in the past - rescheduling');
            $needs_reschedule = true;
        } else {
            // Check if interval matches settings
            $time_until_next = $timestamp - $current_time;
            $expected_interval = self::get_interval_seconds($minutes);
            
            if ($time_until_next > ($expected_interval * 1.5)) {
                self::log('Schedule interval mismatch - rescheduling');
                $needs_reschedule = true;
            }
        }

        if ($needs_reschedule) {
            self::clear_schedule();
            
            // Schedule main cron
            $scheduled = wp_schedule_event($current_time + 60, $schedule_key, self::HOOK);
            
            if ($scheduled === false) {
                self::log('ERROR: Failed to schedule main cron', 'error');
            } else {
                self::log("Successfully scheduled with interval: {$schedule_key}");
            }
            
            // Also schedule fallback check (runs more frequently)
            if (!wp_next_scheduled(self::FALLBACK_HOOK)) {
                wp_schedule_event($current_time + 300, 'hourly', self::FALLBACK_HOOK);
            }
            
            // Update health check
            update_option('aiss_cron_health_check', $current_time, false);
        }
    }

    /**
     * Get the appropriate schedule key based on minutes
     */
    private static function get_schedule_key($minutes) : string {
        if ($minutes <= 5) return self::SCHEDULE_5MIN;
        if ($minutes <= 15) return self::SCHEDULE_15MIN;
        if ($minutes <= 30) return self::SCHEDULE_30MIN;
        return self::SCHEDULE_60MIN;
    }

    /**
     * Get interval in seconds
     */
    private static function get_interval_seconds($minutes) : int {
        if ($minutes <= 5) return 5 * 60;
        if ($minutes <= 15) return 15 * 60;
        if ($minutes <= 30) return 30 * 60;
        return 60 * 60;
    }

    /**
     * Clear all schedules
     */
    public static function clear_schedule() : void {
        // Clear main hook
        $ts = wp_next_scheduled(self::HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::HOOK);
            $ts = wp_next_scheduled(self::HOOK);
        }
        
        // Clear fallback hook
        $ts = wp_next_scheduled(self::FALLBACK_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::FALLBACK_HOOK);
            $ts = wp_next_scheduled(self::FALLBACK_HOOK);
        }
        
        self::log('All schedules cleared');
    }

    /**
     * Fallback check - runs hourly to ensure main cron is working
     */
    public function fallback_check() : void {
        $settings = Utils::get_settings();
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $interval = self::get_interval_seconds((int)$settings['schedule_minutes']);
        
        // If main cron hasn't run for 2x the interval, reschedule
        if ($last_run < (time() - ($interval * 2))) {
            self::log('Fallback detected missed runs - rescheduling', 'warning');
            self::ensure_scheduled(true);
        }
    }

    /**
     * Emergency fallback - checks on admin_init
     */
    public function emergency_fallback() : void {
        // Only run once per hour max
        $last_check = (int)get_transient('aiss_emergency_check');
        if ($last_check) {
            return;
        }
        set_transient('aiss_emergency_check', time(), 3600);
        
        $settings = Utils::get_settings();
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $interval = self::get_interval_seconds((int)$settings['schedule_minutes']);
        
        // If cron hasn't run for 3x the interval, something is seriously wrong
        if ($last_run < (time() - ($interval * 3))) {
            self::log('EMERGENCY: Cron not running - attempting manual execution', 'error');
            
            // Check if we should run now
            $lock = get_transient('aiss_emergency_lock');
            if (!$lock) {
                set_transient('aiss_emergency_lock', true, 300); // 5 min lock
                
                // Try to run
                try {
                    $this->run();
                } catch (\Exception $e) {
                    self::log('Emergency run failed: ' . $e->getMessage(), 'error');
                }
                
                delete_transient('aiss_emergency_lock');
            }
            
            // Reschedule
            self::ensure_scheduled(true);
        }
    }

    /**
     * Main cron execution
     */
    public function run() : void {
        $start_time = microtime(true);
        $run_id = uniqid('run_');
        
        self::log("=== CRON RUN START [{$run_id}] ===");
        
        // Check for concurrent runs
        $running = get_transient('aiss_cron_running');
        if ($running) {
            self::log('Another instance is already running - aborting', 'warning');
            return;
        }
        
        // Set lock (expires in 5 minutes as safety)
        set_transient('aiss_cron_running', $run_id, 300);
        
        try {
            // Update last run time
            update_option('aiss_last_run_time', time(), false);
            update_option('aiss_cron_health_check', time(), false);
            
            $settings = Utils::get_settings();
            $fb_connected = $this->facebook->is_connected();
            $x_connected  = $this->x->is_connected();

            // If no networks are connected, abort
            if (!$fb_connected && !$x_connected) {
                self::log('No networks connected - skipping', 'info');
                return;
            }

            self::log("Networks: FB=" . ($fb_connected ? 'YES' : 'NO') . ", X=" . ($x_connected ? 'YES' : 'NO'));

            // Build query args
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => (int)$settings['max_posts_per_run'],
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'date_query'     => [
                    ['after' => '7 days ago'] // Extended from 3 to 7 days
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
                // Timeout protection - don't run for more than 45 seconds
                if ((microtime(true) - $start_time) > 45) {
                    self::log('Timeout protection triggered - stopping batch', 'warning');
                    break;
                }
                
                $result = $this->process_post($post_id, $settings);
                $processed++;
                
                if ($result['shared'] > 0) {
                    $shared += $result['shared'];
                }
                if ($result['skipped'] > 0) {
                    $skipped += $result['skipped'];
                }
                if ($result['errors'] > 0) {
                    $errors += $result['errors'];
                }
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
            
            self::log("=== CRON RUN COMPLETE [{$run_id}] ===");
            self::log("Duration: {$duration}s | Processed: {$processed} | Shared: {$shared} | Skipped: {$skipped} | Errors: {$errors}");
            
        } catch (\Exception $e) {
            self::log('FATAL ERROR: ' . $e->getMessage(), 'error');
            self::log('Stack trace: ' . $e->getTraceAsString(), 'error');
        } finally {
            // Always release lock
            delete_transient('aiss_cron_running');
        }
    }

    /**
     * Process a single post
     */
    private function process_post($post_id, $settings) : array {
        $result = ['shared' => 0, 'skipped' => 0, 'errors' => 0];
        
        self::log("Processing post #{$post_id}: " . get_the_title($post_id));
        
        // --- 1. Process Facebook ---
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

        // --- DELAY between platforms ---
        if ($this->facebook->is_connected() && $this->x->is_connected()) {
            $delay = apply_filters('aiss_platform_delay', 15);
            self::log("  Waiting {$delay}s before X...");
            sleep($delay);
        }

        // --- 2. Process X (Twitter) ---
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
     * Logging system
     */
    public static function log($message, $level = 'info') : void {
        $settings = Utils::get_settings();
        
        // Skip logs if debug not enabled (except errors)
        if (empty($settings['enable_debug_logs']) && $level !== 'error') {
            return;
        }
        
        $timestamp = current_time('mysql');
        $entry = "[{$timestamp}] [{$level}] {$message}\n";
        
        // Store in option (keep last 100 lines)
        $log = get_option('aiss_debug_log', '');
        $lines = explode("\n", $log);
        $lines[] = $entry;
        $lines = array_slice($lines, -100); // Keep last 100
        update_option('aiss_debug_log', implode("\n", $lines), false);
        
        // Also log errors to PHP error log
        if ($level === 'error' && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AI Social Share: {$message}");
        }
    }

    /**
     * Get cron health status
     */
    public static function get_health_status() : array {
        $settings = Utils::get_settings();
        $last_run = (int)get_option('aiss_last_run_time', 0);
        $last_health = (int)get_option('aiss_cron_health_check', 0);
        $next_scheduled = wp_next_scheduled(self::HOOK);
        $interval = self::get_interval_seconds((int)$settings['schedule_minutes']);
        
        $status = [
            'healthy' => true,
            'last_run' => $last_run,
            'next_run' => $next_scheduled,
            'interval' => $interval,
            'issues' => []
        ];
        
        // Check if cron is scheduled
        if (!$next_scheduled) {
            $status['healthy'] = false;
            $status['issues'][] = 'Cron is not scheduled';
        }
        
        // Check if cron ran recently
        if ($last_run && $last_run < (time() - ($interval * 2))) {
            $status['healthy'] = false;
            $status['issues'][] = 'Cron has not run for ' . round((time() - $last_run) / 60) . ' minutes';
        }
        
        // Check if schedule is stuck
        if ($next_scheduled && $next_scheduled < (time() - 600)) {
            $status['healthy'] = false;
            $status['issues'][] = 'Schedule appears to be stuck in the past';
        }
        
        // Check WP-Cron system
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $status['issues'][] = 'WP-Cron is disabled - ensure system cron is configured';
        }
        
        return $status;
    }

    /**
     * Clear debug log
     */
    public static function clear_log() : void {
        delete_option('aiss_debug_log');
        self::log('Debug log cleared');
    }
}