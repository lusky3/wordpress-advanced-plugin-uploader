<?php
/**
 * Additional unit tests for BPICLIInterface to cover profile loading and registerCommands.
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
 * Tests for CLI interface profile loading and command registration.
 */
class CLIProfileCoverageTest extends TestCase {

    private BPICLIInterface $cli;

    protected function setUp(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code, $bpi_test_cli_commands,
               $bpi_test_options, $bpi_test_installed_plugins, $wpdb, $bpi_test_settings_errors;

        $bpi_test_cli_log       = array();
        $bpi_test_cli_halt_code = null;
        $bpi_test_cli_commands  = array();
        $bpi_test_options       = array( 'bpi_auto_activate' => false, 'bpi_auto_rollback' => true );
        $bpi_test_installed_plugins = array();
        $bpi_test_settings_errors   = array();
        $wpdb->reset_bpi_log();

        $processor = new CLICoverageTestableProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        );

        $this->cli = new BPICLIInterface(
            new BPIBulkUploader(),
            new BPIQueueManager(),
            new BPICompatibilityChecker(),
            $processor,
            new BPIProfileManager()
        );
    }

    public function test_register_commands_adds_cli_command(): void {
        global $bpi_test_cli_commands;

        $this->cli->registerCommands();

        $this->assertNotEmpty( $bpi_test_cli_commands );
        $names = array_column( $bpi_test_cli_commands, 'name' );
        $this->assertContains( 'bulk-plugin install', $names );
    }

    public function test_install_with_no_args_and_no_profile_errors(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code;

        $this->cli->install( array(), array() );

        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'error', $types );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }

    public function test_install_with_nonexistent_profile_errors(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code;

        $this->cli->install( array(), array( 'profile' => 'nonexistent-profile' ) );

        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'error', $types );
        $this->assertSame( 2, $bpi_test_cli_halt_code );
    }

    public function test_install_with_profile_loads_plugins(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code, $bpi_test_options;

        // Create a profile with plugins.
        $profile_manager = new BPIProfileManager();
        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'test-cli-profile';
        $_POST['plugins']  = array(
            array(
                'slug' => 'prof-plugin',
                'name' => 'Prof Plugin',
                'version' => '1.0.0',
            ),
        );

        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $profile_manager->handleAjaxSaveProfile();

        // Now install using the profile.
        $bpi_test_cli_log       = array();
        $bpi_test_cli_halt_code = null;

        $this->cli->install( array(), array( 'profile' => 'test-cli-profile', 'yes' => true ) );

        $messages = array_column( $bpi_test_cli_log, 'message' );
        $combined = implode( ' ', $messages );
        $this->assertStringContainsString( 'Summary', $combined );

        unset( $_POST['_wpnonce'], $_POST['name'], $_POST['plugins'] );
    }

    public function test_install_with_empty_profile_errors(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code;

        // Create an empty profile.
        $profile_manager = new BPIProfileManager();
        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'empty-profile';
        $_POST['plugins']  = array();

        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $profile_manager->handleAjaxSaveProfile();

        $bpi_test_cli_log       = array();
        $bpi_test_cli_halt_code = null;

        $this->cli->install( array(), array( 'profile' => 'empty-profile' ) );

        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'error', $types );
        $this->assertSame( 2, $bpi_test_cli_halt_code );

        unset( $_POST['_wpnonce'], $_POST['name'], $_POST['plugins'] );
    }

    public function test_install_with_nonexistent_file_warns(): void {
        global $bpi_test_cli_log, $bpi_test_cli_halt_code;

        $this->cli->install( array( '/nonexistent/path/plugin.zip' ), array( 'yes' => true ) );

        $types = array_column( $bpi_test_cli_log, 'type' );
        $this->assertContains( 'warning', $types );
    }
}
