<?php
/**
 * Unit tests for the BPIPluginProcessor class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIPluginProcessor;
use BPIRollbackManager;
use BPILogManager;
use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that overrides protected WordPress API methods
 * to simulate install/update/activate behavior without real WordPress.
 */
class Testable_Plugin_Processor extends BPIPluginProcessor {

    /**
     * Simulated upgrader results keyed by slug.
     * Set to true for success, WP_Error for failure.
     *
     * @var array<string, true|\WP_Error>
     */
    public array $upgrader_results = array();

    /**
     * Default upgrader result when no per-slug result is set.
     *
     * @var true|\WP_Error
     */
    public $default_upgrader_result = true;

    /**
     * Simulated active plugins (plugin_file => bool).
     *
     * @var array<string, bool>
     */
    public array $active_plugins = array();

    /**
     * Simulated activation results (plugin_file => null|\WP_Error).
     *
     * @var array<string, null|\WP_Error>
     */
    public array $activation_results = array();

    /**
     * Track which plugins were activated.
     *
     * @var array
     */
    public array $activated_plugins = array();

    /**
     * Track which plugins the upgrader was called for.
     *
     * @var array
     */
    public array $upgrader_calls = array();

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        // Extract slug from plugin_file for lookup.
        $slug = explode( '/', $plugin_file )[0] ?? '';
        $this->upgrader_calls[] = array(
            'action'      => $action,
            'file_path'   => $file_path,
            'plugin_file' => $plugin_file,
            'slug'        => $slug,
        );

        if ( isset( $this->upgrader_results[ $slug ] ) ) {
            return $this->upgrader_results[ $slug ];
        }

        return $this->default_upgrader_result;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_test_plugins/' . $slug;
    }

    /** @inheritDoc */
    protected function isPluginActive( string $plugin_file ): bool {
        return $this->active_plugins[ $plugin_file ] ?? false;
    }

    /** @inheritDoc */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        $this->activated_plugins[] = $plugin_file;

        if ( isset( $this->activation_results[ $plugin_file ] ) ) {
            return $this->activation_results[ $plugin_file ];
        }

        return null; // Success.
    }
}


/**
 * Tests for BPIPluginProcessor.
 */
class PluginProcessorTest extends TestCase {

    private Testable_Plugin_Processor $processor;
    private BPIRollbackManager $rollback;
    private BPILogManager $logger;
    private BPISettingsManager $settings;

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors;

        // Reset global state.
        $bpi_test_options        = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();

        // Set default settings.
        $bpi_test_options['bpi_auto_activate'] = false;
        $bpi_test_options['bpi_auto_rollback'] = true;

        $this->rollback  = new BPIRollbackManager();
        $this->logger    = new BPILogManager();
        $this->settings  = new BPISettingsManager();
        $this->processor = new Testable_Plugin_Processor(
            $this->rollback,
            $this->logger,
            $this->settings
        );
    }

    /**
     * Helper to create a plugin data array.
     */
    private function makePlugin(
        string $slug,
        string $action = 'install',
        ?bool $activate = null,
        string $version = '1.0.0',
        string $installed_version = ''
    ): array {
        $data = array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => ucfirst( $slug ),
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => $version,
            'installed_version' => $installed_version,
        );

        if ( null !== $activate ) {
            $data['activate'] = $activate;
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // processBatch tests
    // ------------------------------------------------------------------

    public function test_process_batch_processes_all_selected_plugins_sequentially(): void {
        $plugins = array(
            $this->makePlugin( 'alpha' ),
            $this->makePlugin( 'beta' ),
            $this->makePlugin( 'gamma' ),
        );

        $results = $this->processor->processBatch( $plugins );

        $this->assertCount( 3, $results );
        $this->assertSame( 'alpha', $results[0]['slug'] );
        $this->assertSame( 'beta', $results[1]['slug'] );
        $this->assertSame( 'gamma', $results[2]['slug'] );

        // Verify upgrader was called in order.
        $this->assertCount( 3, $this->processor->upgrader_calls );
        $this->assertSame( 'alpha', $this->processor->upgrader_calls[0]['slug'] );
        $this->assertSame( 'beta', $this->processor->upgrader_calls[1]['slug'] );
        $this->assertSame( 'gamma', $this->processor->upgrader_calls[2]['slug'] );
    }

    // ------------------------------------------------------------------
    // processPlugin tests
    // ------------------------------------------------------------------

    public function test_process_plugin_returns_success_for_successful_install(): void {
        $plugin = $this->makePlugin( 'my-plugin' );

        $results = $this->processor->processBatch( array( $plugin ) );

        $this->assertSame( 'success', $results[0]['status'] );
        $this->assertSame( 'install', $results[0]['action'] );
    }

    public function test_process_plugin_returns_failure_and_triggers_rollback_for_failed_update(): void {
        // Create a real temp directory so rollback manager can create a backup.
        $plugin_dir = sys_get_temp_dir() . '/bpi_test_plugins/updatable';
        if ( ! is_dir( $plugin_dir ) ) {
            mkdir( $plugin_dir, 0755, true );
        }
        file_put_contents( $plugin_dir . '/updatable.php', '<?php // v1' );

        $this->processor->upgrader_results['updatable'] = new \WP_Error( 'update_failed', 'Update error' );

        $plugin = $this->makePlugin( 'updatable', 'update', null, '2.0.0', '1.0.0' );

        $results = $this->processor->processBatch( array( $plugin ) );

        $this->assertSame( 'failed', $results[0]['status'] );
        $this->assertTrue( $results[0]['rolled_back'] );

        // Cleanup.
        $this->recursiveDelete( sys_get_temp_dir() . '/bpi_test_plugins' );
    }

    public function test_process_plugin_removes_partial_install_on_failed_new_install(): void {
        // Create a partial install directory.
        $plugin_dir = sys_get_temp_dir() . '/bpi_test_plugins/partial';
        if ( ! is_dir( $plugin_dir ) ) {
            mkdir( $plugin_dir, 0755, true );
        }
        file_put_contents( $plugin_dir . '/partial.php', '<?php // partial' );

        $this->processor->upgrader_results['partial'] = new \WP_Error( 'install_failed', 'Install error' );

        $plugin = $this->makePlugin( 'partial', 'install' );

        $results = $this->processor->processBatch( array( $plugin ) );

        $this->assertSame( 'failed', $results[0]['status'] );
        // Partial install directory should be removed.
        $this->assertDirectoryDoesNotExist( $plugin_dir );

        // Cleanup parent.
        $parent = sys_get_temp_dir() . '/bpi_test_plugins';
        if ( is_dir( $parent ) ) {
            $this->recursiveDelete( $parent );
        }
    }

    // ------------------------------------------------------------------
    // activatePlugin tests
    // ------------------------------------------------------------------

    public function test_activate_plugin_respects_per_plugin_toggle_over_global(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_auto_activate'] = false;

        // Per-plugin toggle says activate.
        $plugin = $this->makePlugin( 'toggled', 'install', true );

        $results = $this->processor->processBatch( array( $plugin ) );

        $this->assertTrue( $results[0]['activated'] );
        $this->assertContains( 'toggled/toggled.php', $this->processor->activated_plugins );
    }

    public function test_activate_plugin_skips_activation_for_already_active_on_update(): void {
        $plugin_file = 'active-plugin/active-plugin.php';
        $this->processor->active_plugins[ $plugin_file ] = true;

        $plugin = $this->makePlugin( 'active-plugin', 'update', true, '2.0.0', '1.0.0' );

        // Need a temp dir for backup.
        $plugin_dir = sys_get_temp_dir() . '/bpi_test_plugins/active-plugin';
        if ( ! is_dir( $plugin_dir ) ) {
            mkdir( $plugin_dir, 0755, true );
        }
        file_put_contents( $plugin_dir . '/active-plugin.php', '<?php // v1' );

        $results = $this->processor->processBatch( array( $plugin ) );

        $this->assertSame( 'success', $results[0]['status'] );
        $this->assertTrue( $results[0]['activated'] );
        // activatePlugin should NOT have been called.
        $this->assertNotContains( $plugin_file, $this->processor->activated_plugins );

        $this->recursiveDelete( sys_get_temp_dir() . '/bpi_test_plugins' );
    }

    public function test_activate_plugin_failure_leaves_plugin_installed_but_deactivated(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_auto_activate'] = true;

        $plugin_file = 'fail-activate/fail-activate.php';
        $this->processor->activation_results[ $plugin_file ] = new \WP_Error(
            'activation_error',
            'Fatal error during activation'
        );

        $plugin = $this->makePlugin( 'fail-activate' );

        $results = $this->processor->processBatch( array( $plugin ) );

        // Plugin should be successfully installed.
        $this->assertSame( 'success', $results[0]['status'] );
        // But not activated.
        $this->assertFalse( $results[0]['activated'] );
        // Should have a warning message about activation failure.
        $has_warning = false;
        foreach ( $results[0]['messages'] as $msg ) {
            if ( str_contains( $msg, 'could not be activated' ) ) {
                $has_warning = true;
                break;
            }
        }
        $this->assertTrue( $has_warning, 'Should contain activation failure warning' );
    }

    // ------------------------------------------------------------------
    // getBatchSummary tests
    // ------------------------------------------------------------------

    public function test_get_batch_summary_returns_correct_counts(): void {
        $this->processor->upgrader_results['fail1'] = new \WP_Error( 'err', 'fail' );

        $plugins = array(
            $this->makePlugin( 'install1' ),
            $this->makePlugin( 'install2' ),
            $this->makePlugin( 'fail1', 'install' ),
        );

        $this->processor->processBatch( $plugins );
        $summary = $this->processor->getBatchSummary();

        $this->assertSame( 3, $summary['total'] );
        $this->assertSame( 2, $summary['installed'] );
        $this->assertSame( 0, $summary['updated'] );
        $this->assertSame( 1, $summary['failed'] );
    }

    // ------------------------------------------------------------------
    // Fault tolerance test
    // ------------------------------------------------------------------

    public function test_process_batch_continues_after_individual_plugin_failure(): void {
        $this->processor->upgrader_results['middle'] = new \WP_Error( 'err', 'fail' );

        $plugins = array(
            $this->makePlugin( 'first' ),
            $this->makePlugin( 'middle' ),
            $this->makePlugin( 'last' ),
        );

        $results = $this->processor->processBatch( $plugins );

        $this->assertCount( 3, $results );
        $this->assertSame( 'success', $results[0]['status'] );
        $this->assertSame( 'failed', $results[1]['status'] );
        $this->assertSame( 'success', $results[2]['status'] );
    }

    // ------------------------------------------------------------------
    // Dry run tests
    // ------------------------------------------------------------------

    public function test_dry_run_returns_simulated_results_without_calling_upgrader(): void {
        $plugins = array(
            $this->makePlugin( 'dry1' ),
            $this->makePlugin( 'dry2', 'update', null, '2.0.0', '1.0.0' ),
        );

        $results = $this->processor->processBatch( $plugins, true );

        $this->assertCount( 2, $results );
        $this->assertSame( 'success', $results[0]['status'] );
        $this->assertTrue( $results[0]['is_dry_run'] ?? false );
        $this->assertSame( 'success', $results[1]['status'] );
        $this->assertTrue( $results[1]['is_dry_run'] ?? false );

        // Upgrader should NOT have been called.
        $this->assertEmpty( $this->processor->upgrader_calls );
    }

    public function test_dry_run_does_not_modify_filesystem(): void {
        // Create a temp plugin directory to verify it's untouched.
        $plugin_dir = sys_get_temp_dir() . '/bpi_test_plugins/fs-check';
        if ( ! is_dir( $plugin_dir ) ) {
            mkdir( $plugin_dir, 0755, true );
        }
        file_put_contents( $plugin_dir . '/fs-check.php', '<?php // v1.0' );
        $before_content = file_get_contents( $plugin_dir . '/fs-check.php' );

        $plugins = array(
            $this->makePlugin( 'fs-check', 'update', null, '2.0.0', '1.0.0' ),
        );

        $this->processor->processBatch( $plugins, true );

        // File should be unchanged.
        $this->assertFileExists( $plugin_dir . '/fs-check.php' );
        $this->assertSame( $before_content, file_get_contents( $plugin_dir . '/fs-check.php' ) );

        // No backup should have been created.
        $backup_dir = sys_get_temp_dir() . '/bpi-backups';
        $this->assertDirectoryDoesNotExist( $backup_dir );

        $this->recursiveDelete( sys_get_temp_dir() . '/bpi_test_plugins' );
    }

    public function test_dry_run_logs_with_is_dry_run_flag(): void {
        global $wpdb;

        $plugins = array(
            $this->makePlugin( 'log-dry', 'install', null, '1.0.0' ),
        );

        $this->processor->processBatch( $plugins, true );

        $this->assertNotEmpty( $wpdb->bpi_log_rows );
        $last_log = end( $wpdb->bpi_log_rows );
        $this->assertSame( 'log-dry', $last_log['plugin_slug'] );
        $this->assertSame( 1, $last_log['is_dry_run'] );
    }

    public function test_dry_run_returns_no_changes_message(): void {
        $plugins = array(
            $this->makePlugin( 'msg-check' ),
        );

        $results = $this->processor->processBatch( $plugins, true );

        $has_no_changes = false;
        foreach ( $results[0]['messages'] as $msg ) {
            if ( str_contains( $msg, 'No changes were made' ) ) {
                $has_no_changes = true;
                break;
            }
        }
        $this->assertTrue( $has_no_changes, 'Dry run result should contain "No changes were made" message' );
    }

    public function test_dry_run_flags_incompatible_plugins(): void {
        $plugin = $this->makePlugin( 'incompat' );
        // Set a PHP version requirement higher than current.
        $plugin['requires_php'] = '99.0.0';

        $results = $this->processor->processBatch( array( $plugin ), true );

        $this->assertSame( 'incompatible', $results[0]['status'] );
        $this->assertTrue( $results[0]['is_dry_run'] ?? false );
        $this->assertNotEmpty( $results[0]['compatibility_issues'] ?? array() );
    }

    public function test_dry_run_summary_counts_incompatible(): void {
        $compatible   = $this->makePlugin( 'compat-ok' );
        $incompatible = $this->makePlugin( 'compat-bad' );
        $incompatible['requires_php'] = '99.0.0';

        $this->processor->processBatch( array( $compatible, $incompatible ), true );
        $summary = $this->processor->getBatchSummary();

        $this->assertSame( 2, $summary['total'] );
        $this->assertSame( 1, $summary['installed'] );
        $this->assertSame( 1, $summary['incompatible'] );
    }

    public function test_dry_run_ajax_handler_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid    = false;
        $bpi_test_json_responses = array();

        $_POST['_wpnonce'] = 'bad_nonce';

        $this->processor->handleAjaxDryRun();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        $bpi_test_nonce_valid = true;
        unset( $_POST['_wpnonce'] );
    }

    public function test_dry_run_ajax_handler_rejects_insufficient_capability(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can       = false;
        $bpi_test_json_responses = array();

        $_POST['_wpnonce'] = 'valid';

        $this->processor->handleAjaxDryRun();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        $bpi_test_user_can = true;
        unset( $_POST['_wpnonce'] );
    }

    public function test_dry_run_ajax_handler_returns_is_dry_run_flag(): void {
        global $bpi_test_json_responses;
        $bpi_test_json_responses = array();

        $_POST['_wpnonce']          = 'valid';
        $_POST['selected_plugins']  = array(
            array(
                'slug'        => 'ajax-dry',
                'action'      => 'install',
                'plugin_name' => 'Ajax Dry',
                'file_path'   => '/tmp/ajax-dry.zip',
                'plugin_file' => 'ajax-dry/ajax-dry.php',
                'plugin_version' => '1.0.0',
                'installed_version' => '',
            ),
        );

        $this->processor->handleAjaxDryRun();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertTrue( $bpi_test_json_responses[0]['data']['is_dry_run'] );
        $this->assertStringContainsString( 'No changes were made', $bpi_test_json_responses[0]['data']['message'] );

        unset( $_POST['_wpnonce'], $_POST['selected_plugins'] );
    }

    public function test_dry_run_queue_remains_intact(): void {
        global $bpi_test_transients;

        // Simulate a queue with items.
        $queue_key = 'bpi_queue_1';
        $bpi_test_transients[ $queue_key ] = array(
            'value' => array(
                array( 'slug' => 'queued-a', 'file_path' => '/tmp/a.zip', 'file_size' => 1000 ),
                array( 'slug' => 'queued-b', 'file_path' => '/tmp/b.zip', 'file_size' => 2000 ),
            ),
            'expiration' => 3600,
        );

        $plugins = array(
            $this->makePlugin( 'queued-a' ),
            $this->makePlugin( 'queued-b' ),
        );

        $this->processor->processBatch( $plugins, true );

        // Queue should still be intact.
        $queue = get_transient( $queue_key );
        $this->assertIsArray( $queue );
        $this->assertCount( 2, $queue );
        $this->assertSame( 'queued-a', $queue[0]['slug'] );
        $this->assertSame( 'queued-b', $queue[1]['slug'] );

        unset( $bpi_test_transients[ $queue_key ] );
    }

    public function test_dry_run_registers_ajax_handler(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $this->processor->registerAjaxHandler();

        $dry_run_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'wp_ajax_bpi_dry_run' === $hook['hook'];
        } );

        $this->assertNotEmpty( $dry_run_hooks, 'wp_ajax_bpi_dry_run hook should be registered' );
    }

    // ------------------------------------------------------------------
    // AJAX handler tests
    // ------------------------------------------------------------------

    public function test_ajax_handler_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid    = false;
        $bpi_test_json_responses = array();

        $_POST['_wpnonce'] = 'bad_nonce';

        $this->processor->handleAjaxProcess();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );

        // Reset.
        $bpi_test_nonce_valid = true;
        unset( $_POST['_wpnonce'] );
    }

    public function test_ajax_handler_rejects_insufficient_capability(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can       = false;
        $bpi_test_json_responses = array();

        $_POST['_wpnonce'] = 'valid';

        $this->processor->handleAjaxProcess();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );

        // Reset.
        $bpi_test_user_can = true;
        unset( $_POST['_wpnonce'] );
    }

    // ------------------------------------------------------------------
    // Logging test
    // ------------------------------------------------------------------

    public function test_operations_are_logged_via_log_manager(): void {
        global $wpdb;

        $plugins = array(
            $this->makePlugin( 'logged-plugin' ),
        );

        $this->processor->processBatch( $plugins );

        $this->assertNotEmpty( $wpdb->bpi_log_rows );
        $last_log = end( $wpdb->bpi_log_rows );
        $this->assertSame( 'logged-plugin', $last_log['plugin_slug'] );
        $this->assertSame( 'install', $last_log['action'] );
        $this->assertSame( 'success', $last_log['status'] );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
