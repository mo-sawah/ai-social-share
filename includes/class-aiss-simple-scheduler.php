<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

/**
 * Simple Reliable Scheduler v2
 *
 * Staggered Platform Sharing — one platform per queue cycle, with a
 * configurable minimum gap between platforms:
 *
 *   T+0  min → Facebook  (most overdue connected platform)
 *   T+10 min → X         (gap elapsed)
 *   T+20 min → Instagram (gap elapsed)
 *   T+60 min → cycle repeats
 *
 * Multiple trigger methods for reliability:
 *   1. Share on Publish  (instant)
 *   2. Admin heartbeat   (every ~60s when admin is open)
 *   3. Frontend check    (throttled, async, safety net)
 *   4. WP-Cron          (backbone)
 */
final class SimpleScheduler {

    private $openrouter;
    private $facebook;
    private $x;
    private $instagram;
    private $image_generator;

    public function __construct(
        OpenRouter     $openrouter,
        Facebook       $facebook,
        X              $x,
        Instagram      $instagram       = null,
        ImageGenerator $image_generator = null
    ) {
        $this->openrouter      = $openrouter;
        $this->facebook        = $facebook;
        $this->x               = $x;
        $this->instagram       = $instagram;
        $this->image_generator = $image_generator;

        // Method 1: Share immediately on publish
        add_action('transition_post_status', [$this, 'share_on_publish'], 10, 3);

        // Method 2: Admin heartbeat (every ~60s when admin is active)
        add_action('admin_init', [$this, 'admin_heartbeat_check']);

        // Method 3: Frontend page-load check (throttled to 5 min)
        add_action('init', [$this, 'frontend_check'], 1);

        // Method 4: Traditional WP-Cron as backbone
        add_action('aiss_simple_cron', [$this, 'process_queue']);

        // Register dynamic cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);

        // Auto-reschedule when settings change
        add_action('update_option_aiss_settings', [$this, 'on_settings_change']);

        // Ensure cron is registered on boot
        $this->ensure_scheduled();
    }

    // ── Cron Schedule ──────────────────────────────────────────────────────

    public function add_cron_schedule($schedules) {
        $settings = Utils::get_settings();
        $minutes  = max(5, (int)$settings['schedule_minutes']);

        $schedules['aiss_dynamic_interval'] = [
            'interval' => $minutes * 60,
            'display'  => "Every {$minutes} Minutes (AI Social Share)",
        ];

        // Keep legacy key so any old events can still be unscheduled cleanly
        $schedules['aiss_every_30min'] = [
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (AI Social Share — legacy)',
        ];

        return $schedules;
    }

    public function ensure_scheduled($force = false) {
        if ($force) {
            $this->clear_scheduled();
        }

        if (!wp_next_scheduled('aiss_simple_cron')) {
            $settings = Utils::get_settings();
            $minutes  = max(5, (int)$settings['schedule_minutes']);
            wp_schedule_event(time() + 60, 'aiss_dynamic_interval', 'aiss_simple_cron');
            self::log("Cron scheduled successfully (every {$minutes} minutes)");
        }
    }

    public function clear_scheduled() {
        $timestamp = wp_next_scheduled('aiss_simple_cron');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'aiss_simple_cron');
            $timestamp = wp_next_scheduled('aiss_simple_cron');
        }
        self::log('Cron unscheduled');
    }

    public function on_settings_change() {
        // Force reschedule so the new interval takes effect immediately
        $this->clear_scheduled();
        $this->ensure_scheduled();
    }

    // ── Trigger: Share on Publish ──────────────────────────────────────────

    public function share_on_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if ($post->post_type !== 'post') return;

        $settings = Utils::get_settings();
        if (empty($settings['share_on_publish'])) return;

        self::log("Share on publish triggered for post #{$post->ID}");

        // Schedule a slightly-delayed single event as safety net
        wp_schedule_single_event(time() + 10, 'aiss_process_single_post', [$post->ID]);

        // Also attempt to run right away
        $this->process_single_post($post->ID);
    }

    // ── Trigger: Admin Heartbeat ───────────────────────────────────────────

    public function admin_heartbeat_check() {
        // Throttle to once per minute
        $last_check = (int)get_transient('aiss_admin_heartbeat');
        if ($last_check && time() - $last_check < 60) return;

        set_transient('aiss_admin_heartbeat', time(), 120);

        if ($this->is_any_platform_due()) {
            self::log("Admin heartbeat: platform due, triggering queue");
            $this->process_queue();
        }
    }

    // ── Trigger: Frontend Check ────────────────────────────────────────────

    public function frontend_check() {
        // Throttle to once every 5 minutes
        if (get_transient('aiss_frontend_check')) return;
        set_transient('aiss_frontend_check', time(), 300);

        $settings = Utils::get_settings();
        $interval = max(5, (int)$settings['schedule_minutes']) * 60;
        $state    = $this->get_platform_state();

        // Only trigger if something is overdue by 2× the interval (safety net only)
        foreach (['fb', 'x', 'ig'] as $p) {
            if ((time() - ($state[$p]['last_run'] ?? 0)) >= $interval * 2) {
                self::log("Frontend check: {$p} overdue — spawning async process");
                $this->spawn_async_process();
                break;
            }
        }
    }

    // ── Async Process ──────────────────────────────────────────────────────

    private function spawn_async_process() {
        wp_remote_post(admin_url('admin-ajax.php?action=aiss_async_process'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => ['security' => wp_create_nonce('aiss_async')],
        ]);
    }

    public static function handle_async_process() {
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'aiss_async')) {
            wp_die('Invalid request');
        }

        $instance = new self(
            new OpenRouter(),
            new Facebook(),
            new X(),
            class_exists('AISS\Instagram')      ? new Instagram()      : null,
            class_exists('AISS\ImageGenerator') ? new ImageGenerator() : null
        );
        $instance->process_queue();

        wp_die();
    }

    // ── Core: Process Single Post (Share on Publish) ───────────────────────

    public function process_single_post($post_id) {
        $settings = Utils::get_settings();
        self::log("Processing single post #{$post_id} across all connected platforms");

        // For share-on-publish we share to ALL platforms immediately
        // (user explicitly hit Publish, so no stagger needed)
        $platforms = [
            'fb' => [$this->facebook, 'run_facebook'],
            'x'  => [$this->x,        'run_x'],
            'ig' => [$this->instagram, 'run_instagram'],
        ];

        $prev_ran = false;
        foreach ($platforms as $key => [$obj, $method]) {
            if (!$obj || !$obj->is_connected()) continue;
            if ($prev_ran) sleep(5); // small gap even on publish to avoid rate-limits
            $result = $this->$method($post_id, $settings);
            self::log("Single post [{$key}]: Shared={$result['shared']}, Errors={$result['errors']}");
            $prev_ran = true;
        }
    }

    // ── Core: Queue Processing (Staggered) ────────────────────────────────

    public function process_queue() {
        // Prevent concurrent runs
        if (get_transient('aiss_processing_lock')) {
            self::log("Queue already running — skipping");
            return;
        }
        set_transient('aiss_processing_lock', time(), 300);

        $start_time = microtime(true);
        $run_id     = uniqid('run_');
        self::log("=== QUEUE PROCESS START [{$run_id}] ===");

        try {
            $settings = Utils::get_settings();
            $interval = max(5, (int)$settings['schedule_minutes']) * 60;
            $min_gap  = max(1, (int)($settings['platform_gap_minutes'] ?? 10)) * 60;
            $state    = $this->get_platform_state();
            $now      = time();

            // Check which networks are connected
            $fb_connected = $this->facebook->is_connected();
            $x_connected  = $this->x->is_connected();
            $ig_connected = $this->instagram && $this->instagram->is_connected();

            if (!$fb_connected && !$x_connected && !$ig_connected) {
                self::log('No networks connected — skipping');
                return;
            }

            self::log("Networks: FB=" . ($fb_connected ? 'YES' : 'NO') .
                      " X=" . ($x_connected ? 'YES' : 'NO') .
                      " IG=" . ($ig_connected ? 'YES' : 'NO'));

            // Pick the ONE platform that is due and has waited longest
            $target = $this->pick_next_platform($state, $interval, $min_gap, $now);

            if ($target === null) {
                self::log("No platform due or minimum gap not met — nothing to do");
                return;
            }

            self::log("Target platform: [{$target}] | interval={$interval}s | gap={$min_gap}s");

            // Find posts not yet shared to this specific platform
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => max(1, (int)$settings['max_posts_per_run']),
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => [
                    [
                        'key'     => "_aiss_{$target}_shared_at",
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ];

            // Post age filter
            $post_age   = $settings['filter_post_age'] ?? '24h';
            $date_query = $this->get_date_query_for_age($post_age);
            if ($date_query) {
                $args['date_query'] = [$date_query];
            }

            // Category / tag filter
            if ($settings['filter_mode'] !== 'all' && !empty($settings['filter_terms'])) {
                $tax             = $settings['filter_mode'] === 'category' ? 'category' : 'post_tag';
                $args['tax_query'] = [[
                    'taxonomy' => $tax,
                    'field'    => 'slug',
                    'terms'    => array_map('trim', explode(',', $settings['filter_terms'])),
                ]];
            }

            $posts       = get_posts($args);
            $total_posts = count($posts);
            self::log("Found {$total_posts} unshared posts for [{$target}] (age filter: {$post_age})");

            // Always advance rotation (so the next platform gets its turn)
            // even when there are no posts to share
            $this->update_platform_last_run($target, $now);
            update_option('aiss_last_run_time', $now, false);

            if ($total_posts === 0) {
                self::log("[{$target}] No eligible posts — advancing rotation");
                return;
            }

            $processed = 0;
            $shared    = 0;
            $skipped   = 0;
            $errors    = 0;

            foreach ($posts as $post_id) {
                // Timeout protection — don't run for more than 45 seconds
                if ((microtime(true) - $start_time) > 45) {
                    self::log('Timeout protection triggered — stopping batch', 'warning');
                    break;
                }

                $result = $this->dispatch($post_id, $target, $settings);
                $processed++;
                $shared  += $result['shared'];
                $skipped += $result['skipped'];
                $errors  += $result['errors'];
            }

            $duration = round(microtime(true) - $start_time, 2);

            update_option('aiss_last_run_stats', [
                'last_run'  => $now,
                'duration'  => $duration,
                'platform'  => $target,
                'processed' => $processed,
                'shared'    => $shared,
                'skipped'   => $skipped,
                'errors'    => $errors,
            ], false);

            self::log("=== QUEUE COMPLETE [{$run_id}] [{$target}] ===");
            self::log("Duration: {$duration}s | Processed: {$processed} | Shared: {$shared} | Skipped: {$skipped} | Errors: {$errors}");

        } catch (\Exception $e) {
            self::log('FATAL ERROR: ' . $e->getMessage(), 'error');
            self::log('Stack trace: ' . $e->getTraceAsString(), 'error');
        } finally {
            delete_transient('aiss_processing_lock');
        }
    }

    // ── Platform Selection Logic ───────────────────────────────────────────

    /**
     * Pick the ONE platform that should run next.
     *
     * Rules:
     *  1. At least $min_gap seconds must have elapsed since ANY platform last ran.
     *  2. Platform must be connected.
     *  3. At least $interval seconds must have elapsed since THIS platform last ran.
     *  4. Among eligible platforms, pick the most overdue one.
     */
    private function pick_next_platform(array $state, int $interval, int $min_gap, int $now) : ?string {
        // Enforce minimum gap since the most-recent platform run
        $last_any_ran = 0;
        foreach (['fb', 'x', 'ig'] as $p) {
            $last_any_ran = max($last_any_ran, $state[$p]['last_run'] ?? 0);
        }

        if ($last_any_ran > 0 && ($now - $last_any_ran) < $min_gap) {
            $waited = $now - $last_any_ran;
            self::log("Platform gap not met: {$waited}s elapsed, need {$min_gap}s");
            return null;
        }

        $platform_objects = [
            'fb' => $this->facebook,
            'x'  => $this->x,
            'ig' => $this->instagram,
        ];

        $best        = null;
        $max_overdue = 0;

        foreach (['fb', 'x', 'ig'] as $p) {
            $obj = $platform_objects[$p] ?? null;
            if (!$obj || !$obj->is_connected()) continue;

            $overdue = $now - ($state[$p]['last_run'] ?? 0);

            if ($overdue >= $interval && $overdue > $max_overdue) {
                $max_overdue = $overdue;
                $best        = $p;
            }
        }

        return $best;
    }

    private function is_any_platform_due() : bool {
        $settings = Utils::get_settings();
        $interval = max(5, (int)$settings['schedule_minutes']) * 60;
        $min_gap  = max(1, (int)($settings['platform_gap_minutes'] ?? 10)) * 60;

        return $this->pick_next_platform(
            $this->get_platform_state(),
            $interval,
            $min_gap,
            time()
        ) !== null;
    }

    // ── Per-Platform Dispatch ──────────────────────────────────────────────

    private function dispatch(int $post_id, string $platform, array $settings) : array {
        self::log("  [{$platform}] Processing post #{$post_id}: " . get_the_title($post_id));

        switch ($platform) {
            case 'fb': return $this->run_facebook($post_id, $settings);
            case 'x':  return $this->run_x($post_id, $settings);
            case 'ig': return $this->run_instagram($post_id, $settings);
        }

        return ['shared' => 0, 'skipped' => 0, 'errors' => 0];
    }

    private function run_facebook(int $post_id, array $settings) : array {
        if (get_post_meta($post_id, '_aiss_fb_shared_at', true)) {
            self::log("  [fb] Already shared — skipping");
            return ['shared' => 0, 'skipped' => 1, 'errors' => 0];
        }

        $gen = $this->openrouter->generate_post($post_id, $settings['prompt_facebook']);

        if (!$gen['ok']) {
            update_post_meta($post_id, '_aiss_fb_last_error', $gen['error']);
            self::log("  [fb] ✗ AI generation failed: {$gen['error']}", 'error');
            return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
        }

        self::log("  [fb] AI generated (" . strlen($gen['text']) . " chars)");
        $res = $this->facebook->share_link_post($post_id, $gen['text']);

        if ($res['ok']) {
            update_post_meta($post_id, '_aiss_fb_shared_at',      time());
            update_post_meta($post_id, '_aiss_fb_remote_id',      $res['id']);
            update_post_meta($post_id, '_aiss_fb_last_generated', $gen['text']);
            delete_post_meta($post_id, '_aiss_fb_last_error');
            self::log("  [fb] ✓ Shared successfully (ID: {$res['id']})");
            return ['shared' => 1, 'skipped' => 0, 'errors' => 0];
        }

        update_post_meta($post_id, '_aiss_fb_last_error', $res['error']);
        self::log("  [fb] ✗ Share failed: {$res['error']}", 'error');
        return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
    }

    private function run_x(int $post_id, array $settings) : array {
        if (get_post_meta($post_id, '_aiss_x_shared_at', true)) {
            self::log("  [x] Already shared — skipping");
            return ['shared' => 0, 'skipped' => 1, 'errors' => 0];
        }

        $gen = $this->openrouter->generate_post($post_id, $settings['prompt_x']);

        if (!$gen['ok']) {
            update_post_meta($post_id, '_aiss_x_last_error', $gen['error']);
            self::log("  [x] ✗ AI generation failed: {$gen['error']}", 'error');
            return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
        }

        self::log("  [x] AI generated (" . strlen($gen['text']) . " chars)");
        $res = $this->x->post_tweet($post_id, $gen['text']);

        if ($res['ok']) {
            update_post_meta($post_id, '_aiss_x_shared_at',      time());
            update_post_meta($post_id, '_aiss_x_remote_id',      $res['id']);
            update_post_meta($post_id, '_aiss_x_last_generated', $gen['text']);
            delete_post_meta($post_id, '_aiss_x_last_error');
            self::log("  [x] ✓ Shared successfully (ID: {$res['id']})");
            return ['shared' => 1, 'skipped' => 0, 'errors' => 0];
        }

        update_post_meta($post_id, '_aiss_x_last_error', $res['error']);
        self::log("  [x] ✗ Share failed: {$res['error']}", 'error');
        return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
    }

    private function run_instagram(int $post_id, array $settings) : array {
        if (!$this->instagram || !$this->image_generator) {
            self::log("  [ig] Instagram classes not loaded", 'error');
            return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
        }

        if (get_post_meta($post_id, '_aiss_ig_shared_at', true)) {
            self::log("  [ig] Already shared — skipping");
            return ['shared' => 0, 'skipped' => 1, 'errors' => 0];
        }

        // Generate image + caption via Gemini Nano Banana
        $gen = $this->image_generator->generate_for_instagram($post_id);

        if (!$gen['ok']) {
            update_post_meta($post_id, '_aiss_ig_last_error', $gen['error']);
            self::log("  [ig] ✗ Image generation failed: {$gen['error']}", 'error');
            return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
        }

        self::log("  [ig] Image generated, posting to Instagram...");
        $res = $this->instagram->post_image(
            $post_id,
            $gen['image_url'],
            $gen['caption'],
            $gen['attachment_id']
        );

        if ($res['ok']) {
            update_post_meta($post_id, '_aiss_ig_shared_at',      time());
            update_post_meta($post_id, '_aiss_ig_remote_id',      $res['id']);
            update_post_meta($post_id, '_aiss_ig_last_generated', $gen['caption']);
            update_post_meta($post_id, '_aiss_ig_attachment_id',  $gen['attachment_id']);
            update_post_meta($post_id, '_aiss_ig_image_url',      $gen['image_url']);
            delete_post_meta($post_id, '_aiss_ig_last_error');
            self::log("  [ig] ✓ Shared successfully (Media ID: {$res['id']})");
            return ['shared' => 1, 'skipped' => 0, 'errors' => 0];
        }

        update_post_meta($post_id, '_aiss_ig_last_error', $res['error']);
        self::log("  [ig] ✗ Share failed: {$res['error']}", 'error');
        return ['shared' => 0, 'skipped' => 0, 'errors' => 1];
    }

    // ── Platform State Storage ─────────────────────────────────────────────

    private function get_platform_state() : array {
        $stored   = get_option('aiss_platform_state', []);
        $defaults = [
            'fb' => ['last_run' => 0],
            'x'  => ['last_run' => 0],
            'ig' => ['last_run' => 0],
        ];
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    private function update_platform_last_run(string $platform, int $timestamp) : void {
        $state                      = $this->get_platform_state();
        $state[$platform]['last_run'] = $timestamp;
        update_option('aiss_platform_state', $state, false);
    }

    // ── Date Query Helper ──────────────────────────────────────────────────

    private function get_date_query_for_age($age) {
        switch ($age) {
            case '24h':  return ['after' => '24 hours ago'];
            case '48h':  return ['after' => '48 hours ago'];
            case '7d':   return ['after' => '7 days ago'];
            case '30d':  return ['after' => '30 days ago'];
            case 'all':
            default:     return null;
        }
    }

    // ── Status ────────────────────────────────────────────────────────────

    public static function get_status() : array {
        $last_run   = (int)get_option('aiss_last_run_time', 0);
        $next_cron  = wp_next_scheduled('aiss_simple_cron');
        $plat_state = get_option('aiss_platform_state', []);

        return [
            'active'         => ($next_cron !== false || $last_run > (time() - 7200)),
            'next_run'       => $next_cron,
            'last_run'       => $last_run,
            'platform_state' => $plat_state,
            'methods'        => [
                'share_on_publish' => Utils::get_settings()['share_on_publish'] ?? false,
                'admin_heartbeat'  => true,
                'wp_cron'          => ($next_cron !== false),
            ],
        ];
    }

    // ── Logging ───────────────────────────────────────────────────────────

    public static function log($message, $level = 'info') : void {
        $settings = Utils::get_settings();

        if (empty($settings['enable_debug_logs']) && $level !== 'error') {
            return;
        }

        $timestamp = current_time('mysql');
        $entry     = "[{$timestamp}] [{$level}] {$message}\n";

        $log   = get_option('aiss_debug_log', '');
        $lines = explode("\n", $log);
        $lines[] = $entry;
        $lines = array_slice($lines, -150); // Keep last 150 lines
        update_option('aiss_debug_log', implode("\n", $lines), false);

        if ($level === 'error' && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AI Social Share: {$message}");
        }
    }

    public static function clear_log() : void {
        delete_option('aiss_debug_log');
        self::log('Debug log cleared');
    }
}