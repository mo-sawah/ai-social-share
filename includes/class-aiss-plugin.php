<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;

    /** @var Admin */
    public $admin;

    /** @var Cron */
    public $cron;

    /** @var Metabox */
    public $metabox;

    /** @var OpenRouter */
    public $openrouter;

    /** @var Facebook */
    public $facebook;

    public static function instance() : self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function init() : void {
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-utils.php';
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-openrouter.php';
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-facebook.php';
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-cron.php';
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-metabox.php';
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-admin.php';

        $this->openrouter = new OpenRouter();
        $this->facebook   = new Facebook();
        $this->cron       = new Cron($this->openrouter, $this->facebook);
        $this->metabox    = new Metabox($this->openrouter, $this->facebook);
        $this->admin      = new Admin($this->cron, $this->facebook);

        register_activation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(AISS_PLUGIN_FILE, [__CLASS__, 'deactivate']);
    }

    public static function activate() : void {
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-cron.php';
        Cron::ensure_scheduled();
    }

    public static function deactivate() : void {
        require_once AISS_PLUGIN_DIR . 'includes/class-aiss-cron.php';
        Cron::clear_schedule();
    }
}
