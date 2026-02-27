<?php
/**
 * PHPUnit bootstrap file for Bulk Plugin Installer tests.
 *
 * Provides WordPress function stubs so the plugin can be loaded
 * outside of a full WordPress environment for unit testing.
 *
 * @package BulkPluginInstaller
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// Load WordPress function stubs.
require_once __DIR__ . '/wp-stubs.php';

// Load the plugin file.
require_once dirname( __DIR__ ) . '/bulk-plugin-installer.php';
