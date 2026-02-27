<?php
/**
 * Additional unit tests for BPICLIInterface to cover uncovered paths.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPICLIInterface;
use BPIBulkUploader;
use BPIQueueManager;
use BPICompatibilityChecker;
use BPIPluginProcessor;
use BPIProfileManager;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Testable processor that always succeeds.
 */
class CLICoverageTestableProcessor extends BPIPluginProcessor {

    public $results = array();
    public mixed $defaultUpgraderResult = true;

    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        if ( $this->defaultUpgraderResult instanceof \WP_Error ) {
            return $this->defaultUpgraderResult;
        }
        return true;
    }


    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_cli_cov/' . $slug;
    }

    protected function isPluginActive( string $plugin_file ): bool {
        return false;
    }

    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return null;
    }
}

/**
 * Tests for CLI interface coverage gaps.
 */
class CLIInterfaceCoverageTest extends TestCase {

    private BPICLIInterface $cli;
    private string $tempDir;

    protected function setUp(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code, $bpi_test_cli_commands,
               $bpi_test_options, $bpi_test_installed_plugins, $wpdb, $bpi_test_settings_errors;

        $bpi_test_cli_log       = array();
        $bpi_test_cli_halt_code = null;
        $bpi_test_cli_commands  = array();
        $bpi_test_options       = array( 'bpi_auto_activate' => false, 'bpi_auto_rollback' => true );
        $bpi_test_installed_plugins = array();
        $bpi_test_settings_errors   = array();
        $wpdb->reset_bpi_log();

        $this->tempDir = sys_get_temp_dir() . '/bpi_cli_cov_' . uniqid();
        mkdir( $this->tempDir, 0755, true );

        $processor = new CLICoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $this->cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );
    }

    protected function tearDown(): void {
        $this->recursiveDelete( $this->tempDir );
    }

    public function test_install_with_valid_zip_and_yes_flag_processes(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code;

        $zip_path = $this->createValidPluginZip( 'cli-test-plugin' );

        $this->cli->install( array( $zip_path ), array( 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'Summary', $combined );
    }

    public function test_install_with_dry_run_flag_skips_confirmation(): void {
        global $bpi_test_cli_log;

        $zip_path = $this->createValidPluginZip( 'dry-cli-plugin' );

        $this->cli->install( array( $zip_path ), array( 'dry-run' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'dry-run', $combined );
    }

    public function test_install_with_invalid_zip_warns_and_errors(): void {
        global $bpi_test_cli_log;

        $bad_file = $this->tempDir . '/not-a-zip.zip';
        file_put_contents( $bad_file, 'not a zip file' );

        $this->cli->install( array( $bad_file ), array( 'yes' => true ) );

        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'warning', $types );
    }

    public function test_install_with_no_plugin_header_warns(): void {
        global $bpi_test_cli_log;

        // Create a ZIP with no PHP plugin header.
        $zip_path = $this->tempDir . '/no-header.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( 'no-header/readme.txt', 'Just a readme' );
        $zip->close();

        $this->cli->install( array( $zip_path ), array( 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        // Should warn about no valid plugin header or no valid files.
        $this->assertTrue(
            str_contains( $combined, 'No valid plugin header' )
            || str_contains( $combined, 'No valid plugin' )
            || str_contains( $combined, 'not a valid' )
        );
    }

    public function test_install_without_yes_halts_with_prompt(): void {
        global $bpi_test_cli_halt_code, $bpi_test_cli_log;

        $zip_path = $this->createValidPluginZip( 'prompt-plugin' );

        $this->cli->install( array( $zip_path ), array() );

        $this->assertSame( 0, $bpi_test_cli_halt_code );
        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'About to process', $combined );
    }

    public function test_process_with_progress_handles_failed_results(): void {
        global $bpi_test_cli_log;

        $processor = new CLICoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );
        $processor->defaultUpgraderResult = new \WP_Error( 'fail', 'Simulated failure' );

        $cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );

        $plugins = array(
            array(
                'slug' => 'fail-p', 'action' => 'install', 'plugin_name' => 'Fail P',
                'file_path' => '/tmp/fail.zip', 'plugin_file' => 'fail-p/fail-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $exit_code = $cli->processWithProgress( $plugins, false );

        $this->assertSame( 2, $exit_code );
        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'warning', $types );
    }

    public function test_process_with_progress_dry_run_logs_dry_run_label(): void {
        global $bpi_test_cli_log;

        $plugins = array(
            array(
                'slug' => 'dry-p', 'action' => 'install', 'plugin_name' => 'Dry P',
                'file_path' => '/tmp/dry.zip', 'plugin_file' => 'dry-p/dry-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $exit_code = $this->cli->processWithProgress( $plugins, true );

        $this->assertSame( 0, $exit_code );
        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'Dry run', $combined );
    }

    public function test_install_with_update_action_shows_update_in_preview(): void {
        global $bpi_test_cli_log, $bpi_test_installed_plugins, $bpi_test_cli_halt_code;

        $bpi_test_installed_plugins = array(
            'upd-plugin/upd-plugin.php' => array( 'Name' => 'Upd Plugin', 'Version' => '1.0.0' ),
        );

        $zip_path = $this->createValidPluginZip( 'upd-plugin', '2.0.0' );

        $this->cli->install( array( $zip_path ), array( 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'Summary', $combined );
    }

    private function createValidPluginZip( string $slug, string $version = '1.0.0' ): string {
        $zip_path = $this->tempDir . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            $slug . '/' . $slug . '.php',
            "<?php\n/**\n * Plugin Name: " . ucfirst( $slug ) . "\n * Version: {$version}\n * Author: Test\n * Description: Test plugin\n */"
        );
        $zip->close();
        return $zip_path;
    }

    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $path );
    }

    public function test_process_with_progress_shows_rolled_back_label(): void {
        global $bpi_test_cli_log, $bpi_test_options;

        $bpi_test_options['bpi_auto_rollback'] = true;

        // Create a processor that fails on update (triggers rollback).
        $rollback = new \BPI\Tests\Unit\FailingBackupRollbackManager();
        $rollback->backupShouldFail = false;

        $processor = new CLICoverageTestableProcessor(
            $rollback,
            new \BPILogManager(),
            new \BPISettingsManager()
        );
        $processor->defaultUpgraderResult = new \WP_Error( 'fail', 'Update failed' );

        $cli = new \BPICLIInterface(
            new \BPIBulkUploader(),
            new \BPIQueueManager(),
            new \BPICompatibilityChecker(),
            $processor,
            new \BPIProfileManager()
        );

        $plugins = array(
            array(
                'slug' => 'rb-p', 'action' => 'update', 'plugin_name' => 'RB P',
                'file_path' => '/tmp/rb.zip', 'plugin_file' => 'rb-p/rb-p.php',
                'plugin_version' => '2.0.0', 'installed_version' => '1.0.0',
            ),
        );

        $exit_code = $cli->processWithProgress( $plugins, false );

        $this->assertSame( 2, $exit_code );
        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'failed and rolled back', $combined );
    }
}
