<?php
/**
 * Property test for CLI exit code correctness.
 *
 * Feature: bulk-plugin-installer, Property 29: CLI exit code correctness
 *
 * **Validates: Requirements 20.7**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPICLIInterface;
use BPIBulkUploader;
use BPIQueueManager;
use BPICompatibilityChecker;
use BPIPluginProcessor;
use BPIProfileManager;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 29: CLI exit code correctness.
 *
 * For any CLI bulk-plugin install operation, the exit code should be 0 when
 * all plugins succeed, 1 when some plugins fail but at least one succeeds,
 * and 2 when all plugins fail.
 *
 * **Validates: Requirements 20.7**
 */
class CLIExitCodeCorrectnessTest extends TestCase {

    use TestTrait;

    private BPICLIInterface $cli;

    protected function setUp(): void {
        global $bpi_test_cli_commands, $bpi_test_cli_log, $bpi_test_cli_halt_code,
            $bpi_test_cli_format_items_calls, $bpi_test_options, $bpi_test_hooks,
            $bpi_test_installed_plugins, $bpi_test_transients,
            $bpi_test_settings_errors, $bpi_test_current_user_id, $wpdb;

        $bpi_test_cli_commands           = array();
        $bpi_test_cli_log                = array();
        $bpi_test_cli_halt_code          = null;
        $bpi_test_cli_format_items_calls = array();
        $bpi_test_options                = array();
        $bpi_test_hooks                  = array();
        $bpi_test_installed_plugins      = array();
        $bpi_test_transients             = array();
        $bpi_test_settings_errors        = array();
        $bpi_test_current_user_id        = 1;

        $bpi_test_options['bpi_auto_activate'] = false;
        $bpi_test_options['bpi_auto_rollback'] = true;

        if ( isset( $wpdb ) && method_exists( $wpdb, 'reset_bpi_log' ) ) {
            $wpdb->reset_bpi_log();
        }

        $rollback  = new BPIRollbackManager();
        $logger    = new BPILogManager();
        $settings  = new BPISettingsManager();
        $processor = new BPIPluginProcessor( $rollback, $logger, $settings );

        $this->cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );
    }

    protected function tearDown(): void {
        global $bpi_test_cli_commands, $bpi_test_cli_log, $bpi_test_cli_halt_code,
            $bpi_test_cli_format_items_calls, $bpi_test_options, $bpi_test_hooks,
            $bpi_test_installed_plugins, $bpi_test_transients;

        $bpi_test_cli_commands           = array();
        $bpi_test_cli_log                = array();
        $bpi_test_cli_halt_code          = null;
        $bpi_test_cli_format_items_calls = array();
        $bpi_test_options                = array();
        $bpi_test_hooks                  = array();
        $bpi_test_installed_plugins      = array();
        $bpi_test_transients             = array();
    }

    /**
     * Build a compatible plugin data array (will succeed in dry run).
     *
     * @param int    $index  Plugin index for unique slug.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makeSuccessPlugin( int $index, string $action ): array {
        $slug = 'cli-exit-ok-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'CLI Exit OK Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'file_name'         => $slug . '.zip',
            'file_size'         => 10000,
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '1.0.0',
            'plugin_author'     => 'Test Author',
            'plugin_description' => 'Test plugin.',
            'installed_version' => 'update' === $action ? '0.9.0' : '',
            'requires_php'      => '',
            'requires_wp'       => '',
        );
    }

    /**
     * Build an incompatible plugin data array (will fail in dry run).
     *
     * @param int    $index  Plugin index for unique slug.
     * @param string $action 'install' or 'update'.
     * @return array Plugin data.
     */
    private function makeFailPlugin( int $index, string $action ): array {
        $slug = 'cli-exit-fail-' . $index;
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'CLI Exit Fail Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'file_name'         => $slug . '.zip',
            'file_size'         => 10000,
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '2.0.0',
            'plugin_author'     => 'Test Author',
            'plugin_description' => 'Incompatible test plugin.',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
            'requires_php'      => '99.0.0',
            'requires_wp'       => '',
            'compatibility_issues' => array(
                array(
                    'type'     => 'php_version',
                    'required' => '99.0.0',
                    'current'  => PHP_VERSION,
                    'message'  => 'Requires PHP 99.0.0',
                ),
            ),
        );
    }

    /**
     * Advance the pseudo-random number generator.
     *
     * @param int $rng Current RNG state.
     * @return int Next RNG state.
     */
    private function advanceRng( int $rng ): int {
        return ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
    }

    /**
     * Pick an action string based on the current RNG state.
     *
     * @param int $rng Current RNG state.
     * @return string 'install' or 'update'.
     */
    private function pickAction( int $rng ): string {
        return ( $rng % 2 === 0 ) ? 'install' : 'update';
    }

    /**
     * Build a mixed batch of success/fail plugins, guaranteeing at least one of each.
     *
     * @param int $count Number of plugins.
     * @param int $seed  RNG seed.
     * @return array Plugin data arrays.
     */
    private function buildMixedBatch( int $count, int $seed ): array {
        $rng         = $seed;
        $plugins     = array();
        $hasSuccess  = false;
        $hasFailure  = false;

        for ( $i = 0; $i < $count; $i++ ) {
            $rng    = $this->advanceRng( $rng );
            $action = $this->pickAction( $rng );
            $rng    = $this->advanceRng( $rng );

            if ( $rng % 2 === 0 ) {
                $plugins[]  = $this->makeSuccessPlugin( $i, $action );
                $hasSuccess = true;
            } else {
                $plugins[]  = $this->makeFailPlugin( $i, $action );
                $hasFailure = true;
            }
        }

        if ( ! $hasSuccess ) {
            $rng        = $this->advanceRng( $rng );
            $plugins[0] = $this->makeSuccessPlugin( 0, $this->pickAction( $rng ) );
        }
        if ( ! $hasFailure ) {
            $rng                       = $this->advanceRng( $rng );
            $plugins[ $count - 1 ]     = $this->makeFailPlugin( $count - 1, $this->pickAction( $rng ) );
        }

        return $plugins;
    }

    /**
     * Property 29a: Exit code is 0 when all plugins succeed.
     *
     * Generate batches of 1-20 plugins where all are compatible,
     * run in dry-run mode, and verify exit code is 0.
     *
     * **Validates: Requirements 20.7**
     */
    public function test_exit_code_zero_when_all_succeed(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng       = $this->advanceRng( $rng );
                    $plugins[] = $this->makeSuccessPlugin( $i, $this->pickAction( $rng ) );
                }

                $exit_code = $this->cli->processWithProgress( $plugins, true );

                $this->assertSame(
                    0,
                    $exit_code,
                    "Exit code must be 0 when all $count plugins succeed. Got: $exit_code"
                );
            } );
    }

    /**
     * Property 29b: Exit code is 1 on partial failure.
     *
     * Generate batches of 2-20 plugins with a mix of successes and failures
     * (at least one of each), run in dry-run mode, and verify exit code is 1.
     *
     * **Validates: Requirements 20.7**
     */
    public function test_exit_code_one_on_partial_failure(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 2, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $plugins = $this->buildMixedBatch( $count, $seed );

                $exit_code = $this->cli->processWithProgress( $plugins, true );

                $this->assertSame(
                    1,
                    $exit_code,
                    "Exit code must be 1 for partial failure with $count plugins. Got: $exit_code"
                );
            } );
    }

    /**
     * Property 29c: Exit code is 2 when all plugins fail.
     *
     * Generate batches of 1-20 plugins where all are incompatible,
     * run in dry-run mode, and verify exit code is 2.
     *
     * **Validates: Requirements 20.7**
     */
    public function test_exit_code_two_when_all_fail(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng       = $this->advanceRng( $rng );
                    $plugins[] = $this->makeFailPlugin( $i, $this->pickAction( $rng ) );
                }

                $exit_code = $this->cli->processWithProgress( $plugins, true );

                $this->assertSame(
                    2,
                    $exit_code,
                    "Exit code must be 2 when all $count plugins fail. Got: $exit_code"
                );
            } );
    }
}
