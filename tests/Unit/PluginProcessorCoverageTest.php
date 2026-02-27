<?php
/**
 * Additional unit tests for BPIPluginProcessor to cover remaining paths.
 *
 * Covers: backup creation failure during update, handleInstallFailure
 * non-WP_Error path, handleAjaxDryRun success path.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIPluginProcessor;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Testable processor with controllable rollback manager.
 */
class CoverageTestableProcessor extends BPIPluginProcessor {

    public $defaultUpgraderResult = true;
    public array $activePlugins = array();
    public array $activationResults = array();

    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        if ( $this->defaultUpgraderResult instanceof \WP_Error ) {
            return $this->defaultUpgraderResult;
        }
        return true;
    }

    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_proc_cov/' . $slug;
    }

    protected function isPluginActive( string $plugin_file ): bool {
        return $this->activePlugins[ $plugin_file ] ?? false;
    }

    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return $this->activationResults[ $plugin_file ] ?? null;
    }
}

/**
 * Rollback manager that simulates backup creation failure.
 */
class FailingBackupRollbackManager extends BPIRollbackManager {

    public bool $backupShouldFail = false;

    public function createBackup( string $plugin_dir ): string|\WP_Error {
        if ( $this->backupShouldFail ) {
            return new \WP_Error( 'backup_failed', 'Simulated backup failure' );
        }
        return '/tmp/fake-backup-' . time();
    }

    public function restoreBackup( string $backup_path, string $plugin_dir ): bool|\WP_Error {
        return true;
    }

    public function cleanupBackup( string $backup_path ): void {}
    public function removePartialInstall( string $plugin_dir ): void {}
}

/**
 * Tests for plugin processor coverage gaps.
 */
class PluginProcessorCoverageTest extends TestCase {

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_nonce_valid, $bpi_test_user_can,
               $bpi_test_json_responses, $wpdb, $bpi_test_settings_errors;

        $bpi_test_options         = array( 'bpi_auto_activate' => false, 'bpi_auto_rollback' => true );
        $bpi_test_nonce_valid     = true;
        $bpi_test_user_can        = true;
        $bpi_test_json_responses  = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();
        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'] );
    }

    protected function tearDown(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can;
        $bpi_test_nonce_valid = true;
        $bpi_test_user_can    = true;
        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'] );
    }

    public function test_update_fails_when_backup_creation_fails(): void {
        $rollback = new FailingBackupRollbackManager();
        $rollback->backupShouldFail = true;

        $processor = new CoverageTestableProcessor(
            $rollback,
            new BPILogManager(),
            new BPISettingsManager()
        );

        $plugin = array(
            'slug' => 'backup-fail', 'action' => 'update', 'plugin_name' => 'Backup Fail',
            'file_path' => '/tmp/bf.zip', 'plugin_file' => 'backup-fail/backup-fail.php',
            'plugin_version' => '2.0.0', 'installed_version' => '1.0.0',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'failed', $result['status'] );
        $this->assertStringContainsString( 'backup', implode( ' ', $result['messages'] ) );
    }

    public function test_update_success_cleans_up_backup(): void {
        $rollback = new FailingBackupRollbackManager();
        $rollback->backupShouldFail = false;

        $processor = new CoverageTestableProcessor(
            $rollback,
            new BPILogManager(),
            new BPISettingsManager()
        );

        $plugin = array(
            'slug' => 'upd-ok', 'action' => 'update', 'plugin_name' => 'Upd OK',
            'file_path' => '/tmp/upd.zip', 'plugin_file' => 'upd-ok/upd-ok.php',
            'plugin_version' => '2.0.0', 'installed_version' => '1.0.0',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'success', $result['status'] );
        $this->assertStringContainsString( 'updated', implode( ' ', $result['messages'] ) );
    }

    public function test_failed_install_removes_partial_install(): void {
        $rollback = new FailingBackupRollbackManager();

        $processor = new CoverageTestableProcessor(
            $rollback,
            new BPILogManager(),
            new BPISettingsManager()
        );
        $processor->defaultUpgraderResult = new \WP_Error( 'fail', 'Install failed' );

        $plugin = array(
            'slug' => 'fail-inst', 'action' => 'install', 'plugin_name' => 'Fail Inst',
            'file_path' => '/tmp/fi.zip', 'plugin_file' => 'fail-inst/fail-inst.php',
            'plugin_version' => '1.0.0', 'installed_version' => '',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'failed', $result['status'] );
        $this->assertFalse( $result['rolled_back'] );
    }

    public function test_failed_update_attempts_rollback(): void {
        $rollback = new FailingBackupRollbackManager();
        $rollback->backupShouldFail = false;

        $processor = new CoverageTestableProcessor(
            $rollback,
            new BPILogManager(),
            new BPISettingsManager()
        );
        $processor->defaultUpgraderResult = new \WP_Error( 'fail', 'Update failed' );

        $plugin = array(
            'slug' => 'fail-upd', 'action' => 'update', 'plugin_name' => 'Fail Upd',
            'file_path' => '/tmp/fu.zip', 'plugin_file' => 'fail-upd/fail-upd.php',
            'plugin_version' => '2.0.0', 'installed_version' => '1.0.0',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'failed', $result['status'] );
        $this->assertTrue( $result['rolled_back'] );
    }

    public function test_handle_ajax_dry_run_success(): void {
        global $bpi_test_json_responses;

        $processor = new CoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array(
            array(
                'slug' => 'dry-p', 'action' => 'install', 'plugin_name' => 'Dry P',
                'file_path' => '/tmp/dry.zip', 'plugin_file' => 'dry-p/dry-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $processor->handleAjaxDryRun();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertTrue( $bpi_test_json_responses[0]['data']['is_dry_run'] );
        $this->assertArrayHasKey( 'message', $bpi_test_json_responses[0]['data'] );
    }

    public function test_activation_on_update_when_already_active(): void {
        $processor = new CoverageTestableProcessor(
            new FailingBackupRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );
        $processor->activePlugins = array( 'active-upd/active-upd.php' => true );

        $plugin = array(
            'slug' => 'active-upd', 'action' => 'update', 'plugin_name' => 'Active Upd',
            'file_path' => '/tmp/au.zip', 'plugin_file' => 'active-upd/active-upd.php',
            'plugin_version' => '2.0.0', 'installed_version' => '1.0.0',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'success', $result['status'] );
        $this->assertTrue( $result['activated'] );
    }

    public function test_activation_failure_leaves_plugin_installed(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_auto_activate'] = true;

        $processor = new CoverageTestableProcessor(
            new FailingBackupRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );
        $processor->activationResults = array(
            'act-fail/act-fail.php' => new \WP_Error( 'activation_error', 'Cannot activate' ),
        );

        $plugin = array(
            'slug' => 'act-fail', 'action' => 'install', 'plugin_name' => 'Act Fail',
            'file_path' => '/tmp/af.zip', 'plugin_file' => 'act-fail/act-fail.php',
            'plugin_version' => '1.0.0', 'installed_version' => '',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'success', $result['status'] );
        $this->assertFalse( $result['activated'] );
        $this->assertStringContainsString( 'could not be activated', implode( ' ', $result['messages'] ) );
    }

    public function test_dry_run_with_auto_activate_shows_would_activate_message(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_auto_activate'] = true;

        $processor = new CoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $plugin = array(
            'slug' => 'act-dry', 'action' => 'install', 'plugin_name' => 'Act Dry',
            'file_path' => '/tmp/ad.zip', 'plugin_file' => 'act-dry/act-dry.php',
            'plugin_version' => '1.0.0', 'installed_version' => '',
        );

        $result = $processor->processPlugin( $plugin, true );

        $this->assertSame( 'success', $result['status'] );
        $this->assertTrue( $result['is_dry_run'] );
        $combined = implode( ' ', $result['messages'] );
        $this->assertStringContainsString( 'Would activate', $combined );
    }

    public function test_dry_run_with_per_plugin_activate_override(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_auto_activate'] = false;

        $processor = new CoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $plugin = array(
            'slug' => 'per-act', 'action' => 'install', 'plugin_name' => 'Per Act',
            'file_path' => '/tmp/pa.zip', 'plugin_file' => 'per-act/per-act.php',
            'plugin_version' => '1.0.0', 'installed_version' => '',
            'activate' => true,
        );

        $result = $processor->processPlugin( $plugin, true );

        $combined = implode( ' ', $result['messages'] );
        $this->assertStringContainsString( 'Would activate', $combined );
    }
}
