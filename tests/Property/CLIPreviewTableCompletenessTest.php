<?php
/**
 * Property test for CLI preview table completeness.
 *
 * Feature: bulk-plugin-installer, Property 30: CLI preview table completeness
 *
 * **Validates: Requirements 20.3, 20.6**
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
 * Property 30: CLI preview table completeness.
 *
 * For any set of plugins passed to the CLI, the preview table should display
 * the plugin name, version, and action (install/update) for each plugin
 * before prompting for confirmation.
 *
 * **Validates: Requirements 20.3, 20.6**
 */
class CLIPreviewTableCompletenessTest extends TestCase {

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
     * Generate a random plugin name from a seed.
     *
     * @param int $seed Random seed value.
     * @return string Plugin name.
     */
    private function randomPluginName( int $seed ): string {
        $prefixes = array( 'awesome', 'super', 'mega', 'ultra', 'pro', 'starter', 'starter', 'starter' );
        $suffixes = array( 'seo', 'cache', 'forms', 'security', 'backup', 'analytics', 'gallery', 'slider' );
        $p = $prefixes[ abs( $seed ) % count( $prefixes ) ];
        $s = $suffixes[ abs( $seed >> 8 ) % count( $suffixes ) ];
        return $p . '-' . $s . '-' . abs( $seed % 10000 );
    }

    /**
     * Generate a random semver version string from a seed.
     *
     * @param int $seed Random seed value.
     * @return string Version string.
     */
    private function randomVersion( int $seed ): string {
        $major = abs( $seed ) % 10;
        $minor = abs( $seed >> 4 ) % 20;
        $patch = abs( $seed >> 8 ) % 30;
        return $major . '.' . $minor . '.' . $patch;
    }

    /**
     * Build a plugin data array for testing.
     *
     * @param string $name              Plugin name.
     * @param string $version           Plugin version.
     * @param string $action            'install' or 'update'.
     * @param string $installed_version Installed version (for updates).
     * @return array Plugin data.
     */
    private function makePlugin( string $name, string $version, string $action, string $installed_version = '' ): array {
        $slug = strtolower( str_replace( ' ', '-', $name ) );
        return array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => $name,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'file_name'         => $slug . '.zip',
            'file_size'         => 10000,
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => $version,
            'plugin_author'     => 'Test Author',
            'plugin_description' => 'A test plugin.',
            'installed_version' => $installed_version,
            'requires_php'      => '',
            'requires_wp'       => '',
        );
    }

    /**
     * Property 30a: Preview table contains all plugins.
     *
     * Generate random plugin sets (1-20 plugins) and verify that
     * format_items() is called exactly once with the same count of
     * items as the input plugins.
     *
     * **Validates: Requirements 20.3, 20.6**
     */
    public function test_preview_table_contains_all_plugins(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_cli_format_items_calls;
                $bpi_test_cli_format_items_calls = array();

                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $name   = $this->randomPluginName( $rng );
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $ver    = $this->randomVersion( $rng );

                    $installed = '';
                    if ( 'update' === $action ) {
                        $rng       = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                        $installed = $this->randomVersion( $rng );
                    }

                    $plugins[] = $this->makePlugin( $name, $ver, $action, $installed );
                }

                $this->cli->displayPreviewTable( $plugins );

                $this->assertCount(
                    1,
                    $bpi_test_cli_format_items_calls,
                    'format_items() must be called exactly once.'
                );

                $call = $bpi_test_cli_format_items_calls[0];
                $this->assertCount(
                    $count,
                    $call['items'],
                    "Preview table must contain exactly $count items matching input plugin count."
                );
            } );
    }

    /**
     * Property 30b: Preview table fields match input.
     *
     * Generate random plugin sets and verify each item in the preview
     * table has Name, Version, Action, and Installed Version fields,
     * and that Name and Version match the input plugin data.
     *
     * **Validates: Requirements 20.3, 20.6**
     */
    public function test_preview_table_fields_match_input(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_cli_format_items_calls;
                $bpi_test_cli_format_items_calls = array();

                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $name   = $this->randomPluginName( $rng );
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $ver    = $this->randomVersion( $rng );

                    $installed = '';
                    if ( 'update' === $action ) {
                        $rng       = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                        $installed = $this->randomVersion( $rng );
                    }

                    $plugins[] = $this->makePlugin( $name, $ver, $action, $installed );
                }

                $this->cli->displayPreviewTable( $plugins );

                $call  = $bpi_test_cli_format_items_calls[0];
                $items = $call['items'];

                for ( $i = 0; $i < $count; $i++ ) {
                    $item   = $items[ $i ];
                    $plugin = $plugins[ $i ];

                    $this->assertArrayHasKey( 'Name', $item, "Item $i must have 'Name' field." );
                    $this->assertArrayHasKey( 'Version', $item, "Item $i must have 'Version' field." );
                    $this->assertArrayHasKey( 'Action', $item, "Item $i must have 'Action' field." );
                    $this->assertArrayHasKey( 'Installed Version', $item, "Item $i must have 'Installed Version' field." );

                    $this->assertSame(
                        $plugin['plugin_name'],
                        $item['Name'],
                        "Item $i Name must match input plugin_name."
                    );
                    $this->assertSame(
                        $plugin['plugin_version'],
                        $item['Version'],
                        "Item $i Version must match input plugin_version."
                    );
                }
            } );
    }

    /**
     * Property 30c: Preview table action labels are correct.
     *
     * Generate random plugin sets with install and update actions and verify
     * that the Action label is 'New Install' for install actions and 'Update'
     * for update actions. For updates, Installed Version matches input; for
     * new installs, Installed Version is the em dash '—'.
     *
     * **Validates: Requirements 20.3, 20.6**
     */
    public function test_preview_table_action_labels_correct(): void {
        $this
            ->limitTo( 100 )
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 32767 )
            )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_cli_format_items_calls;
                $bpi_test_cli_format_items_calls = array();

                $rng     = $seed;
                $plugins = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = ( $rng % 2 === 0 ) ? 'install' : 'update';
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $name   = $this->randomPluginName( $rng );
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $ver    = $this->randomVersion( $rng );

                    $installed = '';
                    if ( 'update' === $action ) {
                        $rng       = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                        $installed = $this->randomVersion( $rng );
                    }

                    $plugins[] = $this->makePlugin( $name, $ver, $action, $installed );
                }

                $this->cli->displayPreviewTable( $plugins );

                $call  = $bpi_test_cli_format_items_calls[0];
                $items = $call['items'];

                for ( $i = 0; $i < $count; $i++ ) {
                    $item   = $items[ $i ];
                    $plugin = $plugins[ $i ];

                    if ( 'update' === $plugin['action'] ) {
                        $this->assertSame(
                            'Update',
                            $item['Action'],
                            "Item $i with action 'update' must have Action label 'Update'."
                        );
                        $this->assertSame(
                            $plugin['installed_version'],
                            $item['Installed Version'],
                            "Item $i update must show installed_version in 'Installed Version' field."
                        );
                    } else {
                        $this->assertSame(
                            'New Install',
                            $item['Action'],
                            "Item $i with action 'install' must have Action label 'New Install'."
                        );
                        $this->assertSame(
                            '—',
                            $item['Installed Version'],
                            "Item $i new install must show em dash '—' for 'Installed Version'."
                        );
                    }
                }
            } );
    }
}
