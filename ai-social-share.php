<?php
/**
 * Plugin Name: AI Social Share
 * Description: Automatically generates and shares WordPress posts to social media using OpenRouter AI (Facebook first).
 * Version: 2.0.23
 * Author: Sawah Solutions
 * License: GPLv2 or later
 * Text Domain: ai-social-share
 */

if (!defined('ABSPATH')) { exit; }

define('AISS_VERSION', '2.0.23');
define('AISS_PLUGIN_FILE', __FILE__);
define('AISS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AISS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AISS_PLUGIN_DIR . 'includes/class-aiss-plugin.php';

function aiss_bootstrap() {
    \AISS\Plugin::instance();
}
add_action('plugins_loaded', 'aiss_bootstrap');