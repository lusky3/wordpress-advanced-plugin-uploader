<?php
/**
 * Unit tests for the BPICLIInterface class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPICLIInterface;
use BPIBulkUploader;
use BPIQueueManager;
use BPICompatibilityChecker;
use BPIPluginProcessor;
use BPIProfileManager;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WP-CLI integration: command registration, install handler,
 * preview table, progress processing, and exit codes.
 */
class CLIInterfaceTest extends TestCase {

    private const VERSION_100 = '1.0.0';
    private const CREATED_AT = '2024-01-01T00:00:00Z';
    private const PLUGIN_A_NAME = 'Plugin A';
    private const INCOMPATIBLE_PHP = '99.0.0';
    private const INCOMPATIBLE_MSG = 'Requires PHP 99.0.0';

    private BPICLIInterface $cli;
    private BPIBulkUploader $uploader;
    private BPIQueueManager $queue;
    private BPICompatibilityChecker $checker;
    private BPIPluginProcessor $processor;
    private BPIProfileManager $profiles;

    protected function setUp(): void {
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

        $this->uploader  = new BPIBulkUploader();
        $this->queue     = new BPIQueueManager();
        $this->checker   = new BPICompatibilityChecker();
        $this->processor = new BPIPluginProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );
        $this->profiles  = new BPIProfileManager();

        $this->cli = new BPICLIInterface(
            $this->uploader,
            $this->queue,
            $this->checker,
            $this->processor,
            $this->profiles
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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Build a sample plugin data array.
     */
    private function samplePlugin( string $slug = 'test-plugin', string $action = 'install' ): array {
        return array(
            'slug'              => $slug,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'file_name'         => $slug . '.zip',
            'file_size'         => 12345,
            'plugin_name'       => ucwords( str_replace( '-', ' ', $slug ) ),
            'plugin_version'    => self::VERSION_100,
            'plugin_author'     => 'Test Author',
            'plugin_description' => 'A test plugin.',
            'requires_php'      => '',
            'requires_wp'       => '',
            'action'            => $action,
            'installed_version' => 'update' === $action ? '0.9.0' : null,
            'plugin_file'       => $slug . '/' . $slug . '.php',
        );
    }

    /**
     * Get CLI log messages of a specific type.
     */
    private function getCliMessages( string $type ): array {
        global $bpi_test_cli_log;
        return array_values( array_filter( $bpi_test_cli_log, function ( $entry ) use ( $type ) {
            return $entry['type'] === $type;
        } ) );
    }

    // ---------------------------------------------------------------
    // registerCommands()
    // ---------------------------------------------------------------

    public function test_register_commands_adds_bulk_plugin_install(): void {
        global $bpi_test_cli_commands;

        $this->cli->registerCommands();

        $this->assertCount( 1, $bpi_test_cli_commands );
        $this->assertSame( 'bulk-plugin install', $bpi_test_cli_commands[0]['name'] );
    }

    public function test_register_commands_sets_callable_to_install_method(): void {
        global $bpi_test_cli_commands;

        $this->cli->registerCommands();

        $callable = $bpi_test_cli_commands[0]['callable'];
        $this->assertIsArray( $callable );
        $this->assertSame( $this->cli, $callable[0] );
        $this->assertSame( 'install', $callable[1] );
    }

    public function test_register_commands_includes_synopsis(): void {
        global $bpi_test_cli_commands;

        $this->cli->registerCommands();

        $args = $bpi_test_cli_commands[0]['args'];
        $this->assertArrayHasKey( 'synopsis', $args );
        $this->assertNotEmpty( $args['synopsis'] );
    }

    // ---------------------------------------------------------------
    // install() — no files
    // ---------------------------------------------------------------

    public function test_install_with_no_args_and_no_profile_outputs_error(): void {
        global $bpi_test_cli_halt_code;

        $this->cli->install( array(), array() );

        $errors = $this->getCliMessages( 'error' );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'No plugin ZIP files specified', $errors[0]['message'] );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }

    // ---------------------------------------------------------------
    // install() — file not found
    // ---------------------------------------------------------------

    public function test_install_with_nonexistent_file_outputs_warning(): void {
        global $bpi_test_cli_halt_code;

        $this->cli->install( array( '/nonexistent/path.zip' ), array( 'yes' => true ) );

        $warnings = $this->getCliMessages( 'warning' );
        $this->assertNotEmpty( $warnings );
        $this->assertStringContainsString( 'File not found', $warnings[0]['message'] );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }

    // ---------------------------------------------------------------
    // install() — profile not found
    // ---------------------------------------------------------------

    public function test_install_with_unknown_profile_outputs_error(): void {
        global $bpi_test_cli_halt_code;

        $this->cli->install( array(), array( 'profile' => 'nonexistent-profile' ) );

        $errors = $this->getCliMessages( 'error' );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'not found', $errors[0]['message'] );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }

    // ---------------------------------------------------------------
    // install() — profile with plugins
    // ---------------------------------------------------------------

    public function test_install_with_profile_loads_plugins(): void {
        global $bpi_test_options, $bpi_test_cli_halt_code;

        // Save a profile.
        $bpi_test_options['bpi_profiles'] = array(
            array(
                'id'         => 1,
                'name'       => 'my-profile',
                'created_at' => self::CREATED_AT,
                'plugins'    => array(
                    array( 'slug' => 'plugin-a', 'name' => self::PLUGIN_A_NAME, 'version' => self::VERSION_100 ),
                    array( 'slug' => 'plugin-b', 'name' => 'Plugin B', 'version' => '2.0.0' ),
                ),
            ),
        );

        $this->cli->install( array(), array( 'profile' => 'my-profile', 'yes' => true ) );

        $logs = $this->getCliMessages( 'log' );
        $profile_log = array_filter( $logs, function ( $l ) {
            return strpos( $l['message'], 'Loaded 2 plugin(s)' ) !== false;
        } );
        $this->assertNotEmpty( $profile_log );
    }

    // ---------------------------------------------------------------
    // install() — confirmation prompt (no --yes)
    // ---------------------------------------------------------------

    public function test_install_without_yes_halts_for_confirmation(): void {
        global $bpi_test_options, $bpi_test_cli_halt_code;

        $bpi_test_options['bpi_profiles'] = array(
            array(
                'id'      => 1,
                'name'    => 'test-profile',
                'created_at' => self::CREATED_AT,
                'plugins' => array(
                    array( 'slug' => 'plugin-a', 'name' => self::PLUGIN_A_NAME, 'version' => self::VERSION_100 ),
                ),
            ),
        );

        $this->cli->install( array(), array( 'profile' => 'test-profile' ) );

        $this->assertSame( 0, $bpi_test_cli_halt_code );
        $lines = $this->getCliMessages( 'line' );
        $prompt_lines = array_filter( $lines, function ( $l ) {
            return strpos( $l['message'], 'About to process' ) !== false;
        } );
        $this->assertNotEmpty( $prompt_lines );
    }

    // ---------------------------------------------------------------
    // displayPreviewTable()
    // ---------------------------------------------------------------

    public function test_display_preview_table_calls_format_items(): void {
        global $bpi_test_cli_format_items_calls;

        $plugins = array(
            $this->samplePlugin( 'plugin-a', 'install' ),
            $this->samplePlugin( 'plugin-b', 'update' ),
        );

        $this->cli->displayPreviewTable( $plugins );

        $this->assertCount( 1, $bpi_test_cli_format_items_calls );
    }

    public function test_display_preview_table_includes_correct_fields(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin() ) );

        $fields = $bpi_test_cli_format_items_calls[0]['fields'];
        $this->assertContains( 'Name', $fields );
        $this->assertContains( 'Version', $fields );
        $this->assertContains( 'Action', $fields );
        $this->assertContains( 'Installed Version', $fields );
    }

    public function test_display_preview_table_labels_new_install(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin( 'new-plugin', 'install' ) ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( 'New Install', $items[0]['Action'] );
    }

    public function test_display_preview_table_labels_update(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin( 'old-plugin', 'update' ) ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( 'Update', $items[0]['Action'] );
    }

    public function test_display_preview_table_shows_installed_version_for_updates(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin( 'old-plugin', 'update' ) ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( '0.9.0', $items[0]['Installed Version'] );
    }

    public function test_display_preview_table_shows_dash_for_new_install_version(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin( 'new-plugin', 'install' ) ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( '—', $items[0]['Installed Version'] );
    }

    public function test_display_preview_table_shows_plugin_name(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin( 'my-plugin' ) ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( 'My Plugin', $items[0]['Name'] );
    }

    public function test_display_preview_table_shows_version(): void {
        global $bpi_test_cli_format_items_calls;

        $this->cli->displayPreviewTable( array( $this->samplePlugin() ) );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertSame( self::VERSION_100, $items[0]['Version'] );
    }

    // ---------------------------------------------------------------
    // processWithProgress() — exit codes
    // ---------------------------------------------------------------

    public function test_process_with_progress_returns_0_on_all_success(): void {
        $plugins = array(
            $this->samplePlugin( 'plugin-a' ),
            $this->samplePlugin( 'plugin-b' ),
        );

        // Dry run mode produces success results.
        $exit_code = $this->cli->processWithProgress( $plugins, true );

        $this->assertSame( 0, $exit_code );
    }

    public function test_process_with_progress_returns_2_on_all_failure(): void {
        // Create plugins that will fail compatibility checks in dry run.
        $plugins = array(
            array_merge( $this->samplePlugin( 'plugin-a' ), array(
                'requires_php' => self::INCOMPATIBLE_PHP,
                'compatibility_issues' => array(
                    array(
                        'type'     => 'php_version',
                        'required' => self::INCOMPATIBLE_PHP,
                        'current'  => PHP_VERSION,
                        'message'  => self::INCOMPATIBLE_MSG,
                    ),
                ),
            ) ),
            array_merge( $this->samplePlugin( 'plugin-b' ), array(
                'requires_php' => self::INCOMPATIBLE_PHP,
                'compatibility_issues' => array(
                    array(
                        'type'     => 'php_version',
                        'required' => self::INCOMPATIBLE_PHP,
                        'current'  => PHP_VERSION,
                        'message'  => self::INCOMPATIBLE_MSG,
                    ),
                ),
            ) ),
        );

        $exit_code = $this->cli->processWithProgress( $plugins, true );

        $this->assertSame( 2, $exit_code );
    }

    public function test_process_with_progress_returns_1_on_partial_failure(): void {
        // Mix: one compatible, one incompatible.
        $plugins = array(
            $this->samplePlugin( 'plugin-a' ),
            array_merge( $this->samplePlugin( 'plugin-b' ), array(
                'requires_php' => self::INCOMPATIBLE_PHP,
                'compatibility_issues' => array(
                    array(
                        'type'     => 'php_version',
                        'required' => self::INCOMPATIBLE_PHP,
                        'current'  => PHP_VERSION,
                        'message'  => self::INCOMPATIBLE_MSG,
                    ),
                ),
            ) ),
        );

        $exit_code = $this->cli->processWithProgress( $plugins, true );

        $this->assertSame( 1, $exit_code );
    }

    // ---------------------------------------------------------------
    // processWithProgress() — output
    // ---------------------------------------------------------------

    public function test_process_with_progress_outputs_summary(): void {
        $plugins = array( $this->samplePlugin() );

        $this->cli->processWithProgress( $plugins, true );

        $lines = $this->getCliMessages( 'line' );
        $summary_lines = array_filter( $lines, function ( $l ) {
            return strpos( $l['message'], 'Summary:' ) !== false;
        } );
        $this->assertNotEmpty( $summary_lines );
    }

    public function test_process_with_progress_outputs_per_plugin_status(): void {
        $plugins = array(
            $this->samplePlugin( 'plugin-a' ),
            $this->samplePlugin( 'plugin-b' ),
        );

        $this->cli->processWithProgress( $plugins, true );

        $logs = $this->getCliMessages( 'log' );
        $plugin_logs = array_filter( $logs, function ( $l ) {
            return strpos( $l['message'], 'Dry run:' ) !== false;
        } );
        $this->assertCount( 2, $plugin_logs );
    }

    public function test_process_with_progress_dry_run_shows_dry_run_label(): void {
        $plugins = array( $this->samplePlugin() );

        $this->cli->processWithProgress( $plugins, true );

        $logs = $this->getCliMessages( 'log' );
        $dry_run_logs = array_filter( $logs, function ( $l ) {
            return strpos( $l['message'], 'Dry run:' ) !== false;
        } );
        $this->assertNotEmpty( $dry_run_logs );
    }

    public function test_process_with_progress_shows_warning_for_incompatible(): void {
        $plugins = array(
            array_merge( $this->samplePlugin( 'bad-plugin' ), array(
                'requires_php' => self::INCOMPATIBLE_PHP,
                'compatibility_issues' => array(
                    array(
                        'type'     => 'php_version',
                        'required' => self::INCOMPATIBLE_PHP,
                        'current'  => PHP_VERSION,
                        'message'  => self::INCOMPATIBLE_MSG,
                    ),
                ),
            ) ),
        );

        $this->cli->processWithProgress( $plugins, true );

        $warnings = $this->getCliMessages( 'warning' );
        $this->assertNotEmpty( $warnings );
        $this->assertStringContainsString( 'Bad Plugin', $warnings[0]['message'] );
    }

    // ---------------------------------------------------------------
    // Conditional registration
    // ---------------------------------------------------------------

    public function test_cli_registration_block_exists_in_main_plugin_file(): void {
        $content = file_get_contents( dirname( __DIR__, 2 ) . '/bulk-plugin-installer.php' );

        $this->assertStringContainsString( "defined( 'WP_CLI' ) && WP_CLI", $content );
        $this->assertStringContainsString( 'BPICLIInterface', $content );
        $this->assertStringContainsString( 'registerCommands', $content );
    }

    // ---------------------------------------------------------------
    // displayPreviewTable() — multiple plugins
    // ---------------------------------------------------------------

    public function test_display_preview_table_handles_multiple_plugins(): void {
        global $bpi_test_cli_format_items_calls;

        $plugins = array(
            $this->samplePlugin( 'alpha', 'install' ),
            $this->samplePlugin( 'beta', 'update' ),
            $this->samplePlugin( 'gamma', 'install' ),
        );

        $this->cli->displayPreviewTable( $plugins );

        $items = $bpi_test_cli_format_items_calls[0]['items'];
        $this->assertCount( 3, $items );
        $this->assertSame( 'Alpha', $items[0]['Name'] );
        $this->assertSame( 'Beta', $items[1]['Name'] );
        $this->assertSame( 'Gamma', $items[2]['Name'] );
    }

    // ---------------------------------------------------------------
    // install() — dry-run flag
    // ---------------------------------------------------------------

    public function test_install_with_dry_run_skips_confirmation(): void {
        global $bpi_test_options, $bpi_test_cli_halt_code;

        $bpi_test_options['bpi_profiles'] = array(
            array(
                'id'      => 1,
                'name'    => 'dry-profile',
                'created_at' => self::CREATED_AT,
                'plugins' => array(
                    array( 'slug' => 'plugin-a', 'name' => self::PLUGIN_A_NAME, 'version' => self::VERSION_100 ),
                ),
            ),
        );

        $this->cli->install( array(), array( 'profile' => 'dry-profile', 'dry-run' => true ) );

        // Should process (not halt for confirmation).
        $logs = $this->getCliMessages( 'log' );
        $dry_run_logs = array_filter( $logs, function ( $l ) {
            return strpos( $l['message'], 'dry-run mode' ) !== false;
        } );
        $this->assertNotEmpty( $dry_run_logs );
    }

    // ---------------------------------------------------------------
    // Empty profile
    // ---------------------------------------------------------------

    public function test_install_with_empty_profile_outputs_error(): void {
        global $bpi_test_options, $bpi_test_cli_halt_code;

        $bpi_test_options['bpi_profiles'] = array(
            array(
                'id'      => 1,
                'name'    => 'empty-profile',
                'created_at' => self::CREATED_AT,
                'plugins' => array(),
            ),
        );

        $this->cli->install( array(), array( 'profile' => 'empty-profile' ) );

        $errors = $this->getCliMessages( 'error' );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'contains no plugins', $errors[0]['message'] );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }
}
