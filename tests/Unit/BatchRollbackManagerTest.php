<?php
/**
 * Unit tests for the BPIBatchRollbackManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIBatchRollbackManager;
use BPIRollbackManager;
use BPISettingsManager;
use BPILogManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for batch manifest recording, rollback, active batch tracking,
 * expired cleanup, and AJAX handler security.
 */
class BatchRollbackManagerTest extends TestCase {

    private BPIBatchRollbackManager $manager;
    private BPIRollbackManager $rollback;
    private BPISettingsManager $settings;
    private BPILogManager $logger;

    protected function setUp(): void {
        // Reset global state.
        global $bpi_test_options, $bpi_test_transients, $bpi_test_hooks,
            $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses, $wpdb;

        $bpi_test_options        = array();
        $bpi_test_transients     = array();
        $bpi_test_hooks          = array();
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $wpdb->reset_bpi_log();

        $this->rollback = $this->createMock( BPIRollbackManager::class );
        $this->settings = new BPISettingsManager();
        $this->logger   = new BPILogManager();

        $this->manager = new BPIBatchRollbackManager(
            $this->rollback,
            $this->settings,
            $this->logger
        );
    }

    // ------------------------------------------------------------------
    // recordBatch() tests
    // ------------------------------------------------------------------

    public function test_record_batch_stores_manifest_in_transient(): void {
        $manifest = $this->createSampleManifest();

        $this->manager->recordBatch( 'batch_001', $manifest );

        $stored = $this->manager->getBatchManifest( 'batch_001' );
        $this->assertNotEmpty( $stored );
        $this->assertSame( 'batch_001', $stored['batch_id'] );
        $this->assertArrayHasKey( 'plugins', $stored );
        $this->assertArrayHasKey( 'expires_at', $stored );
    }

    public function test_record_batch_uses_retention_setting_for_expiration(): void {
        global $bpi_test_transients;

        // Set retention to 48 hours.
        update_option( 'bpi_rollback_retention', 48 );

        $this->manager->recordBatch( 'batch_002', $this->createSampleManifest() );

        $transient_data = $bpi_test_transients['bpi_batch_batch_002'] ?? null;
        $this->assertNotNull( $transient_data );
        $this->assertSame( 48 * 3600, $transient_data['expiration'] );
    }

    public function test_record_batch_defaults_retention_to_24_hours(): void {
        global $bpi_test_transients;

        // Default retention is 24 hours.
        $this->manager->recordBatch( 'batch_003', $this->createSampleManifest() );

        $transient_data = $bpi_test_transients['bpi_batch_batch_003'] ?? null;
        $this->assertNotNull( $transient_data );
        $this->assertSame( 24 * 3600, $transient_data['expiration'] );
    }

    public function test_record_batch_tracks_batch_id_in_active_list(): void {
        $this->manager->recordBatch( 'batch_a', $this->createSampleManifest() );
        $this->manager->recordBatch( 'batch_b', $this->createSampleManifest() );

        $active = get_option( 'bpi_active_batches', array() );
        $this->assertContains( 'batch_a', $active );
        $this->assertContains( 'batch_b', $active );
    }

    public function test_record_batch_does_not_duplicate_batch_id(): void {
        $this->manager->recordBatch( 'batch_dup', $this->createSampleManifest() );
        $this->manager->recordBatch( 'batch_dup', $this->createSampleManifest() );

        $active = get_option( 'bpi_active_batches', array() );
        $count  = count( array_filter( $active, fn( $id ) => $id === 'batch_dup' ) );
        $this->assertSame( 1, $count );
    }

    public function test_record_batch_sets_user_id_and_timestamp(): void {
        $manifest = array( 'plugins' => array() );
        $this->manager->recordBatch( 'batch_meta', $manifest );

        $stored = $this->manager->getBatchManifest( 'batch_meta' );
        $this->assertArrayHasKey( 'user_id', $stored );
        $this->assertArrayHasKey( 'timestamp', $stored );
        $this->assertSame( 'batch_meta', $stored['batch_id'] );
    }

    // ------------------------------------------------------------------
    // getBatchManifest() tests
    // ------------------------------------------------------------------

    public function test_get_batch_manifest_returns_empty_for_nonexistent(): void {
        $result = $this->manager->getBatchManifest( 'nonexistent' );
        $this->assertSame( array(), $result );
    }

    public function test_get_batch_manifest_returns_stored_data(): void {
        $manifest = $this->createSampleManifest();
        $this->manager->recordBatch( 'batch_get', $manifest );

        $result = $this->manager->getBatchManifest( 'batch_get' );
        $this->assertSame( 'batch_get', $result['batch_id'] );
        $this->assertCount( 2, $result['plugins'] );
    }

    // ------------------------------------------------------------------
    // rollbackBatch() tests
    // ------------------------------------------------------------------

    public function test_rollback_batch_returns_failure_for_missing_manifest(): void {
        $result = $this->manager->rollbackBatch( 'nonexistent' );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['failures'] );
    }

    public function test_rollback_batch_restores_updated_plugins(): void {
        $manifest = array(
            'plugins' => array(
                array(
                    'slug'        => 'plugin-a',
                    'action'      => 'update',
                    'status'      => 'success',
                    'backup_path' => '/backups/plugin-a_123',
                ),
            ),
        );

        $this->rollback->expects( $this->once() )
            ->method( 'restoreBackup' )
            ->with( '/backups/plugin-a_123', WP_CONTENT_DIR . '/plugins/plugin-a' )
            ->willReturn( true );

        $this->manager->recordBatch( 'batch_rb', $manifest );
        $result = $this->manager->rollbackBatch( 'batch_rb' );

        $this->assertTrue( $result['success'] );
        $this->assertEmpty( $result['failures'] );
        $this->assertSame( 'success', $result['results'][0]['status'] );
    }

    public function test_rollback_batch_removes_newly_installed_plugins(): void {
        $manifest = array(
            'plugins' => array(
                array(
                    'slug'   => 'new-plugin',
                    'action' => 'install',
                    'status' => 'success',
                ),
            ),
        );

        $this->rollback->expects( $this->once() )
            ->method( 'removePartialInstall' )
            ->with( WP_CONTENT_DIR . '/plugins/new-plugin' );

        $this->manager->recordBatch( 'batch_inst', $manifest );
        $result = $this->manager->rollbackBatch( 'batch_inst' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'remove', $result['results'][0]['action'] );
    }

    public function test_rollback_batch_continues_on_individual_failure(): void {
        $manifest = array(
            'plugins' => array(
                array(
                    'slug'        => 'fail-plugin',
                    'action'      => 'update',
                    'status'      => 'success',
                    'backup_path' => '/backups/fail-plugin_123',
                ),
                array(
                    'slug'   => 'ok-plugin',
                    'action' => 'install',
                    'status' => 'success',
                ),
            ),
        );

        $this->rollback->expects( $this->once() )
            ->method( 'restoreBackup' )
            ->willReturn( new \WP_Error( 'restore_failed', 'Disk error' ) );

        $this->rollback->expects( $this->once() )
            ->method( 'removePartialInstall' );

        $this->manager->recordBatch( 'batch_partial', $manifest );
        $result = $this->manager->rollbackBatch( 'batch_partial' );

        $this->assertFalse( $result['success'] );
        $this->assertCount( 1, $result['failures'] );
        $this->assertCount( 2, $result['results'] );
        // Second plugin should still have been processed.
        $this->assertSame( 'success', $result['results'][1]['status'] );
    }

    public function test_rollback_batch_skips_failed_plugins(): void {
        $manifest = array(
            'plugins' => array(
                array(
                    'slug'   => 'failed-plugin',
                    'action' => 'install',
                    'status' => 'failed',
                ),
            ),
        );

        $this->rollback->expects( $this->never() )
            ->method( 'removePartialInstall' );

        $this->manager->recordBatch( 'batch_skip', $manifest );
        $result = $this->manager->rollbackBatch( 'batch_skip' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'skipped', $result['results'][0]['status'] );
    }

    public function test_rollback_batch_removes_transient_and_tracking(): void {
        $this->manager->recordBatch( 'batch_clean', array( 'plugins' => array() ) );

        $this->assertNotEmpty( $this->manager->getBatchManifest( 'batch_clean' ) );

        $this->manager->rollbackBatch( 'batch_clean' );

        $this->assertEmpty( $this->manager->getBatchManifest( 'batch_clean' ) );
        $active = get_option( 'bpi_active_batches', array() );
        $this->assertNotContains( 'batch_clean', $active );
    }

    public function test_rollback_batch_logs_operation(): void {
        global $wpdb;

        $this->manager->recordBatch( 'batch_log', array( 'plugins' => array() ) );
        $this->manager->rollbackBatch( 'batch_log' );

        $this->assertNotEmpty( $wpdb->bpi_log_rows );
        $last_log = end( $wpdb->bpi_log_rows );
        $this->assertSame( 'batch_rollback', $last_log['action'] );
        $this->assertSame( 'batch_log', $last_log['batch_id'] );
    }

    public function test_rollback_batch_reports_missing_backup_path(): void {
        $manifest = array(
            'plugins' => array(
                array(
                    'slug'        => 'no-backup',
                    'action'      => 'update',
                    'status'      => 'success',
                    'backup_path' => '',
                ),
            ),
        );

        $this->manager->recordBatch( 'batch_nobk', $manifest );
        $result = $this->manager->rollbackBatch( 'batch_nobk' );

        $this->assertFalse( $result['success'] );
        $this->assertCount( 1, $result['failures'] );
        $this->assertSame( 'failed', $result['results'][0]['status'] );
    }

    // ------------------------------------------------------------------
    // getActiveBatches() tests
    // ------------------------------------------------------------------

    public function test_get_active_batches_returns_valid_manifests(): void {
        $this->manager->recordBatch( 'active_1', array( 'plugins' => array() ) );
        $this->manager->recordBatch( 'active_2', array( 'plugins' => array() ) );

        $batches = $this->manager->getActiveBatches();

        $this->assertCount( 2, $batches );
        $ids = array_column( $batches, 'batch_id' );
        $this->assertContains( 'active_1', $ids );
        $this->assertContains( 'active_2', $ids );
    }

    public function test_get_active_batches_excludes_expired_transients(): void {
        global $bpi_test_transients;

        $this->manager->recordBatch( 'valid_batch', array( 'plugins' => array() ) );

        // Manually add a batch ID that has no transient (simulating expiration).
        $active   = get_option( 'bpi_active_batches', array() );
        $active[] = 'expired_batch';
        update_option( 'bpi_active_batches', $active );

        $batches = $this->manager->getActiveBatches();

        $this->assertCount( 1, $batches );
        $this->assertSame( 'valid_batch', $batches[0]['batch_id'] );
    }

    // ------------------------------------------------------------------
    // cleanupExpired() tests
    // ------------------------------------------------------------------

    public function test_cleanup_expired_removes_expired_batch_ids(): void {
        global $bpi_test_transients;

        $this->manager->recordBatch( 'still_valid', array( 'plugins' => array() ) );

        // Add an expired batch by removing its transient.
        $active   = get_option( 'bpi_active_batches', array() );
        $active[] = 'already_expired';
        update_option( 'bpi_active_batches', $active );

        $this->manager->cleanupExpired();

        $remaining = get_option( 'bpi_active_batches', array() );
        $this->assertContains( 'still_valid', $remaining );
        $this->assertNotContains( 'already_expired', $remaining );
    }

    public function test_cleanup_expired_cleans_up_backups_for_explicitly_expired(): void {
        global $bpi_test_transients;

        // Record a batch first so it's tracked.
        $this->manager->recordBatch( 'exp_batch', array(
            'plugins' => array(
                array(
                    'slug'        => 'old-plugin',
                    'backup_path' => '/backups/old-plugin_123',
                ),
            ),
        ) );

        // Now manually override the transient to simulate an expired batch
        // (expires_at in the past) while the transient itself still exists.
        $bpi_test_transients['bpi_batch_exp_batch']['value']['expires_at'] =
            gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 );

        $this->rollback->expects( $this->once() )
            ->method( 'cleanupBackup' )
            ->with( '/backups/old-plugin_123' );

        $this->manager->cleanupExpired();

        $remaining = get_option( 'bpi_active_batches', array() );
        $this->assertNotContains( 'exp_batch', $remaining );
    }

    // ------------------------------------------------------------------
    // AJAX handler tests
    // ------------------------------------------------------------------

    public function test_register_ajax_handler_adds_action(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $this->manager->registerAjaxHandler();

        $hooks = array_column( $bpi_test_hooks, 'hook' );
        $this->assertContains( 'wp_ajax_bpi_batch_rollback', $hooks );
    }

    public function test_ajax_handler_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce'] = 'invalid';
        $_POST['batch_id'] = 'batch_001';

        $this->manager->handleAjaxRollback();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    public function test_ajax_handler_rejects_insufficient_capability(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce'] = 'valid';
        $_POST['batch_id'] = 'batch_001';

        $this->manager->handleAjaxRollback();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    public function test_ajax_handler_rejects_empty_batch_id(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['batch_id'] = '';

        $this->manager->handleAjaxRollback();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );

        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    public function test_ajax_handler_performs_rollback_on_valid_request(): void {
        global $bpi_test_json_responses;

        $this->manager->recordBatch( 'ajax_batch', array( 'plugins' => array() ) );

        $_POST['_wpnonce'] = 'valid';
        $_POST['batch_id'] = 'ajax_batch';

        $this->manager->handleAjaxRollback();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        unset( $_POST['_wpnonce'], $_POST['batch_id'] );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createSampleManifest(): array {
        return array(
            'plugins' => array(
                array(
                    'slug'             => 'plugin-alpha',
                    'action'           => 'install',
                    'previous_version' => null,
                    'new_version'      => '1.0.0',
                    'backup_path'      => '',
                    'status'           => 'success',
                    'plugin_file'      => 'plugin-alpha/plugin-alpha.php',
                    'activated'        => true,
                ),
                array(
                    'slug'             => 'plugin-beta',
                    'action'           => 'update',
                    'previous_version' => '1.0.0',
                    'new_version'      => '2.0.0',
                    'backup_path'      => '/backups/plugin-beta_123',
                    'status'           => 'success',
                    'plugin_file'      => 'plugin-beta/plugin-beta.php',
                    'activated'        => false,
                ),
            ),
            'summary' => array(
                'total'       => 2,
                'installed'   => 1,
                'updated'     => 1,
                'failed'      => 0,
                'rolled_back' => 0,
            ),
        );
    }
}
