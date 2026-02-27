<?php
/**
 * Unit tests for BulkPluginInstaller deactivate() and recursiveRmdir().
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BulkPluginInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the deactivation and cleanup methods.
 */
class BootstrapDeactivateTest extends TestCase {

    private string $backupDir;

    protected function setUp(): void {
        global $bpi_test_hooks, $bpi_test_options, $wpdb;
        $bpi_test_hooks   = array();
        $bpi_test_options = array();
        $wpdb->reset_bpi_log();

        $this->backupDir = WP_CONTENT_DIR . '/bpi-backups';
    }

    protected function tearDown(): void {
        if ( is_dir( $this->backupDir ) ) {
            $this->recursiveDelete( $this->backupDir );
        }
    }

    public function test_deactivate_cleans_up_backup_directory(): void {
        // Create a fake backup directory with files.
        mkdir( $this->backupDir . '/test-plugin_123_abc', 0755, true );
        file_put_contents( $this->backupDir . '/test-plugin_123_abc/plugin.php', '<?php // test' );

        $instance = BulkPluginInstaller::getInstance();
        $instance->deactivate();

        $this->assertDirectoryDoesNotExist( $this->backupDir );
    }

    public function test_deactivate_handles_missing_backup_directory(): void {
        // Ensure backup dir doesn't exist.
        if ( is_dir( $this->backupDir ) ) {
            $this->recursiveDelete( $this->backupDir );
        }

        $instance = BulkPluginInstaller::getInstance();
        // Should not throw.
        $instance->deactivate();

        $this->assertDirectoryDoesNotExist( $this->backupDir );
    }

    public function test_deactivate_removes_nested_backup_files(): void {
        mkdir( $this->backupDir . '/plugin_1/includes', 0755, true );
        file_put_contents( $this->backupDir . '/plugin_1/plugin.php', '<?php // main' );
        file_put_contents( $this->backupDir . '/plugin_1/includes/helper.php', '<?php // helper' );

        $instance = BulkPluginInstaller::getInstance();
        $instance->deactivate();

        $this->assertDirectoryDoesNotExist( $this->backupDir );
    }

    public function test_activate_sets_delete_data_on_uninstall_default(): void {
        global $bpi_test_options;
        $bpi_test_options = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->activate();

        $this->assertSame( false, $bpi_test_options['bpi_delete_data_on_uninstall'] );
    }

    public function test_register_network_admin_hooks_creates_admin_page(): void {
        $instance = BulkPluginInstaller::getInstance();
        // Should not throw — BPIAdminPage class exists.
        $instance->registerNetworkAdminHooks();
        $this->assertTrue( true );
    }

    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getPathname() );
            } else {
                unlink( $item->getPathname() );
            }
        }
        rmdir( $path );
    }
}
