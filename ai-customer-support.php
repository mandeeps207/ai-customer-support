<?php
/**
 * Plugin Name: AI Customer Support
 * Description: AI-powered live chat with agent escalation for WordPress.
 * Version: 1.0.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define constants
define( 'AICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICS_URL', plugin_dir_url( __FILE__ ) );

// Load core
require_once AICS_DIR . 'includes/class-aics-core.php';

// Init
add_action( 'plugins_loaded', function() {
    AICS_Core::instance()->run();
});