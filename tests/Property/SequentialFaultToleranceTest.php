<?php
/**
 * Property test for sequential processing with fault tolerance.
 *
 * Feature: bulk-plugin-installer, Property 7: Sequential processing with fault tolerance
 *
 * **Validates: Requirements 4.1, 4.5**
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
 * to simulate install/update behavior with configurable failure points.
 */
class FaultTolerantTestableProcessor extends BPIPluginProcessor {

    /**
     * Upgrader results keyed by slug. true = success, WP_Error = failure.
     *
     * @var array<string, true|\WP_Error>
     */
    public array $upgraderResults = array();

    /**
     * Default upgrader result when no per-slug result is set.
     *
     * @var true|\WP_Error
     */
    public $defaultUpgraderResult = true;

    /**
     * Simulated active plugins (plugin_file => bool).
     *
     * @var array<string, bool>
     */
    public array $activePlugins = array();

    /**
     * Simulated activation results (plugin_file => null|\WP_Error).
     *
     * @var array<string, null|\WP_Error>
     */
    public array $activationResults = array();

    /**
     * Track which slugs the upgrader was called for, in order.
     *
     * @var array<string>
     */
    public array $upgraderCallOrder = array();

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        $slug = explode( '/', $plugin_file )[0] ?? '';
        $this->upgraderCallOrder[] = $slug;

        if ( isset( $this->upgraderResults[ $slug ] ) ) {
            return $this->upgraderResults[ $slug ];
        }

        return $this->defaultUpgraderResult;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_pbt_fault_' . $slug;
    }

    /** @inheritDoc */
    protected function isPluginActive( string $plugin_file ): bool {
        return $this->activePlugins[ $plugin_file ] ?? false;
    }

    /** @inheritDoc */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        if ( isset( $this->activationResults[ $plugin_file ] ) ) {
            return $this->activationResults[ $plugin_file ];
        }
        return null;
    }
}

/**
 * Property 7: Sequential processing with fault tolerance.
 *
 * For any batch of selected plugins, the Plugin_Processor should process them
 * sequentially, and if any plugin fails, it should continue processing the
 * remaining plugins without aborting the batch.
 *
 * **Validates: Requirements 4.1, 4.5**
 */
class SequentialFaultToleranceTest extends TestCase {

    use TestTrait;

    private FaultTolerantTestableProcessor $processor;

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors;

        $bpi_test_options         = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();

        $bpi_test_options['bpi_auto_activate'] = false;
        $bpi_test_options['bpi_auto_rollback'] = true;

        $rollback  = new BPIRollbackManager();
        $logger    = new BPILogManager();
        $settings  = new BPISettingsManager();

        $this->processor = new FaultTolerantTestableProcessor( $rollback, $logger, $settings );
    }

    /**
     * Build a plugin data array for a given index.
     */
    private function makePlugin( int $index ): array {
        $slug = 'plugin-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => 'install',
            'plugin_name'       => 'Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '1.0.0',
            'installed_version' => '',
        );
    }

    /**
     * Determine random failure indices from a seed, ensuring at least one failure and one success.
     *
     * @param int $batchSize Batch size.
     * @param int $seed      Random seed.
     * @return array Failure indices.
     */
    private function determineFailures( int $batchSize, int $seed ): array {
        $rng            = $seed;
        $failureIndices = array();
        for ( $i = 0; $i < $batchSize; $i++ ) {
            $rng = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
            if ( $rng % 3 === 0 ) {
                $failureIndices[] = $i;
            }
        }

        // Guarantee at least one failure.
        if ( empty( $failureIndices ) ) {
            $failureIndices[] = $seed % $batchSize;
        }

        // Guarantee at least one success.
        if ( count( $failureIndices ) === $batchSize ) {
            $removeIdx      = $seed % $batchSize;
            $failureIndices = array_values( array_diff( $failureIndices, array( $removeIdx ) ) );
        }

        return $failureIndices;
    }

    /**
     * Configure the processor with failure results for given indices.
     *
     * @param array $failureIndices Indices that should fail.
     */
    private function configureFailures( array $failureIndices ): void {
        foreach ( $failureIndices as $idx ) {
            $slug = 'plugin-' . $idx;
            $this->processor->upgraderResults[ $slug ] = new \WP_Error(
                'install_failed',
                'Simulated failure for ' . $slug
            );
        }
    }

    /**
     * Assert each result has the expected slug and status.
     *
     * @param array $results    Batch results.
     * @param array $failureSet Flipped failure indices.
     * @param int   $batchSize  Batch size.
     */
    private function assertResultStatuses( array $results, array $failureSet, int $batchSize ): void {
        for ( $i = 0; $i < $batchSize; $i++ ) {
            $result       = $results[ $i ];
            $expectedSlug = 'plugin-' . $i;

            $this->assertSame( $expectedSlug, $result['slug'], "Result at index $i must have slug '$expectedSlug'." );

            $expectedStatus = isset( $failureSet[ $i ] ) ? 'failed' : 'success';
            $this->assertSame( $expectedStatus, $result['status'], "Plugin at index $i should have status '$expectedStatus'." );
        }
    }

    /**
     * Property 7: All plugins in a batch are processed regardless of failures.
     *
     * For any batch of N plugins where a random subset fails:
     * 1. All N plugins should have results (count == input count)
     * 2. Failed plugins should have status 'failed'
     * 3. Successful plugins should have status 'success'
     * 4. The order of results matches the order of input
     * 5. Processing does NOT stop at the first failure
     *
     * **Validates: Requirements 4.1, 4.5**
     */
    public function test_all_plugins_processed_despite_failures(): void {
        $this
            ->forAll(
                Generator\choose( 2, 15 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $batchSize, int $seed ): void {
                $this->processor->upgraderResults       = array();
                $this->processor->upgraderCallOrder     = array();
                $this->processor->defaultUpgraderResult = true;

                $plugins = array();
                for ( $i = 0; $i < $batchSize; $i++ ) {
                    $plugins[] = $this->makePlugin( $i );
                }

                $failureIndices = $this->determineFailures( $batchSize, $seed );
                $this->configureFailures( $failureIndices );
                $failureSet = array_flip( $failureIndices );

                $results = $this->processor->processBatch( $plugins );

                // 1. All N plugins should have results.
                $this->assertCount( $batchSize, $results, "Result count must equal input count ($batchSize)." );

                // 2-4. Verify each result matches expectations.
                $this->assertResultStatuses( $results, $failureSet, $batchSize );

                // 5. Upgrader was called for every plugin in order.
                $this->assertCount( $batchSize, $this->processor->upgraderCallOrder );
                for ( $i = 0; $i < $batchSize; $i++ ) {
                    $this->assertSame( 'plugin-' . $i, $this->processor->upgraderCallOrder[ $i ] );
                }
            } );
    }
}
