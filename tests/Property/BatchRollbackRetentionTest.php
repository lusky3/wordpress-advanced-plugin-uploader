<?php
/**
 * Property test for batch rollback availability by retention period.
 *
 * Feature: bulk-plugin-installer, Property 23: Batch rollback availability by retention period
 *
 * **Validates: Requirements 15.3**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIBatchRollbackManager;
use BPIRollbackManager;
use BPISettingsManager;
use BPILogManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class BatchRollbackRetentionTest extends TestCase {

    use TestTrait;

    private BPIRollbackManager $rollback;
    private BPISettingsManager $settings;
    private BPILogManager $logger;
    private BPIBatchRollbackManager $manager;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_transients, $bpi_test_hooks, $wpdb;

        $bpi_test_options    = array();
        $bpi_test_transients = array();
        $bpi_test_hooks      = array();
        if ( isset( $wpdb ) && method_exists( $wpdb, 'reset_bpi_log' ) ) {
            $wpdb->reset_bpi_log();
        }

        $this->rollback = new BPIRollbackManager();
        $this->settings = new BPISettingsManager();
        $this->logger   = new BPILogManager();

        $this->manager = new BPIBatchRollbackManager(
            $this->rollback,
            $this->settings,
            $this->logger
        );
    }

    /**
     * Build a minimal batch manifest.
     *
     * @param int $pluginCount Number of plugins in the batch.
     * @param int $seed        Seed for unique slug generation.
     * @return array Manifest suitable for recordBatch().
     */
    private function buildManifest( int $pluginCount, int $seed ): array {
        $plugins = array();
        for ( $i = 0; $i < $pluginCount; $i++ ) {
            $plugins[] = array(
                'slug'             => 'ret-plugin-' . $seed . '-' . $i,
                'action'           => 'install',
                'status'           => 'success',
                'new_version'      => '1.0.0',
                'previous_version' => null,
                'backup_path'      => '',
                'plugin_file'      => 'ret-plugin-' . $seed . '-' . $i . '/main.php',
                'activated'        => false,
            );
        }
        return array( 'plugins' => $plugins );
    }

    /**
     * Property 23 (a): Transient expiration matches retention_hours * 3600.
     *
     * For any retention period (1–720 hours) and any number of batches (1–5),
     * the transient stored by recordBatch() must have expiration equal to
     * retention_hours * 3600 seconds.
     *
     * **Validates: Requirements 15.3**
     */
    public function test_transient_expiration_matches_retention_period(): void {
        $this
            ->forAll(
                Generator\choose( 1, 720 ),
                Generator\choose( 1, 5 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $retentionHours, int $batchCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();

                // Set the retention period.
                $bpi_test_options['bpi_rollback_retention'] = $retentionHours;

                $expectedExpiration = $retentionHours * 3600;

                for ( $b = 0; $b < $batchCount; $b++ ) {
                    $batchId  = 'ret_batch_' . $seed . '_' . $b;
                    $manifest = $this->buildManifest( 2, $seed + $b );

                    $this->manager->recordBatch( $batchId, $manifest );

                    // Verify the transient expiration.
                    $transientKey = 'bpi_batch_' . $batchId;
                    $this->assertArrayHasKey(
                        $transientKey,
                        $bpi_test_transients,
                        "Transient must exist for batch '$batchId'."
                    );
                    $this->assertSame(
                        $expectedExpiration,
                        $bpi_test_transients[ $transientKey ]['expiration'],
                        "Transient expiration must be retention_hours ($retentionHours) * 3600 = $expectedExpiration seconds."
                    );
                }
            } );
    }

    /**
     * Property 23 (b): All recorded batches are returned by getActiveBatches()
     * when their transients still exist (within retention period).
     *
     * **Validates: Requirements 15.3**
     */
    public function test_active_batches_returns_all_within_retention(): void {
        $this
            ->forAll(
                Generator\choose( 1, 720 ),
                Generator\choose( 1, 5 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $retentionHours, int $batchCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();

                $bpi_test_options['bpi_rollback_retention'] = $retentionHours;

                $batchIds = array();
                for ( $b = 0; $b < $batchCount; $b++ ) {
                    $batchId    = 'active_' . $seed . '_' . $b;
                    $batchIds[] = $batchId;
                    $manifest   = $this->buildManifest( 1, $seed + $b );
                    $this->manager->recordBatch( $batchId, $manifest );
                }

                $activeBatches = $this->manager->getActiveBatches();

                $this->assertCount(
                    $batchCount,
                    $activeBatches,
                    'getActiveBatches() must return all recorded batches when transients exist.'
                );

                // Verify each batch ID is present.
                $activeBatchIds = array_column( $activeBatches, 'batch_id' );
                foreach ( $batchIds as $id ) {
                    $this->assertContains(
                        $id,
                        $activeBatchIds,
                        "Batch '$id' must be in active batches."
                    );
                }
            } );
    }

    /**
     * Property 23 (c): Batches whose transients are deleted (simulating expiration)
     * are NOT returned by getActiveBatches().
     *
     * **Validates: Requirements 15.3**
     */
    public function test_expired_batches_not_in_active_list(): void {
        $this
            ->forAll(
                Generator\choose( 1, 720 ),
                Generator\choose( 2, 5 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $retentionHours, int $batchCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();

                $bpi_test_options['bpi_rollback_retention'] = $retentionHours;

                $batchIds = array();
                for ( $b = 0; $b < $batchCount; $b++ ) {
                    $batchId    = 'exp_' . $seed . '_' . $b;
                    $batchIds[] = $batchId;
                    $manifest   = $this->buildManifest( 1, $seed + $b );
                    $this->manager->recordBatch( $batchId, $manifest );
                }

                // Simulate expiration: delete transients for roughly half the batches.
                $expiredIds = array();
                $keptIds    = array();
                foreach ( $batchIds as $i => $id ) {
                    if ( ( $seed + $i ) % 2 === 0 ) {
                        delete_transient( 'bpi_batch_' . $id );
                        $expiredIds[] = $id;
                    } else {
                        $keptIds[] = $id;
                    }
                }

                $activeBatches  = $this->manager->getActiveBatches();
                $activeBatchIds = array_column( $activeBatches, 'batch_id' );

                // Kept batches must be present.
                foreach ( $keptIds as $id ) {
                    $this->assertContains(
                        $id,
                        $activeBatchIds,
                        "Non-expired batch '$id' must be in active batches."
                    );
                }

                // Expired batches must NOT be present.
                foreach ( $expiredIds as $id ) {
                    $this->assertNotContains(
                        $id,
                        $activeBatchIds,
                        "Expired batch '$id' must NOT be in active batches."
                    );
                }

                $this->assertCount(
                    count( $keptIds ),
                    $activeBatches,
                    'Active batches count must equal only non-expired batches.'
                );
            } );
    }

    /**
     * Property 23 (d): cleanupExpired() removes batches with expires_at in the past
     * and retains batches with expires_at in the future.
     *
     * **Validates: Requirements 15.3**
     */
    public function test_cleanup_expired_removes_past_batches(): void {
        $this
            ->forAll(
                Generator\choose( 1, 720 ),
                Generator\choose( 2, 5 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $retentionHours, int $batchCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();

                $bpi_test_options['bpi_rollback_retention'] = $retentionHours;

                $batchIds   = array();
                $expiredIds = array();
                $validIds   = array();

                for ( $b = 0; $b < $batchCount; $b++ ) {
                    $batchId    = 'cleanup_' . $seed . '_' . $b;
                    $batchIds[] = $batchId;
                    $manifest   = $this->buildManifest( 1, $seed + $b );
                    $this->manager->recordBatch( $batchId, $manifest );

                    // Manipulate expires_at: make roughly half expired.
                    $transientKey = 'bpi_batch_' . $batchId;
                    if ( ( $seed + $b ) % 2 === 0 ) {
                        // Set expires_at to 1 hour in the past.
                        $stored = $bpi_test_transients[ $transientKey ]['value'];
                        $stored['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 );
                        $bpi_test_transients[ $transientKey ]['value'] = $stored;
                        $expiredIds[] = $batchId;
                    } else {
                        $validIds[] = $batchId;
                    }
                }

                $this->manager->cleanupExpired();

                // Valid batches must still be retrievable.
                foreach ( $validIds as $id ) {
                    $manifest = $this->manager->getBatchManifest( $id );
                    $this->assertNotEmpty(
                        $manifest,
                        "Valid batch '$id' must still be retrievable after cleanup."
                    );
                }

                // Expired batches must be removed.
                foreach ( $expiredIds as $id ) {
                    $manifest = $this->manager->getBatchManifest( $id );
                    $this->assertEmpty(
                        $manifest,
                        "Expired batch '$id' must be removed after cleanup."
                    );
                }

                // getActiveBatches should only return valid ones.
                $activeBatches  = $this->manager->getActiveBatches();
                $activeBatchIds = array_column( $activeBatches, 'batch_id' );

                $this->assertCount(
                    count( $validIds ),
                    $activeBatches,
                    'After cleanup, only non-expired batches should remain active.'
                );

                foreach ( $validIds as $id ) {
                    $this->assertContains( $id, $activeBatchIds );
                }
            } );
    }
}
