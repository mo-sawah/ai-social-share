<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;
    public $admin, $cron, $metabox, $openrouter, $facebook, $x;

    public static function instance() : self {
        if (self::$instance === null) { self::$instance = new self(); self::$instance->init(); }
        return self::$instance;
    }

    private function __construct() {}

    private function init() : void {
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-utils.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-openrouter.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-facebook.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-x.php'; // New
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-cron.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-metabox.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-admin.php';

        $this->openrouter = new OpenRouter();
        $this->facebook   = new Facebook();
        $this->x          = new X(); // New
        $this->cron       = new Cron($this->openrouter, $this->facebook, $this->x);
        $this->metabox    = new Metabox($this->openrouter, $this->facebook, $this->x);
        $this->admin      = new Admin($this->cron, $this->facebook, $this->x);

        register_activation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'deactivate']);
    }

    public static function activate() : void {
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-cron.php';
        Cron::ensure_scheduled();
    }

    public static function deactivate() : void {
        require_once plugin_dir_path(__FILE__) . 'includes/class-aiss-cron.php';
        Cron::clear_schedule();
    }
}