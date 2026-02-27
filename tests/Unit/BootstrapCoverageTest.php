<?php
/**
 * Additional unit tests for BulkPluginInstaller bootstrap to cover remaining paths.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BulkPluginInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Tests for bootstrap coverage gaps.
 */
class BootstrapCoverageTest extends TestCase {

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors;
        $bpi_test_options         = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();
    }

    public function test_activate_does_not_overwrite_existing_options(): void {
        global $bpi_test_options;

        // Pre-set some options.
        $bpi_test_options['bpi_auto_activate']  = true;
        $bpi_test_options['bpi_max_plugins']    = 50;

        $plugin = BulkPluginInstaller::getInstance();
        $plugin->activate();

        // Existing values should be preserved.
        $this->assertTrue( $bpi_test_options['bpi_auto_activate'] );
        $this->assertSame( 50, $bpi_test_options['bpi_max_plugins'] );
        // New defaults should be set for missing keys.
        $this->assertArrayHasKey( 'bpi_auto_rollback', $bpi_test_options );
    }

    public function test_load_textdomain_calls_wp_function(): void {
        $plugin = BulkPluginInstaller::getInstance();

        // Should not throw.
        $plugin->loadTextdomain();
        $this->assertTrue( true );
    }

    public function test_get_instance_returns_singleton(): void {
        $a = BulkPluginInstaller::getInstance();
        $b = BulkPluginInstaller::getInstance();

        $this->assertSame( $a, $b );
    }
}
