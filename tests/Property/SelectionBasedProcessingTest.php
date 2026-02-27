<?php
/**
 * Property test for selection-based processing.
 *
 * Feature: bulk-plugin-installer, Property 5: Selection-based processing
 *
 * **Validates: Requirements 3.5, 3.6**
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
 * Testable subclass that tracks which slugs were processed.
 */
class SelectionTestableProcessor extends BPIPluginProcessor {

    /**
     * Slugs that were passed to runUpgrader (i.e. actually processed).
     *
     * @var array<string>
     */
    public array $processedSlugs = array();

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        $slug = explode( '/', $plugin_file )[0] ?? '';
        $this->processedSlugs[] = $slug;
        return true;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_pbt_selection_' . $slug;
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
 * Property 5: Selection-based processing.
 *
 * For any set of queued plugins where a subset is checked/selected,
 * the Plugin_Processor should process exactly the checked plugins
 * and skip all unchecked plugins.
 *
 * **Validates: Requirements 3.5, 3.6**
 */
class SelectionBasedProcessingTest extends TestCase {

    use TestTrait;

    private SelectionTestableProcessor $processor;

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

        $this->processor = new SelectionTestableProcessor( $rollback, $logger, $settings );
    }

    /**
     * Build a plugin data array.
     *
     * @param int $index Plugin index.
     * @return array Plugin data.
     */
    private function makePlugin( int $index ): array {
        $slug = 'sel-plugin-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => 'install',
            'plugin_name'       => 'Selection Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '1.0.0',
            'installed_version' => '',
        );
    }

    /**
     * Property 5: Only selected plugins are processed, unselected are skipped.
     *
     * Generate a queue of N plugins, randomly select a subset, pass only
     * the selected subset to processBatch(), and verify:
     * 1. Only the selected plugins are passed to processBatch()
     * 2. The results contain exactly the selected plugins (by slug)
     * 3. No unselected plugins appear in the results
     * 4. The count of results equals the count of selected plugins
     *
     * **Validates: Requirements 3.5, 3.6**
     */
    public function testOnlySelectedPluginsAreProcessed(): void {
        $this
            ->forAll(
                Generator\choose( 1, 20 ),   // total queue size
                Generator\choose( 0, 32767 ) // seed for random selection
            )
            ->then( function ( int $queueSize, int $seed ): void {
                // Reset processor state.
                $this->processor->processedSlugs = array();

                // Build the full queue.
                $fullQueue = array();
                for ( $i = 0; $i < $queueSize; $i++ ) {
                    $fullQueue[] = $this->makePlugin( $i );
                }

                // Randomly select a subset (simulating user check/uncheck).
                $rng             = $seed;
                $selectedPlugins = array();
                $selectedSlugs   = array();
                $unselectedSlugs = array();

                for ( $i = 0; $i < $queueSize; $i++ ) {
                    $rng       = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $isChecked = ( $rng % 2 === 0 );

                    if ( $isChecked ) {
                        $selectedPlugins[] = $fullQueue[ $i ];
                        $selectedSlugs[]   = $fullQueue[ $i ]['slug'];
                    } else {
                        $unselectedSlugs[] = $fullQueue[ $i ]['slug'];
                    }
                }

                // Process only the selected plugins (simulating the UI/AJAX
                // layer passing only checked plugins to processBatch).
                $results = $this->processor->processBatch( $selectedPlugins );

                // Extract slugs from results.
                $resultSlugs = array_map(
                    function ( array $r ): string {
                        return $r['slug'];
                    },
                    $results
                );

                // 1 & 2. Results contain exactly the selected plugins by slug.
                $this->assertSame(
                    $selectedSlugs,
                    $resultSlugs,
                    'Results must contain exactly the selected plugin slugs in order.'
                );

                // 3. No unselected plugins appear in the results.
                foreach ( $unselectedSlugs as $unselected ) {
                    $this->assertNotContains(
                        $unselected,
                        $resultSlugs,
                        "Unselected plugin '$unselected' must not appear in results."
                    );
                }

                // 4. Count of results equals count of selected plugins.
                $this->assertCount(
                    count( $selectedPlugins ),
                    $results,
                    'Result count must equal the number of selected plugins.'
                );

                // Also verify the upgrader was only called for selected slugs.
                $this->assertSame(
                    $selectedSlugs,
                    $this->processor->processedSlugs,
                    'The upgrader must only be called for selected plugin slugs.'
                );
            } );
    }

    /**
     * Property 5b: Empty selection produces no results.
     *
     * When no plugins are selected (all unchecked), processBatch
     * receives an empty array and produces zero results.
     *
     * **Validates: Requirements 3.5, 3.6**
     */
    public function testEmptySelectionProducesNoResults(): void {
        $this
            ->forAll(
                Generator\choose( 1, 15 ) // queue size (irrelevant since nothing is selected)
            )
            ->then( function ( int $_queueSize ): void {
                $this->processor->processedSlugs = array();

                // Simulate: user unchecks everything, so nothing is passed.
                $results = $this->processor->processBatch( array() );

                $this->assertSame(
                    array(),
                    $results,
                    'Empty selection must produce zero results.'
                );

                $this->assertSame(
                    array(),
                    $this->processor->processedSlugs,
                    'No plugins should be processed when selection is empty.'
                );
            } );
    }

    /**
     * Property 5c: Full selection processes all plugins.
     *
     * When all plugins are selected, every plugin in the queue
     * should appear in the results.
     *
     * **Validates: Requirements 3.5, 3.6**
     */
    public function testFullSelectionProcessesAll(): void {
        $this
            ->forAll(
                Generator\choose( 1, 15 ) // queue size
            )
            ->then( function ( int $queueSize ): void {
                $this->processor->processedSlugs = array();

                $allPlugins = array();
                $allSlugs   = array();
                for ( $i = 0; $i < $queueSize; $i++ ) {
                    $plugin       = $this->makePlugin( $i );
                    $allPlugins[] = $plugin;
                    $allSlugs[]   = $plugin['slug'];
                }

                // All selected — pass entire queue.
                $results     = $this->processor->processBatch( $allPlugins );
                $resultSlugs = array_map(
                    function ( array $r ): string {
                        return $r['slug'];
                    },
                    $results
                );

                $this->assertSame(
                    $allSlugs,
                    $resultSlugs,
                    'Full selection must process every plugin in the queue.'
                );

                $this->assertCount(
                    $queueSize,
                    $results,
                    'Result count must equal queue size when all are selected.'
                );
            } );
    }
}
