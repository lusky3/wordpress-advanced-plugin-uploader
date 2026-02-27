<?php
/**
 * Property test for dry run produces simulated results.
 *
 * Feature: bulk-plugin-installer, Property 28: Dry run produces simulated results
 *
 * **Validates: Requirements 19.3**
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
 * so we can run dry-run processing without real filesystem operations.
 */
class DryRunSimulatedResultsTestableProcessor extends BPIPluginProcessor {

    /**
     * Track whether runUpgrader was ever called (it should NOT be during dry run).
     *
     * @var bool
     */
    public bool $upgraderWasCalled = false;

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        $this->upgraderWasCalled = true;
        return true;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_pbt_dryrun_sim_' . $slug;
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
 * Property 28: Dry run produces simulated results.
 *
 * For any dry run operation, the results should show what would have been
 * installed, updated, skipped, or flagged as incompatible, matching the
 * same validation logic as a real installation.
 *
 * **Validates: Requirements 19.3**
 */
class DryRunSimulatedResultsTest extends TestCase {

    use TestTrait;

    private DryRunSimulatedResultsTestableProcessor $processor;

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

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        $this->processor = new DryRunSimulatedResultsTestableProcessor( $rollback, $logger, $settings );
    }

    /**
     * Build a compatible plugin data array.
     *
     * @param int    $index  Plugin index.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makeCompatiblePlugin( int $index, string $action ): array {
        $slug = 'drsim-compat-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'DRSim Compatible Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '2.0.0',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
            'requires_php'      => '7.4',
            'requires_wp'       => '5.0',
        );
    }

    /**
     * Build an incompatible plugin data array (requires PHP 99.0.0).
     *
     * @param int    $index  Plugin index.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makeIncompatiblePlugin( int $index, string $action ): array {
        $slug = 'drsim-incompat-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'DRSim Incompatible Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '3.0.0',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
            'requires_php'      => '99.0.0',
            'requires_wp'       => '5.0',
        );
    }

    /**
     * Property 28a: Every dry run result has is_dry_run = true.
     *
     * Generate random sets of plugins with varying compatibility,
     * run dry run, and verify every result is marked as dry run.
     *
     * **Validates: Requirements 19.3**
     */
    public function testEveryDryRunResultHasIsDryRunTrue(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),   // total plugin count
                Generator\choose( 0, 32767 ) // seed for compatibility/action mix
            )
            ->then( function ( int $count, int $seed ): void {
                $this->processor->upgraderWasCalled = false;

                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    if ( $rng % 3 === 0 ) {
                        $plugins[] = $this->makeIncompatiblePlugin( $i, $action );
                    } else {
                        $plugins[] = $this->makeCompatiblePlugin( $i, $action );
                    }
                }

                $results = $this->processor->processBatch( $plugins, true );

                $this->assertCount( $count, $results, 'Result count must match input count.' );

                foreach ( $results as $idx => $result ) {
                    $this->assertArrayHasKey(
                        'is_dry_run',
                        $result,
                        "Result at index $idx must have 'is_dry_run' key."
                    );
                    $this->assertTrue(
                        $result['is_dry_run'],
                        "Result at index $idx must have is_dry_run = true."
                    );
                }
            } );
    }

    /**
     * Property 28b: Compatible plugins get status 'success' with descriptive messages;
     * incompatible plugins get status 'incompatible' with compatibility_issues populated.
     *
     * **Validates: Requirements 19.3**
     */
    public function testDryRunStatusMatchesCompatibility(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),   // total plugin count
                Generator\choose( 0, 32767 ) // seed for mix
            )
            ->then( function ( int $count, int $seed ): void {
                $this->processor->upgraderWasCalled = false;

                $rng     = $seed;
                $plugins = array();
                $expect  = array(); // Track expected status per index.
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    if ( $rng % 3 === 0 ) {
                        $plugins[] = $this->makeIncompatiblePlugin( $i, $action );
                        $expect[]  = 'incompatible';
                    } else {
                        $plugins[] = $this->makeCompatiblePlugin( $i, $action );
                        $expect[]  = 'success';
                    }
                }

                $results = $this->processor->processBatch( $plugins, true );

                foreach ( $results as $idx => $result ) {
                    if ( 'incompatible' === $expect[ $idx ] ) {
                        $this->assertSame(
                            'incompatible',
                            $result['status'],
                            "Incompatible plugin at index $idx must have status 'incompatible'."
                        );
                        $this->assertArrayHasKey(
                            'compatibility_issues',
                            $result,
                            "Incompatible plugin at index $idx must have 'compatibility_issues'."
                        );
                        $this->assertNotEmpty(
                            $result['compatibility_issues'],
                            "Incompatible plugin at index $idx must have non-empty compatibility_issues."
                        );
                    } else {
                        $this->assertSame(
                            'success',
                            $result['status'],
                            "Compatible plugin at index $idx must have status 'success'."
                        );
                    }
                }
            } );
    }

    /**
     * Property 28c: The action field in each result matches the input action.
     *
     * **Validates: Requirements 19.3**
     */
    public function testDryRunActionFieldMatchesInput(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    if ( $rng % 3 === 0 ) {
                        $plugins[] = $this->makeIncompatiblePlugin( $i, $action );
                    } else {
                        $plugins[] = $this->makeCompatiblePlugin( $i, $action );
                    }
                }

                $results = $this->processor->processBatch( $plugins, true );

                foreach ( $results as $idx => $result ) {
                    $this->assertSame(
                        $plugins[ $idx ]['action'],
                        $result['action'],
                        "Result action at index $idx must match input action."
                    );
                }
            } );
    }

    /**
     * Property 28d: Summary counts match — installed + incompatible = total for dry runs
     * (no failures since no real operations occur).
     *
     * **Validates: Requirements 19.3**
     */
    public function testDryRunSummaryCountsMatch(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    if ( $rng % 3 === 0 ) {
                        $plugins[] = $this->makeIncompatiblePlugin( $i, $action );
                    } else {
                        $plugins[] = $this->makeCompatiblePlugin( $i, $action );
                    }
                }

                $this->processor->processBatch( $plugins, true );
                $summary = $this->processor->getBatchSummary();

                $successCount = $summary['installed'] + $summary['updated'];
                $totalAccounted = $successCount + $summary['incompatible'];

                $this->assertSame(
                    $summary['total'],
                    $totalAccounted,
                    'installed + updated + incompatible must equal total. '
                    . "Got: installed={$summary['installed']}, updated={$summary['updated']}, "
                    . "incompatible={$summary['incompatible']}, total={$summary['total']}"
                );

                $this->assertSame(
                    0,
                    $summary['failed'],
                    'Dry run should have zero failures since no real operations occur.'
                );
            } );
    }

    /**
     * Property 28e: Every dry run result contains "No changes were made" message.
     *
     * **Validates: Requirements 19.3**
     */
    public function testEveryDryRunResultContainsNoChangesMessage(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    if ( $rng % 3 === 0 ) {
                        $plugins[] = $this->makeIncompatiblePlugin( $i, $action );
                    } else {
                        $plugins[] = $this->makeCompatiblePlugin( $i, $action );
                    }
                }

                $results = $this->processor->processBatch( $plugins, true );

                foreach ( $results as $idx => $result ) {
                    $allMessages = implode( ' ', $result['messages'] );
                    $this->assertStringContainsString(
                        'No changes were made',
                        $allMessages,
                        "Result at index $idx must contain 'No changes were made' message."
                    );
                }
            } );
    }
}
