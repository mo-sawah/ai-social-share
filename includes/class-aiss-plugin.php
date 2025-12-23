<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;

    /** @var Admin */
    public $admin;

    /** @var Scheduler */
    public $scheduler;

    /** @var Metabox */
    public $metabox;

    /** @var OpenRouter */
    public $openrouter;

    /** @var Facebook */
    public $facebook;

    /** @var X */
    public $x;

    public static function instance() : self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function init() : void {
        // Load Action Scheduler library first (if not already loaded by another plugin)
        if (!class_exists('ActionScheduler')) {
            $action_scheduler_path = AISS_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
            if (file_exists($action_scheduler_path)) {
                require_once $action_scheduler_path;
            } else {
                // Show admin notice if Action Scheduler is missing
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>AI Social Share Error:</strong> Action Scheduler library not found. ';
                    echo 'Please install Action Scheduler in: <code>wp-content/plugins/ai-social-share/lib/action-scheduler/</code>';
                    echo '</p></div>';
                });
            }
        }
        
        // Load dependencies safely using __DIR__
        require_once __DIR__ . '/class-aiss-utils.php';
        require_once __DIR__ . '/class-aiss-openrouter.php';
        require_once __DIR__ . '/class-aiss-facebook.php';
        require_once __DIR__ . '/class-aiss-x.php';
        require_once __DIR__ . '/class-aiss-scheduler.php';
        require_once __DIR__ . '/class-aiss-metabox.php';
        require_once __DIR__ . '/class-aiss-admin.php';

        // Initialize Service Classes
        $this->openrouter = new OpenRouter();
        $this->facebook   = new Facebook();
        $this->x          = new X();
        
        // Initialize Logic Classes
        $this->scheduler  = new Scheduler($this->openrouter, $this->facebook, $this->x);
        $this->metabox    = new Metabox($this->openrouter, $this->facebook, $this->x);
        $this->admin      = new Admin($this->scheduler, $this->facebook, $this->x);

        // Register Hooks
        register_activation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'deactivate']);
        
        // Register AJAX handler for single post sharing
        add_action('aiss_share_single_post', [$this->scheduler, 'process_single_post']);
    }

    public static function activate() : void {
        // Load Action Scheduler if not already loaded
        if (!class_exists('ActionScheduler')) {
            $action_scheduler_path = AISS_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
            if (file_exists($action_scheduler_path)) {
                require_once $action_scheduler_path;
            }
        }
        
        // Load scheduler and ensure it's scheduled
        require_once __DIR__ . '/class-aiss-utils.php';
        require_once __DIR__ . '/class-aiss-openrouter.php';
        require_once __DIR__ . '/class-aiss-facebook.php';
        require_once __DIR__ . '/class-aiss-x.php';
        require_once __DIR__ . '/class-aiss-scheduler.php';
        
        // Initialize (this will schedule the recurring action)
        $openrouter = new OpenRouter();
        $facebook = new Facebook();
        $x = new X();
        $scheduler = new Scheduler($openrouter, $facebook, $x);
        
        // Ensure scheduled
        $scheduler->ensure_scheduled();
    }

    public static function deactivate() : void {
        // Load Action Scheduler if not already loaded
        if (!class_exists('ActionScheduler')) {
            $action_scheduler_path = AISS_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
            if (file_exists($action_scheduler_path)) {
                require_once $action_scheduler_path;
            }
        }
        
        // Load scheduler and clear schedule
        require_once __DIR__ . '/class-aiss-utils.php';
        require_once __DIR__ . '/class-aiss-openrouter.php';
        require_once __DIR__ . '/class-aiss-facebook.php';
        require_once __DIR__ . '/class-aiss-x.php';
        require_once __DIR__ . '/class-aiss-scheduler.php';
        
        // Clear all scheduled actions
        $openrouter = new OpenRouter();
        $facebook = new Facebook();
        $x = new X();
        $scheduler = new Scheduler($openrouter, $facebook, $x);
        $scheduler->clear_scheduled();
    }
}