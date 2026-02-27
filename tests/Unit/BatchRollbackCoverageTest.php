<?php
/**
 * Additional unit tests for BPIBatchRollbackManager to cover notification paths.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIBatchRollbackManager;
use BPIRollbackManager;
use BPISettingsManager;
use BPILogManager;
use BPINotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for batch rollback manager coverage gaps.
 */
class BatchRollbackCoverageTest extends TestCase {

    private BPIBatchRollbackManager $manager;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_nonce_valid, $bpi_test_user_can,
               $bpi_test_json_responses, $bpi_test_transients, $bpi_test_emails,
               $wpdb, $bpi_test_settings_errors;

        $bpi_test_options = array(
            'bpi_rollback_retention'  => 24,
            'bpi_email_notifications' => true,
            'admin_email'             => 'admin@example.com',
            'bpi_email_recipients'    => '',
        );
        $bpi_test_nonce_valid     = true;
        $bpi_test_user_can        = true;
        $bpi_test_json_responses  = array();
        $bpi_test_transients      = array();
        $bpi_test_emails          = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();

        $settings = new BPISettingsManager();
        $this->manager = new BPIBatchRollbackManager(
            new BPIRollbackManager(),
            $settings,
            new BPILogManager()
        );
        $this->manager->setNotificationManager( new BPINotificationManager( $settings ) );

        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    protected function tearDown(): void {
        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    public function test_ajax_rollback_success_sends_notification_and_returns_success(): void {
        global $bpi_test_json_responses, $bpi_test_emails, $bpi_test_transients;

        // Record a batch with an install action (no backup needed).
        $this->manager->recordBatch( 'notif_batch', array(
            'plugins' => array(
                array( 'slug' => 'notif-p', 'action' => 'install', 'status' => 'success' ),
            ),
        ) );

        $_POST['_wpnonce'] = 'valid';
        $_POST['batch_id'] = 'notif_batch';

        $this->manager->handleAjaxRollback();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        // Email should have been sent.
        $this->assertNotEmpty( $bpi_test_emails );
        // Admin notice should have been queued.
        $notice_found = false;
        foreach ( $bpi_test_transients as $key => $value ) {
            if ( str_starts_with( $key, 'bpi_admin_notices_' ) ) {
                $notice_found = true;
                break;
            }
        }
        $this->assertTrue( $notice_found );
    }

    public function test_ajax_rollback_with_failures_returns_error_with_notification(): void {
        global $bpi_test_json_responses, $bpi_test_emails;

        // Record a batch with an update action but no backup path — will fail.
        $this->manager->recordBatch( 'fail_batch', array(
            'plugins' => array(
                array( 'slug' => 'fail-p', 'action' => 'update', 'status' => 'success', 'backup_path' => '' ),
            ),
        ) );

        $_POST['_wpnonce'] = 'valid';
        $_POST['batch_id'] = 'fail_batch';

        $this->manager->handleAjaxRollback();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertArrayHasKey( 'failures', $bpi_test_json_responses[0]['data'] );
        // Email should still be sent for failed rollback.
        $this->assertNotEmpty( $bpi_test_emails );
    }

    public function test_record_batch_with_zero_retention_defaults_to_24(): void {
        global $bpi_test_options, $bpi_test_transients;

        $bpi_test_options['bpi_rollback_retention'] = 0;

        $settings = new BPISettingsManager();
        $manager = new BPIBatchRollbackManager(
            new BPIRollbackManager(),
            $settings,
            new BPILogManager()
        );

        $manager->recordBatch( 'zero_ret', array( 'plugins' => array() ) );

        // Should still store the batch (using default 24h retention).
        $manifest = $manager->getBatchManifest( 'zero_ret' );
        $this->assertNotEmpty( $manifest );
    }
}
