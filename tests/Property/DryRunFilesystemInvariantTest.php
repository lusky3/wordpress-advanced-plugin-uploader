<?php
/**
 * Property test for dry run filesystem invariant.
 *
 * Feature: bulk-plugin-installer, Property 27: Dry run filesystem invariant
 *
 * **Validates: Requirements 19.2, 19.5**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIPluginProcessor;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that points getPluginDir to a real temp directory
 * so we can verify the filesystem is untouched after a dry run.
 */
class DryRunFilesystemTestableProcessor extends BPIPluginProcessor {

    /**
     * Base directory for simulated plugin directories.
     *
     * @var string
     */
    public string $pluginBaseDir = '';

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        // Real runs would write files — dry run should never reach here.
        // If called, create a marker file to prove filesystem was modified.
        $slug = explode( '/', $plugin_file )[0] ?? '';
        $dir  = $this->pluginBaseDir . '/' . $slug;
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $dir . '/UPGRADER_WAS_CALLED.txt', 'modified' );
        return true;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return $this->pluginBaseDir . '/' . $slug;
    }

    /** @inheritDoc */
    protected function isPluginActive( string $plugin_file ): bool {
        return false;
    }

    /** @inheritDoc */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return null;
    }
}


/**
 * Property 27: Dry run filesystem invariant.
 *
 * For any dry run operation, the WordPress plugin directory filesystem
 * state should be identical before and after the dry run. The upload
 * queue should remain intact so the user can proceed to actual
 * installation without re-uploading.
 *
 * **Validates: Requirements 19.2, 19.5**
 */
class DryRunFilesystemInvariantTest extends TestCase {

    use TestTrait;

    private DryRunFilesystemTestableProcessor $processor;

    /**
     * Base temp directory for this test run.
     *
     * @var string
     */
    private string $baseDir = '';

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors,
            $bpi_test_transients, $bpi_test_current_user_id;

        $bpi_test_options         = array();
        $bpi_test_settings_errors = array();
        $bpi_test_current_user_id = 1;
        $bpi_test_transients      = array();
        $wpdb->reset_bpi_log();

        $bpi_test_options['bpi_auto_activate'] = false;
        $bpi_test_options['bpi_auto_rollback'] = true;

        $this->baseDir = sys_get_temp_dir() . '/bpi_pbt_dryrun_fs_' . getmypid();
        if ( ! is_dir( $this->baseDir ) ) {
            mkdir( $this->baseDir, 0755, true );
        }

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        $this->processor                 = new DryRunFilesystemTestableProcessor( $rollback, $logger, $settings );
        $this->processor->pluginBaseDir = $this->baseDir;
    }

    protected function tearDown(): void {
        $this->recursiveDelete( $this->baseDir );
    }

    /**
     * Recursively snapshot a directory tree into a sorted associative array.
     *
     * Returns [ 'relative/path' => md5_of_contents ] for files,
     * and [ 'relative/path/' => 'dir' ] for directories.
     *
     * @param string $dir  Directory to snapshot.
     * @return array Sorted snapshot.
     */
    private function snapshotDir( string $dir ): array {
        $snapshot = array();

        if ( ! is_dir( $dir ) ) {
            return $snapshot;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $rel = ltrim( str_replace( $dir, '', $item->getPathname() ), '/\\' );
            $rel = str_replace( '\\', '/', $rel );

            if ( $item->isDir() ) {
                $snapshot[ $rel . '/' ] = 'dir';
            } else {
                $snapshot[ $rel ] = md5_file( $item->getPathname() );
            }
        }

        ksort( $snapshot );
        return $snapshot;
    }

    /**
     * Build a plugin data array.
     *
     * @param int    $index  Plugin index.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makePlugin( int $index, string $action ): array {
        $slug = 'dryfs-plugin-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'DryFS Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '2.0.0',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
        );
    }

    /**
     * Seed existing plugin directories for "update" plugins so the
     * filesystem has real content to potentially modify.
     *
     * @param array $plugins Array of plugin data arrays.
     */
    private function seedPluginDirs( array $plugins ): void {
        foreach ( $plugins as $p ) {
            if ( 'update' === $p['action'] ) {
                $dir = $this->baseDir . '/' . $p['slug'];
                if ( ! is_dir( $dir ) ) {
                    mkdir( $dir, 0755, true );
                }
                file_put_contents(
                    $dir . '/' . $p['slug'] . '.php',
                    '<?php // ' . $p['slug'] . ' v1.0.0'
                );
            }
        }
    }

    /**
     * Property 27: Filesystem state is identical before and after dry run.
     *
     * Generate random sets of plugins (varying counts, mix of installs
     * and updates), snapshot the filesystem, run dry run, and verify
     * the filesystem is unchanged.
     *
     * **Validates: Requirements 19.2, 19.5**
     */
    public function testDryRunDoesNotModifyFilesystem(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),   // plugin count
                Generator\choose( 0, 32767 ) // seed for action mix
            )
            ->then( function ( int $count, int $seed ): void {
                // Clean the base dir between iterations.
                $this->recursiveDelete( $this->baseDir );
                mkdir( $this->baseDir, 0755, true );

                // Build plugins with a random mix of install/update actions.
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $plugins[] = $this->makePlugin( $i, $action );
                }

                // Seed directories for update plugins.
                $this->seedPluginDirs( $plugins );

                // Snapshot before.
                $before = $this->snapshotDir( $this->baseDir );

                // Run dry run.
                $this->processor->processBatch( $plugins, true );

                // Snapshot after.
                $after = $this->snapshotDir( $this->baseDir );

                // Filesystem must be identical.
                $this->assertSame(
                    $before,
                    $after,
                    'Filesystem state must be identical before and after dry run. '
                    . 'Count=' . $count . ' Seed=' . $seed
                );
            } );
    }

    /**
     * Property 27b: Upload queue remains intact after dry run.
     *
     * Generate a queue of plugins, store them in the transient,
     * run dry run, and verify the queue transient is unchanged.
     *
     * **Validates: Requirements 19.2, 19.5**
     */
    public function testDryRunPreservesUploadQueue(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),   // plugin count
                Generator\choose( 0, 32767 ) // seed for action mix
            )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_transients;

                // Clean the base dir between iterations.
                $this->recursiveDelete( $this->baseDir );
                mkdir( $this->baseDir, 0755, true );

                // Build plugins with a random mix of install/update actions.
                $rng        = $seed;
                $plugins    = array();
                $queueItems = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $plugin    = $this->makePlugin( $i, $action );
                    $plugins[] = $plugin;

                    $queueItems[] = array(
                        'slug'      => $plugin['slug'],
                        'file_path' => $plugin['file_path'],
                        'file_size' => 1000 + $i * 500,
                    );
                }

                // Seed directories for update plugins.
                $this->seedPluginDirs( $plugins );

                // Store queue in transient (user_id = 1).
                $queue_key = 'bpi_queue_1';
                $bpi_test_transients[ $queue_key ] = array(
                    'value'      => $queueItems,
                    'expiration' => 3600,
                );

                // Deep-copy the queue for comparison.
                $queue_before = json_decode( json_encode( $queueItems ), true );

                // Run dry run.
                $this->processor->processBatch( $plugins, true );

                // Queue transient must still exist and be unchanged.
                $this->assertArrayHasKey(
                    $queue_key,
                    $bpi_test_transients,
                    'Queue transient must still exist after dry run.'
                );

                $queue_after = $bpi_test_transients[ $queue_key ]['value'];

                $this->assertSame(
                    $queue_before,
                    $queue_after,
                    'Upload queue must be identical before and after dry run. '
                    . 'Count=' . $count . ' Seed=' . $seed
                );

                $this->assertCount(
                    $count,
                    $queue_after,
                    'Queue item count must remain unchanged after dry run.'
                );

                // Clean up transient for next iteration.
                unset( $bpi_test_transients[ $queue_key ] );
            } );
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path Directory path.
     */
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
