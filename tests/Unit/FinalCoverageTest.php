<?php
/**
 * Final coverage tests to push line coverage as close to 100% as possible.
 *
 * Covers remaining testable gaps across multiple classes:
 * - BPISettingsManager: sanitizeIntRange out-of-range, sanitizeNonNegativeInt invalid,
 *   sanitizeEmailRecipients invalid, renderSettingsPage
 * - BPIAdminPage: extractChangelogData with real ZIP changelog
 * - BPICLIInterface: buildPluginDataFromFile no-header, loadFromProfile plugin data
 * - BPIBulkUploader: zipContainsPluginHeader edge cases, getPluginSlug fallback
 * - BPIRollbackManager: recursiveCopy/recursiveDelete real filesystem
 * - BPIChangelogExtractor: readZipContents invalid ZIP, getEntriesBetween empty version
 * - BPICompatibilityChecker: checkSlugConflicts empty slug
 * - BPILogManager: createTable require_once branch
 * - BPINotificationManager: sendRollbackEmail empty recipients
 * - BPIPluginProcessor: getRequiredCapability multisite, handleInstallFailure non-WP_Error
 * - BulkPluginInstaller: deactivate with real bpi-backups dir, recursiveRmdir
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIAdminPage;
use BPIBulkUploader;
use BPIChangelogExtractor;
use BPICLIInterface;
use BPICompatibilityChecker;
use BPILogManager;
use BPINotificationManager;
use BPIPluginProcessor;
use BPIProfileManager;
use BPIQueueManager;
use BPIRollbackManager;
use BPISettingsManager;
use BulkPluginInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Final coverage tests.
 */
class FinalCoverageTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses,
               $bpi_test_options, $bpi_test_is_multisite, $bpi_test_is_network_admin,
               $bpi_test_settings_errors, $bpi_test_transients, $bpi_test_emails,
               $bpi_test_installed_plugins, $bpi_test_cli_log, $bpi_test_cli_halt_code,
               $bpi_test_cli_commands, $wpdb;

        $bpi_test_nonce_valid       = true;
        $bpi_test_user_can          = true;
        $bpi_test_json_responses    = array();
        $bpi_test_options           = array( 'admin_email' => 'admin@example.com' );
        $bpi_test_is_multisite      = false;
        $bpi_test_is_network_admin  = false;
        $bpi_test_settings_errors   = array();
        $bpi_test_transients        = array();
        $bpi_test_emails            = array();
        $bpi_test_installed_plugins = array();
        $bpi_test_cli_log           = array();
        $bpi_test_cli_halt_code     = null;
        $bpi_test_cli_commands      = array();
        $wpdb->reset_bpi_log();

        $this->tempDir = sys_get_temp_dir() . '/bpi_final_cov_' . uniqid();
        mkdir( $this->tempDir, 0755, true );

        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'], $_FILES['plugin_zip'] );
    }

    protected function tearDown(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        $bpi_test_user_can         = true;
        unset( $_POST['_wpnonce'], $_POST['selected_plugins'], $_POST['dry_run'], $_FILES['plugin_zip'] );
        $this->recursiveDelete( $this->tempDir );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeIntRange "not isset" branch (line 248)
    // ------------------------------------------------------------------

    public function test_sanitize_int_range_missing_key_returns_current_option(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_max_plugins'] = 42;

        $settings = new BPISettingsManager();
        // Omit bpi_max_plugins entirely from input.
        $result = $settings->sanitizeSettings( array(
            'bpi_auto_activate'  => false,
            'bpi_auto_rollback'  => true,
            'bpi_max_file_size'  => 0,
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => '',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        $this->assertSame( 42, $result['bpi_max_plugins'] );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeNonNegativeInt "not isset" branch (line 269)
    // ------------------------------------------------------------------

    public function test_sanitize_non_negative_int_missing_key_returns_current_option(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_max_file_size'] = 50;

        $settings = new BPISettingsManager();
        // Omit bpi_max_file_size entirely from input.
        $result = $settings->sanitizeSettings( array(
            'bpi_auto_activate'  => false,
            'bpi_max_plugins'    => 20,
            'bpi_auto_rollback'  => true,
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => '',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        $this->assertSame( 50, $result['bpi_max_file_size'] );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeEmailRecipients empty email in list (line 307)
    // ------------------------------------------------------------------

    public function test_sanitize_email_recipients_skips_empty_entries_in_list(): void {
        global $bpi_test_settings_errors;

        $settings = new BPISettingsManager();
        $result = $settings->sanitizeSettings( array(
            'bpi_auto_activate'  => false,
            'bpi_max_plugins'    => 20,
            'bpi_auto_rollback'  => true,
            'bpi_max_file_size'  => 0,
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => 'valid@example.com, , another@example.com',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        // Empty entries should be skipped, valid ones kept.
        $this->assertStringContainsString( 'valid@example.com', $result['bpi_email_recipients'] );
        $this->assertStringContainsString( 'another@example.com', $result['bpi_email_recipients'] );
        // No errors should be added for empty entries.
        $codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertNotContains( 'bpi_invalid_email_recipients', $codes );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — renderSectionDescription (line 374)
    // ------------------------------------------------------------------

    public function test_render_section_description_outputs_text(): void {
        $settings = new BPISettingsManager();

        ob_start();
        $settings->renderSectionDescription();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Configure the behavior', $output );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeIntRange out-of-range (line 252-253)
    // ------------------------------------------------------------------

    public function test_sanitize_int_range_out_of_range_returns_previous_value(): void {
        global $bpi_test_options, $bpi_test_settings_errors;
        $bpi_test_options['bpi_max_plugins'] = 15;

        $settings = new BPISettingsManager();
        $result = $settings->sanitizeSettings( array(
            'bpi_max_plugins'    => 200, // above max of 100
            'bpi_auto_activate'  => false,
            'bpi_auto_rollback'  => true,
            'bpi_max_file_size'  => 0,
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => '',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        $this->assertSame( 15, $result['bpi_max_plugins'] );
        $codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_max_plugins', $codes );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeNonNegativeInt invalid (line 269)
    // ------------------------------------------------------------------

    public function test_sanitize_non_negative_int_rejects_non_numeric(): void {
        global $bpi_test_options, $bpi_test_settings_errors;
        $bpi_test_options['bpi_max_file_size'] = 10;

        $settings = new BPISettingsManager();
        $result = $settings->sanitizeSettings( array(
            'bpi_auto_activate'  => false,
            'bpi_max_plugins'    => 20,
            'bpi_auto_rollback'  => true,
            'bpi_max_file_size'  => 'abc', // non-numeric
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => '',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        $this->assertSame( 10, $result['bpi_max_file_size'] );
        $codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_max_file_size', $codes );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — sanitizeEmailRecipients invalid (line 307)
    // ------------------------------------------------------------------

    public function test_sanitize_email_recipients_rejects_invalid_and_returns_previous(): void {
        global $bpi_test_options, $bpi_test_settings_errors;
        $bpi_test_options['bpi_email_recipients'] = 'old@example.com';

        $settings = new BPISettingsManager();
        $result = $settings->sanitizeSettings( array(
            'bpi_auto_activate'  => false,
            'bpi_max_plugins'    => 20,
            'bpi_auto_rollback'  => true,
            'bpi_max_file_size'  => 0,
            'bpi_rollback_retention' => 24,
            'bpi_email_notifications' => false,
            'bpi_email_recipients' => 'valid@example.com, not-an-email',
            'bpi_delete_data_on_uninstall' => false,
        ) );

        $this->assertSame( 'old@example.com', $result['bpi_email_recipients'] );
        $codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_email_recipients', $codes );
    }

    // ------------------------------------------------------------------
    // BPISettingsManager — renderSettingsPage (line 374)
    // ------------------------------------------------------------------

    public function test_render_settings_page_outputs_html(): void {
        $settings = new BPISettingsManager();
        $settings->registerSettings();

        ob_start();
        $settings->renderSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Bulk Plugin Installer Settings', $output );
        $this->assertStringContainsString( 'Activity Log', $output );
        $this->assertStringContainsString( 'options.php', $output );
    }

    // ------------------------------------------------------------------
    // BPINotificationManager — sendRollbackEmail empty recipients (line 141)
    // ------------------------------------------------------------------

    public function test_send_rollback_email_no_recipients_does_not_send(): void {
        global $bpi_test_options, $bpi_test_emails;
        $bpi_test_options['bpi_email_notifications'] = true;
        // No admin_email and no additional recipients.
        unset( $bpi_test_options['admin_email'] );
        $bpi_test_options['bpi_email_recipients'] = '';

        $settings = new BPISettingsManager();
        $manager  = new BPINotificationManager( $settings );

        $manager->sendRollbackEmail( array(
            'batch_id'  => 'test_batch',
            'timestamp' => '2026-01-01 00:00:00',
            'user_id'   => 1,
            'plugins'   => array(),
            'reason'    => 'Test reason',
        ) );

        $this->assertEmpty( $bpi_test_emails );
    }

    // ------------------------------------------------------------------
    // BPIChangelogExtractor — readZipContents invalid ZIP (line 74)
    // ------------------------------------------------------------------

    public function test_changelog_extract_returns_empty_for_invalid_zip(): void {
        $bad_file = $this->tempDir . '/bad.zip';
        file_put_contents( $bad_file, 'not a zip' );

        $extractor = new BPIChangelogExtractor();
        $result = $extractor->extract( $bad_file );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result['entries'] ?? array() );
    }

    // ------------------------------------------------------------------
    // BPIChangelogExtractor — getEntriesBetween empty version (line 240)
    // ------------------------------------------------------------------

    public function test_get_entries_between_filters_empty_version(): void {
        $extractor = new BPIChangelogExtractor();

        $entries = array(
            array( 'version' => '1.1.0', 'changes' => 'Change A' ),
            array( 'version' => '',      'changes' => 'No version' ),
            array( 'version' => '1.2.0', 'changes' => 'Change B' ),
        );

        $result = $extractor->getEntriesBetween( $entries, '1.0.0', '1.2.0' );

        $this->assertCount( 2, $result );
        $versions = array_column( $result, 'version' );
        $this->assertNotContains( '', $versions );
    }

    // ------------------------------------------------------------------
    // BPICompatibilityChecker — checkSlugConflicts empty slug (line 137)
    // ------------------------------------------------------------------

    public function test_check_slug_conflicts_skips_empty_slug(): void {
        $checker = new BPICompatibilityChecker();

        $queue = array(
            array( 'slug' => '' ),
            array( 'slug' => 'valid-plugin' ),
            array( 'slug' => 'valid-plugin' ),
        );

        $conflicts = $checker->checkSlugConflicts( $queue );

        // Only valid-plugin should have a conflict, empty slug is skipped.
        $this->assertArrayHasKey( 'valid-plugin', $conflicts );
        $this->assertArrayNotHasKey( '', $conflicts );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — getPluginSlug fallback to PHP filename (line 396)
    // ------------------------------------------------------------------

    public function test_get_plugin_slug_falls_back_to_php_filename(): void {
        // Create a ZIP with a PHP file at root level (no directory).
        $zip_path = $this->tempDir . '/flat-plugin.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            'my-flat-plugin.php',
            "<?php\n/**\n * Plugin Name: Flat Plugin\n * Version: 1.0.0\n */"
        );
        $zip->close();

        $uploader = new BPIBulkUploader();
        $slug = $uploader->getPluginSlug( $zip_path );

        $this->assertSame( 'my-flat-plugin', $slug );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — zipContainsPluginHeader edge cases (lines 254, 263)
    // ------------------------------------------------------------------

    public function test_validate_zip_rejects_zip_without_php_files(): void {
        $zip_path = $this->tempDir . '/no-php.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( 'readme.txt', 'Just a readme' );
        $zip->close();

        $uploader = new BPIBulkUploader();
        $result = $uploader->validateZip( $zip_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_plugin_header', $result->get_error_code() );
    }

    public function test_validate_zip_rejects_php_without_plugin_header(): void {
        $zip_path = $this->tempDir . '/no-header.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( 'plugin/plugin.php', "<?php\n// No plugin header here\necho 'hello';" );
        $zip->close();

        $uploader = new BPIBulkUploader();
        $result = $uploader->validateZip( $zip_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_plugin_header', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // BPIRollbackManager — real filesystem recursiveCopy/recursiveDelete
    // (lines 244, 248, 272, 275, 290, 304)
    // ------------------------------------------------------------------

    public function test_real_rollback_manager_backup_and_restore_cycle(): void {
        $plugin_dir = $this->tempDir . '/test-plugin';
        mkdir( $plugin_dir, 0755, true );
        file_put_contents( $plugin_dir . '/plugin.php', '<?php // test' );
        mkdir( $plugin_dir . '/subdir', 0755, true );
        file_put_contents( $plugin_dir . '/subdir/file.txt', 'content' );

        // Use a real rollback manager (not the controllable subclass).
        $manager = new class( $this->tempDir . '/backups' ) extends BPIRollbackManager {
            private string $base;
            public function __construct( string $base ) { $this->base = $base; }
            protected function getBackupBaseDir(): string { return $this->base; }
        };

        // Create backup.
        $backup_path = $manager->createBackup( $plugin_dir );
        $this->assertIsString( $backup_path );
        $this->assertDirectoryExists( $backup_path );
        $this->assertFileExists( $backup_path . '/plugin.php' );
        $this->assertFileExists( $backup_path . '/subdir/file.txt' );

        // Modify the plugin dir.
        file_put_contents( $plugin_dir . '/plugin.php', '<?php // modified' );

        // Restore backup.
        $result = $manager->restoreBackup( $backup_path, $plugin_dir );
        $this->assertTrue( $result );
        $this->assertSame( '<?php // test', file_get_contents( $plugin_dir . '/plugin.php' ) );

        // Cleanup backup.
        $manager->cleanupBackup( $backup_path );
        $this->assertDirectoryDoesNotExist( $backup_path );
    }

    public function test_real_rollback_manager_remove_partial_install(): void {
        $plugin_dir = $this->tempDir . '/partial-plugin';
        mkdir( $plugin_dir, 0755, true );
        file_put_contents( $plugin_dir . '/file.php', '<?php' );

        $manager = new class( $this->tempDir . '/backups2' ) extends BPIRollbackManager {
            private string $base;
            public function __construct( string $base ) { $this->base = $base; }
            protected function getBackupBaseDir(): string { return $this->base; }
        };

        $manager->removePartialInstall( $plugin_dir );
        $this->assertDirectoryDoesNotExist( $plugin_dir );
    }

    public function test_real_rollback_manager_backup_source_missing(): void {
        $manager = new BPIRollbackManager();
        $result = $manager->createBackup( $this->tempDir . '/nonexistent-dir' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'backup_source_missing', $result->get_error_code() );
    }

    public function test_real_rollback_manager_restore_backup_missing(): void {
        $manager = new BPIRollbackManager();
        $result = $manager->restoreBackup( $this->tempDir . '/no-backup', $this->tempDir . '/no-plugin' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'restore_backup_missing', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // BPIAdminPage — extractChangelogData with real ZIP (lines 425-440)
    // ------------------------------------------------------------------

    public function test_handle_preview_extracts_changelog_for_update(): void {
        global $bpi_test_json_responses, $bpi_test_transients, $bpi_test_installed_plugins;

        // Create a ZIP with a readme.txt containing a changelog section.
        $zip_path = $this->createPluginZipWithChangelog( 'changelog-plugin', '2.0.0' );

        // Set up the queue transient with this plugin as an update.
        $bpi_test_installed_plugins = array(
            'changelog-plugin/changelog-plugin.php' => array(
                'Name'    => 'Changelog Plugin',
                'Version' => '1.0.0',
            ),
        );

        $queue_key = 'bpi_queue_1';
        $bpi_test_transients[ $queue_key ] = array(
            'value' => array(
                array(
                    'slug'              => 'changelog-plugin',
                    'plugin_name'       => 'Changelog Plugin',
                    'plugin_version'    => '2.0.0',
                    'plugin_author'     => 'Test',
                    'plugin_description' => 'Test plugin',
                    'file_path'         => $zip_path,
                    'requires_php'      => '',
                    'requires_wp'       => '',
                ),
            ),
            'expiration' => 0,
        );

        $_POST['_wpnonce'] = 'valid';

        $page = new BPIAdminPage();
        $page->handlePreview();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $response = $bpi_test_json_responses[0];
        $this->assertTrue( $response['success'] );

        $plugins = $response['data']['plugins'];
        $this->assertCount( 1, $plugins );
        $this->assertSame( 'update', $plugins[0]['action'] );
        // Changelog data should be populated.
        $this->assertArrayHasKey( 'changelog', $plugins[0] );
    }

    // ------------------------------------------------------------------
    // BPICLIInterface — loadFromProfile with plugin data (lines 515-519)
    // ------------------------------------------------------------------

    public function test_cli_install_from_profile_with_update_action(): void {
        global $bpi_test_cli_log, $bpi_test_installed_plugins, $bpi_test_options;

        // Pre-install a plugin so the profile triggers an update action.
        $bpi_test_installed_plugins = array(
            'prof-upd/prof-upd.php' => array( 'Name' => 'Prof Upd', 'Version' => '1.0.0' ),
        );

        // Create a profile with a plugin that matches the installed one.
        $profile_manager = new BPIProfileManager();
        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'update-profile';
        $_POST['plugins']  = array(
            array( 'slug' => 'prof-upd', 'name' => 'Prof Upd', 'version' => '2.0.0' ),
        );

        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $profile_manager->handleAjaxSaveProfile();

        // Now install using the profile.
        $bpi_test_cli_log = array();

        $processor = new CLICoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );

        $cli->install( array(), array( 'profile' => 'update-profile', 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'Loaded', $combined );
        $this->assertStringContainsString( 'Summary', $combined );

        unset( $_POST['_wpnonce'], $_POST['name'], $_POST['plugins'] );
    }

    // ------------------------------------------------------------------
    // BPIPluginProcessor — getRequiredCapability multisite (line 558)
    // ------------------------------------------------------------------

    public function test_plugin_processor_ajax_process_uses_network_cap_in_multisite(): void {
        global $bpi_test_json_responses, $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array(
            'manage_network_plugins' => false,
            'install_plugins'        => true,
        );

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array(
            array( 'slug' => 'test', 'action' => 'install', 'plugin_name' => 'Test',
                   'file_path' => '/tmp/t.zip', 'plugin_file' => 'test/test.php',
                   'plugin_version' => '1.0.0', 'installed_version' => '' ),
        );

        $processor = new CoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $processor->handleAjaxProcess();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_plugin_processor_ajax_dry_run_uses_network_cap_in_multisite(): void {
        global $bpi_test_json_responses, $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array(
            'manage_network_plugins' => false,
            'install_plugins'        => true,
        );

        $_POST['_wpnonce']         = 'valid';
        $_POST['selected_plugins'] = array(
            array( 'slug' => 'test', 'action' => 'install', 'plugin_name' => 'Test',
                   'file_path' => '/tmp/t.zip', 'plugin_file' => 'test/test.php',
                   'plugin_version' => '1.0.0', 'installed_version' => '' ),
        );

        $processor = new CoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $processor->handleAjaxDryRun();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    // ------------------------------------------------------------------
    // BPIPluginProcessor — handleInstallFailure non-WP_Error path
    // ------------------------------------------------------------------

    public function test_plugin_processor_handles_non_wp_error_failure(): void {
        // Create a processor that returns a non-WP_Error, non-true result.
        $processor = new class(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        ) extends BPIPluginProcessor {
            protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
                // Return a WP_Error with a generic message to simulate unexpected result.
                return new \WP_Error( 'unexpected', 'Unexpected result' );
            }
            protected function getPluginDir( string $slug ): string {
                return sys_get_temp_dir() . '/bpi_nonwp_' . $slug;
            }
            protected function isPluginActive( string $plugin_file ): bool { return false; }
            protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null { return null; }
        };

        $plugin = array(
            'slug' => 'nonwp-fail', 'action' => 'install', 'plugin_name' => 'NonWP Fail',
            'file_path' => '/tmp/nw.zip', 'plugin_file' => 'nonwp-fail/nonwp-fail.php',
            'plugin_version' => '1.0.0', 'installed_version' => '',
        );

        $result = $processor->processPlugin( $plugin );

        $this->assertSame( 'failed', $result['status'] );
    }

    // ------------------------------------------------------------------
    // BulkPluginInstaller — deactivate with real bpi-backups dir (lines 275-297)
    // ------------------------------------------------------------------

    public function test_deactivate_removes_bpi_backups_directory(): void {
        // Create a fake bpi-backups directory in WP_CONTENT_DIR.
        $backup_dir = WP_CONTENT_DIR . '/bpi-backups';
        if ( ! is_dir( $backup_dir ) ) {
            mkdir( $backup_dir, 0755, true );
        }
        file_put_contents( $backup_dir . '/test-backup.txt', 'backup data' );
        mkdir( $backup_dir . '/subdir', 0755, true );
        file_put_contents( $backup_dir . '/subdir/nested.txt', 'nested data' );

        $plugin = BulkPluginInstaller::getInstance();
        $plugin->deactivate();

        $this->assertDirectoryDoesNotExist( $backup_dir );
    }

    // ------------------------------------------------------------------
    // BPILogManager — createTable (line 71 — require_once branch)
    // ------------------------------------------------------------------

    public function test_log_manager_create_table_succeeds(): void {
        global $wpdb;

        $log_manager = new BPILogManager();
        $log_manager->createTable();

        $this->assertTrue( $wpdb->bpi_log_table_exists );
    }

    // ------------------------------------------------------------------
    // BPICLIInterface — buildPluginDataFromFile no-header (lines 416-423)
    // ------------------------------------------------------------------

    public function test_cli_install_with_empty_plugin_name_warns_no_header(): void {
        global $bpi_test_cli_log;

        // Create a ZIP that passes zipContainsPluginHeader (has "Plugin Name:")
        // but extractPluginHeaders returns empty plugin_name.
        // The trick: end the file content immediately after "Plugin Name:" with no
        // trailing newline or content, so the \s* in the extraction regex has nothing
        // to bridge to and .+ cannot match anything.
        $zip_path = $this->tempDir . '/empty-name-cli.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            'empty-name/empty-name.php',
            "<?php\n/**\n * Plugin Name:"
        );
        $zip->close();

        $processor = new CLICoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );

        $cli->install( array( $zip_path ), array( 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'No valid plugin header', $combined );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — extractPluginHeaders edge cases (lines 301, 306, 312, 318)
    // ------------------------------------------------------------------

    public function test_extract_plugin_headers_returns_empty_for_invalid_zip(): void {
        $bad_file = $this->tempDir . '/bad-headers.zip';
        file_put_contents( $bad_file, 'not a zip' );

        $uploader = new BPIBulkUploader();
        $headers = $uploader->extractPluginHeaders( $bad_file );

        $this->assertSame( '', $headers['plugin_name'] );
    }

    public function test_extract_plugin_headers_skips_non_php_files(): void {
        $zip_path = $this->tempDir . '/txt-only.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( 'plugin/readme.txt', 'Plugin Name: Fake' );
        $zip->close();

        $uploader = new BPIBulkUploader();
        $headers = $uploader->extractPluginHeaders( $zip_path );

        $this->assertSame( '', $headers['plugin_name'] );
    }

    public function test_extract_plugin_headers_skips_php_without_header(): void {
        $zip_path = $this->tempDir . '/no-header-extract.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString( 'plugin/plugin.php', "<?php\necho 'no header';" );
        $zip->close();

        $uploader = new BPIBulkUploader();
        $headers = $uploader->extractPluginHeaders( $zip_path );

        $this->assertSame( '', $headers['plugin_name'] );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — checkPathTraversal (line 351, 358)
    // ------------------------------------------------------------------

    public function test_check_path_traversal_returns_false_for_invalid_zip(): void {
        $bad_file = $this->tempDir . '/bad-traversal.zip';
        file_put_contents( $bad_file, 'not a zip' );

        $uploader = new BPIBulkUploader();
        $result = $uploader->checkPathTraversal( $bad_file );

        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — getPluginSlug returns empty for invalid zip
    // ------------------------------------------------------------------

    public function test_get_plugin_slug_returns_empty_for_invalid_zip(): void {
        $bad_file = $this->tempDir . '/bad-slug.zip';
        file_put_contents( $bad_file, 'not a zip' );

        $uploader = new BPIBulkUploader();
        $slug = $uploader->getPluginSlug( $bad_file );

        $this->assertSame( '', $slug );
    }

    // ------------------------------------------------------------------
    // BPIBulkUploader — handleUpload file size limit (line 254 area)
    // ------------------------------------------------------------------

    public function test_handle_upload_rejects_oversized_file(): void {
        global $bpi_test_json_responses, $bpi_test_options;
        $bpi_test_options['bpi_max_file_size'] = 1; // 1 MB limit

        $zip_path = $this->createValidPluginZip( 'size-test' );

        $_POST['_wpnonce'] = 'valid';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'size-test.zip',
            'size'     => 2 * 1024 * 1024, // 2 MB (exceeds 1 MB limit)
            'error'    => UPLOAD_ERR_OK,
        );

        $uploader = new BPIBulkUploader();
        $uploader->handleUpload();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertStringContainsString( 'exceeds', $bpi_test_json_responses[0]['data']['message'] );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createValidPluginZip( string $slug, string $version = '1.0.0' ): string {
        $zip_path = $this->tempDir . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            $slug . '/' . $slug . '.php',
            "<?php\n/**\n * Plugin Name: " . ucfirst( $slug ) . "\n * Version: {$version}\n * Author: Test\n * Description: Test plugin\n */"
        );
        $zip->close();
        return $zip_path;
    }

    private function createPluginZipWithChangelog( string $slug, string $version ): string {
        $zip_path = $this->tempDir . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            $slug . '/' . $slug . '.php',
            "<?php\n/**\n * Plugin Name: " . ucfirst( $slug ) . "\n * Version: {$version}\n * Author: Test\n * Description: Test plugin\n */"
        );
        $zip->addFromString(
            $slug . '/readme.txt',
            "=== " . ucfirst( $slug ) . " ===\n"
            . "Tested up to: 6.7\n"
            . "Stable tag: {$version}\n\n"
            . "== Changelog ==\n\n"
            . "= {$version} =\n"
            . "* Added new feature\n"
            . "* Fixed bug\n\n"
            . "= 1.5.0 =\n"
            . "* Intermediate release\n\n"
            . "= 1.0.0 =\n"
            . "* Initial release\n"
        );
        $zip->close();
        return $zip_path;
    }

    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $path );
    }
}
