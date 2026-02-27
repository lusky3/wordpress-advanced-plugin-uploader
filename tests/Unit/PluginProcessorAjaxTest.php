<?php
/**
 * Unit tests for BPIPluginProcessor AJAX handler success paths.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIPluginProcessor;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use BPINotificationManager;
use BPIBatchRollbackManager;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass for AJAX tests.
 */
class AjaxTestableProcessor extends BPIPluginProcessor {

    public $defaultUpgraderResult = true;

    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        return $this->defaultUpgraderResult;
    }

    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_ajax_test/' . $slug;
    }

    protected function isPluginActive( string $plugin_file ): bool {
        return false;
    }

    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return null;
    }
}

/**
 * Tests for AJAX handler success paths and edge cases.
 */
class PluginProcessorAjaxTest extends TestCase {

    private AjaxTestableProcessor $processor;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses, $wpdb, $bpi_test_settings_errors, $bpi_test_emails, $bpi_test_transients;
        $bpi_test_options         = array( 'bpi_auto_activate' => false, 'bpi_auto_rollback' => true );
        $bpi_test_nonce_valid     = true;
        $bpi_test_user_can        = true;
        $bpi_test_json_responses  = array();
        $bpi_test_settings_errors = array();
        $bpi_test_emails          = array();
        $bpi_test_transients      = array();
        $wpdb->reset_bpi_log();
        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'] );

        $this->processor = new AjaxTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );
    }

    protected function tearDown(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can;
        $bpi_test_nonce_valid = true;
        $bpi_test_user_can    = true;
        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'] );
    }

    public function test_handle_ajax_process_returns_success_with_results(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array(
            array(
                'slug' => 'test-p', 'action' => 'install', 'plugin_name' => 'Test P',
                'file_path' => '/tmp/test-p.zip', 'plugin_file' => 'test-p/test-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $this->processor->handleAjaxProcess();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertArrayHasKey( 'results', $bpi_test_json_responses[0]['data'] );
        $this->assertArrayHasKey( 'summary', $bpi_test_json_responses[0]['data'] );
        $this->assertArrayHasKey( 'batch_id', $bpi_test_json_responses[0]['data'] );
    }

    public function test_handle_ajax_process_rejects_empty_selection(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array();

        $this->processor->handleAjaxProcess();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_handle_ajax_process_rejects_missing_selection(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';

        $this->processor->handleAjaxProcess();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_handle_ajax_process_records_batch_and_sends_notification(): void {
        global $bpi_test_json_responses, $bpi_test_options, $bpi_test_transients, $bpi_test_emails;
        $bpi_test_options['bpi_email_notifications'] = true;
        $bpi_test_options['bpi_email_recipients']    = '';
        $bpi_test_options['admin_email']             = 'admin@example.com';

        $notification = new BPINotificationManager( new BPISettingsManager() );
        $batch_rb     = new BPIBatchRollbackManager( new BPIRollbackManager(), new BPISettingsManager(), new BPILogManager() );

        $this->processor->setNotificationManager( $notification );
        $this->processor->setBatchRollbackManager( $batch_rb );

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array(
            array(
                'slug' => 'notif-p', 'action' => 'install', 'plugin_name' => 'Notif P',
                'file_path' => '/tmp/notif-p.zip', 'plugin_file' => 'notif-p/notif-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $this->processor->handleAjaxProcess();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        // Batch should be recorded in transients.
        $batch_id = $bpi_test_json_responses[0]['data']['batch_id'];
        $this->assertNotEmpty( $batch_id );
        // Email should have been sent.
        $this->assertNotEmpty( $bpi_test_emails );
    }

    public function test_handle_ajax_dry_run_returns_empty_selection_error(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array();

        $this->processor->handleAjaxDryRun();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_handle_ajax_process_with_dry_run_flag(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']         = 'valid';
        $_POST['dry_run']          = '1';
        $_POST['selected_plugins'] = array(
            array(
                'slug' => 'dry-p', 'action' => 'install', 'plugin_name' => 'Dry P',
                'file_path' => '/tmp/dry-p.zip', 'plugin_file' => 'dry-p/dry-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '',
            ),
        );

        $this->processor->handleAjaxProcess();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $results = $bpi_test_json_responses[0]['data']['results'];
        $this->assertTrue( $results[0]['is_dry_run'] );
    }

    public function test_handle_ajax_process_multisite_capability_check(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => false, 'install_plugins' => true );

        $_POST['_wpnonce'] = 'valid';
        $_POST['selected_plugins'] = array(
            array( 'slug' => 'ms-p', 'action' => 'install', 'plugin_name' => 'MS P',
                'file_path' => '/tmp/ms.zip', 'plugin_file' => 'ms-p/ms-p.php',
                'plugin_version' => '1.0.0', 'installed_version' => '' ),
        );

        $this->processor->handleAjaxProcess();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        $bpi_test_user_can         = true;
    }
}
