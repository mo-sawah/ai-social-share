<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;

    /** @var Admin */
    public $admin;

    /** @var SimpleScheduler */
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
        // Load dependencies safely using __DIR__
        require_once __DIR__ . '/class-aiss-utils.php';
        require_once __DIR__ . '/class-aiss-openrouter.php';
        require_once __DIR__ . '/class-aiss-facebook.php';
        require_once __DIR__ . '/class-aiss-x.php';
        require_once __DIR__ . '/class-aiss-simple-scheduler.php';
        require_once __DIR__ . '/class-aiss-metabox.php';
        require_once __DIR__ . '/class-aiss-admin.php';
        require_once __DIR__ . '/class-aiss-instagram.php';
        require_once __DIR__ . '/class-aiss-image-generator.php';

        // Initialize Service Classes
        $this->openrouter = new OpenRouter();
        $this->facebook   = new Facebook();
        $this->x          = new X();

        $this->instagram       = new Instagram();
        $this->image_generator = new ImageGenerator();
        $this->scheduler       = new SimpleScheduler(
            $this->openrouter, $this->facebook,
            $this->x, $this->instagram, $this->image_generator
        );
        
        // Initialize Logic Classes
        $this->scheduler  = new SimpleScheduler($this->openrouter, $this->facebook, $this->x);
        $this->metabox    = new Metabox($this->openrouter, $this->facebook, $this->x);
        $this->admin      = new Admin($this->scheduler, $this->facebook, $this->x);

        // Register Hooks
        register_activation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'deactivate']);
        
        // Register AJAX handler for async processing
        add_action('wp_ajax_aiss_async_process', ['\AISS\SimpleScheduler', 'handle_async_process']);
        add_action('wp_ajax_nopriv_aiss_async_process', ['\AISS\SimpleScheduler', 'handle_async_process']);
        
        // Register action for single post sharing
        add_action('aiss_process_single_post', [$this->scheduler, 'process_single_post']);
    }

    public static function activate() : void {
        // Simple scheduler auto-initializes, no manual scheduling needed
    }

    public static function deactivate() : void {
        // Clear any scheduled events
        $timestamp = wp_next_scheduled('aiss_simple_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'aiss_simple_cron');
        }
    }
}