<?php
/**
 * Property test for summary count invariant.
 *
 * Feature: bulk-plugin-installer, Property 9: Summary count invariant
 *
 * **Validates: Requirements 4.6**
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
 * Testable subclass that overrides protected WordPress API methods
 * to simulate install/update behavior with configurable per-slug results.
 */
class SummaryCountTestableProcessor extends BPIPluginProcessor {

    /**
     * Upgrader results keyed by slug. true = success, WP_Error = failure.
     *
     * @var array<string, true|\WP_Error>
     */
    public array $upgraderResults = array();

    /**
     * Simulated active plugins (plugin_file => bool).
     *
     * @var array<string, bool>
     */
    public array $activePlugins = array();

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        $slug = explode( '/', $plugin_file )[0] ?? '';

        if ( isset( $this->upgraderResults[ $slug ] ) ) {
            return $this->upgraderResults[ $slug ];
        }

        return true;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_pbt_summary_' . $slug;
    }

    /** @inheritDoc */
    protected function isPluginActive( string $plugin_file ): bool {
        return $this->activePlugins[ $plugin_file ] ?? false;
    }

    /** @inheritDoc */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return null;
    }
}

/**
 * Property 9: Summary count invariant.
 *
 * For any completed batch, the summary counts (successful installs +
 * successful updates + failures) should equal the total number of
 * plugins that were selected for processing.
 *
 * **Validates: Requirements 4.6**
 */
class SummaryCountInvariantTest extends TestCase {

    use TestTrait;

    private SummaryCountTestableProcessor $processor;

    /**
     * Temp directories created during a test iteration, cleaned up after.
     *
     * @var array<string>
     */
    private array $tempDirs = array();

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors;

        $bpi_test_options         = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();

        $bpi_test_options['bpi_auto_activate'] = false;
        $bpi_test_options['bpi_auto_rollback'] = false;

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        $this->processor = new SummaryCountTestableProcessor( $rollback, $logger, $settings );
    }

    protected function tearDown(): void {
        foreach ( $this->tempDirs as $dir ) {
            if ( is_dir( $dir ) ) {
                rmdir( $dir );
            }
        }
        $this->tempDirs = array();
    }

    /**
     * Ensure a temp plugin directory exists so createBackup can find it.
     *
     * @param string $slug Plugin slug.
     */
    private function ensurePluginDir( string $slug ): void {
        $dir = sys_get_temp_dir() . '/bpi_pbt_summary_' . $slug;
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        $this->tempDirs[] = $dir;
    }

    /**
     * Build a plugin data array.
     *
     * @param int    $index  Plugin index.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makePlugin( int $index, string $action ): array {
        $slug = 'summary-plugin-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'Summary Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '2.0.0',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
        );
    }

    /**
     * Generate a batch of plugins with random actions and configure failures.
     *
     * @param int $batchSize Batch size.
     * @param int $seed      Random seed.
     * @return array{plugins: array, installed: int, updated: int, failed: int}
     */
    private function generateBatchWithFailures( int $batchSize, int $seed ): array {
        $rng       = $seed;
        $plugins   = array();
        $installed = 0;
        $updated   = 0;
        $failed    = 0;

        // Clean up temp dirs from previous iteration.
        foreach ( $this->tempDirs as $dir ) {
            if ( is_dir( $dir ) ) {
                rmdir( $dir );
            }
        }
        $this->tempDirs = array();

        for ( $i = 0; $i < $batchSize; $i++ ) {
            $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
            $action = ( $rng % 2 === 0 ) ? 'install' : 'update';

            $plugins[] = $this->makePlugin( $i, $action );
            $slug      = 'summary-plugin-' . $i;

            if ( 'update' === $action ) {
                $this->ensurePluginDir( $slug );
            }

            $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
            $fails = ( $rng % 5 < 2 );

            if ( $fails ) {
                $this->processor->upgraderResults[ $slug ] = new \WP_Error(
                    'install_failed',
                    'Simulated failure for ' . $slug
                );
                $failed++;
            } elseif ( 'install' === $action ) {
                $installed++;
            } else {
                $updated++;
            }
        }

        return array(
            'plugins'   => $plugins,
            'installed' => $installed,
            'updated'   => $updated,
            'failed'    => $failed,
        );
    }

    /**
     * Property 9: installed + updated + failed == total for any batch.
     *
     * Generate batch results with random success/failure combinations
     * and random install/update actions. Verify:
     * 1. getBatchSummary()['total'] == N (number of selected plugins)
     * 2. installed + updated + failed == total
     * 3. 'installed' counts only successful installs
     * 4. 'updated' counts only successful updates
     * 5. 'failed' counts all failures regardless of action type
     *
     * **Validates: Requirements 4.6**
     */
    public function test_summary_counts_equal_total_selected(): void {
        $this
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $batchSize, int $seed ): void {
                $this->processor->upgraderResults = array();
                $this->processor->activePlugins   = array();

                $batch = $this->generateBatchWithFailures( $batchSize, $seed );

                $this->processor->processBatch( $batch['plugins'] );
                $summary = $this->processor->getBatchSummary();

                // 1. Total equals batch size.
                $this->assertSame( $batchSize, $summary['total'] );

                // 2. installed + updated + failed == total.
                $sum = $summary['installed'] + $summary['updated'] + $summary['failed'];
                $this->assertSame( $summary['total'], $sum );

                // 3-5. Individual counts match expectations.
                $this->assertSame( $batch['installed'], $summary['installed'] );
                $this->assertSame( $batch['updated'], $summary['updated'] );
                $this->assertSame( $batch['failed'], $summary['failed'] );
            } );
    }
}
