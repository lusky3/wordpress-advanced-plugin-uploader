<?php
/**
 * Property test for batch rollback restores pre-batch state.
 *
 * Feature: bulk-plugin-installer, Property 22: Batch rollback restores pre-batch state
 *
 * **Validates: Requirements 15.1, 15.4, 15.6**
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

/**
 * Tracks calls made to BPIRollbackManager methods so we can verify
 * which plugins were restored/removed during batch rollback.
 */
class TrackingRollbackManager extends BPIRollbackManager {

    /** @var array[] Each entry: ['backup_path' => string, 'plugin_dir' => string] */
    public array $restoreCalls = array();

    /** @var string[] Plugin dirs passed to removePartialInstall(). */
    public array $removeCalls = array();

    /** @var array<string, bool|\WP_Error> Map of slug => forced result for restoreBackup(). */
    public array $forceRestoreResults = array();

    public function restoreBackup( string $backup_path, string $plugin_dir ): bool|\WP_Error {
        $this->restoreCalls[] = array(
            'backup_path' => $backup_path,
            'plugin_dir'  => $plugin_dir,
        );

        // Check if a forced result is configured for this plugin dir.
        foreach ( $this->forceRestoreResults as $slug => $result ) {
            if ( str_ends_with( $plugin_dir, '/' . $slug ) ) {
                return $result;
            }
        }

        return true;
    }

    public function removePartialInstall( string $plugin_dir ): void {
        $this->removeCalls[] = $plugin_dir;
    }

    public function cleanupBackup( string $backup_path ): void {
        // No-op for tracking.
    }

    /**
     * Reset tracked calls between iterations.
     */
    public function reset(): void {
        $this->restoreCalls       = array();
        $this->removeCalls        = array();
        $this->forceRestoreResults = array();
    }
}

class BatchRollbackPreBatchStateTest extends TestCase {

    use TestTrait;

    private TrackingRollbackManager $rollback;
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

        $this->rollback = new TrackingRollbackManager();
        $this->settings = new BPISettingsManager();
        $this->logger   = new BPILogManager();

        $this->manager = new BPIBatchRollbackManager(
            $this->rollback,
            $this->settings,
            $this->logger
        );
    }

    /**
     * Build a batch manifest from generated plugin data.
     *
     * @param array $plugins Array of ['slug', 'action', 'status'] entries.
     * @return array Manifest suitable for recordBatch().
     */
    private function buildManifest( array $plugins ): array {
        $manifestPlugins = array();
        foreach ( $plugins as $p ) {
            $entry = array(
                'slug'             => $p['slug'],
                'action'           => $p['action'],
                'status'           => $p['status'],
                'new_version'      => '2.0.0',
                'plugin_file'      => $p['slug'] . '/' . $p['slug'] . '.php',
                'activated'        => false,
            );

            if ( 'update' === $p['action'] ) {
                $entry['previous_version'] = '1.0.0';
                $entry['backup_path']      = '/wp-content/bpi-backups/' . $p['slug'] . '_backup';
            } else {
                $entry['previous_version'] = null;
                $entry['backup_path']      = '';
            }

            $manifestPlugins[] = $entry;
        }

        return array( 'plugins' => $manifestPlugins );
    }

    /**
     * Generate random plugins with mixed actions and statuses.
     *
     * @param int $pluginCount Number of plugins.
     * @param int $seed        Random seed.
     * @return array Array of plugin data.
     */
    private function generatePlugins( int $pluginCount, int $seed ): array {
        $plugins = array();
        for ( $i = 0; $i < $pluginCount; $i++ ) {
            $action = ( ( $seed + $i ) % 2 === 0 ) ? 'update' : 'install';
            $status = ( ( $seed + $i * 7 ) % 5 === 0 ) ? 'failed' : 'success';
            $plugins[] = array(
                'slug'   => 'plugin-' . $seed . '-' . $i,
                'action' => $action,
                'status' => $status,
            );
        }
        return $plugins;
    }

    /**
     * Compute expected restore and remove directories from plugin list.
     *
     * @param array $plugins Plugin data array.
     * @return array{restore: array, remove: array}
     */
    private function computeExpectedDirs( array $plugins ): array {
        $restoreDirs = array();
        $removeDirs  = array();

        foreach ( $plugins as $p ) {
            if ( 'failed' === $p['status'] ) {
                continue;
            }
            $pluginDir = WP_CONTENT_DIR . '/plugins/' . $p['slug'];
            if ( 'update' === $p['action'] ) {
                $restoreDirs[ $pluginDir ] = '/wp-content/bpi-backups/' . $p['slug'] . '_backup';
            } elseif ( 'install' === $p['action'] ) {
                $removeDirs[] = $pluginDir;
            }
        }

        return array( 'restore' => $restoreDirs, 'remove' => $removeDirs );
    }

    /**
     * Property 22 (a): Batch rollback calls correct methods for each plugin type.
     *
     * For any batch manifest with a random mix of install/update actions:
     * - Updated plugins with status 'success' → restoreBackup() called with correct backup_path and plugin_dir
     * - Installed plugins with status 'success' → removePartialInstall() called with correct plugin_dir
     * - Failed plugins → skipped entirely (neither method called)
     *
     * **Validates: Requirements 15.1, 15.4**
     */
    public function test_rollback_calls_correct_methods_per_plugin_type(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $pluginCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();
                $this->rollback->reset();

                $plugins  = $this->generatePlugins( $pluginCount, $seed );
                $batchId  = 'pbt_batch_' . $seed;
                $manifest = $this->buildManifest( $plugins );

                $this->manager->recordBatch( $batchId, $manifest );
                $result = $this->manager->rollbackBatch( $batchId );

                $expected = $this->computeExpectedDirs( $plugins );

                // Verify restoreBackup calls.
                $actualRestoreDirs = array();
                foreach ( $this->rollback->restoreCalls as $call ) {
                    $actualRestoreDirs[ $call['plugin_dir'] ] = $call['backup_path'];
                }
                $this->assertSame(
                    $expected['restore'],
                    $actualRestoreDirs,
                    'restoreBackup() must be called with correct backup_path and plugin_dir for each successful update.'
                );

                // Verify removePartialInstall calls.
                $this->assertSame(
                    $expected['remove'],
                    $this->rollback->removeCalls,
                    'removePartialInstall() must be called with correct plugin_dir for each successful install.'
                );

                // Verify total results count matches plugin count.
                $this->assertCount(
                    $pluginCount,
                    $result['results'],
                    'Results must contain an entry for every plugin in the manifest.'
                );
            } );
    }

    /**
     * Property 22 (b): Individual rollback failures do not prevent remaining plugins from being processed.
     *
     * When restoreBackup() returns WP_Error for some plugins, the batch rollback
     * must still attempt all remaining plugins (fault tolerance).
     *
     * **Validates: Requirements 15.6**
     */
    public function test_rollback_continues_on_individual_failures(): void {
        $this
            ->forAll(
                Generator\choose( 2, 8 ),
                Generator\choose( 0, 99999 )
            )
            ->then( function ( int $pluginCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_transients;
                $bpi_test_options    = array();
                $bpi_test_transients = array();
                $this->rollback->reset();

                // Generate all-update plugins with 'success' status so all trigger restoreBackup.
                $plugins = array();
                for ( $i = 0; $i < $pluginCount; $i++ ) {
                    $plugins[] = array(
                        'slug'   => 'upd-' . $seed . '-' . $i,
                        'action' => 'update',
                        'status' => 'success',
                    );
                }

                // Pick a random subset to fail (~50% of plugins).
                $failSlugs = array();
                for ( $i = 0; $i < $pluginCount; $i++ ) {
                    if ( ( $seed + $i * 3 ) % 2 === 0 ) {
                        $slug = 'upd-' . $seed . '-' . $i;
                        $failSlugs[] = $slug;
                        $this->rollback->forceRestoreResults[ $slug ] = new \WP_Error(
                            'restore_failed',
                            'Simulated failure for ' . $slug
                        );
                    }
                }

                $batchId  = 'pbt_fault_' . $seed;
                $manifest = $this->buildManifest( $plugins );

                $this->manager->recordBatch( $batchId, $manifest );
                $result = $this->manager->rollbackBatch( $batchId );

                // All plugins must have been attempted (restoreBackup called for each).
                $this->assertCount(
                    $pluginCount,
                    $this->rollback->restoreCalls,
                    'restoreBackup() must be called for every successful update plugin, even when some fail.'
                );

                // Results must contain an entry for every plugin.
                $this->assertCount(
                    $pluginCount,
                    $result['results'],
                    'Results must contain an entry for every plugin regardless of individual failures.'
                );

                // Failures array should contain entries for the failed slugs.
                $this->assertCount(
                    count( $failSlugs ),
                    $result['failures'],
                    'Failures array must list exactly the plugins whose restoreBackup() returned WP_Error.'
                );

                // If any failures occurred, success should be false.
                if ( ! empty( $failSlugs ) ) {
                    $this->assertFalse(
                        $result['success'],
                        'Batch rollback success must be false when any individual rollback fails.'
                    );
                }

                // Verify non-failed plugins have 'success' status in results.
                foreach ( $result['results'] as $r ) {
                    $slug = $r['slug'];
                    if ( in_array( $slug, $failSlugs, true ) ) {
                        $this->assertSame( 'failed', $r['status'], "Failed plugin '$slug' must have 'failed' status." );
                    } else {
                        $this->assertSame( 'success', $r['status'], "Non-failed plugin '$slug' must have 'success' status." );
                    }
                }
            } );
    }
}
