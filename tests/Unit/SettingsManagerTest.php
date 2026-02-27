<?php
/**
 * Unit tests for the BPISettingsManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Settings Manager: registration, sanitization, defaults, and rendering.
 */
class SettingsManagerTest extends TestCase {

    /**
     * The Settings Manager instance under test.
     *
     * @var BPISettingsManager
     */
    private BPISettingsManager $settingsManager;

    /**
     * Reset global state and create a fresh Settings Manager before each test.
     */
    protected function setUp(): void {
        global $bpi_test_hooks, $bpi_test_options, $bpi_test_registered_settings,
            $bpi_test_settings_sections, $bpi_test_settings_fields,
            $bpi_test_options_pages, $bpi_test_settings_errors;

        $bpi_test_hooks               = array();
        $bpi_test_options             = array();
        $bpi_test_registered_settings = array();
        $bpi_test_settings_sections   = array();
        $bpi_test_settings_fields     = array();
        $bpi_test_options_pages       = array();
        $bpi_test_settings_errors     = array();

        $this->settingsManager = new BPISettingsManager();
    }

    // ---------------------------------------------------------------
    // Settings Registration Tests
    // ---------------------------------------------------------------

    /**
     * Test that registerSettings() registers the option group.
     */
    public function test_register_settings_registers_option_group(): void {
        global $bpi_test_registered_settings;

        $this->settingsManager->registerSettings();

        $this->assertArrayHasKey( 'bpi_settings', $bpi_test_registered_settings );
        $this->assertSame( 'bpi_settings_group', $bpi_test_registered_settings['bpi_settings']['group'] );
    }

    /**
     * Test that registerSettings() registers the general section.
     */
    public function test_register_settings_registers_section(): void {
        global $bpi_test_settings_sections;

        $this->settingsManager->registerSettings();

        $this->assertArrayHasKey( 'bpi_general_section', $bpi_test_settings_sections );
        $this->assertSame( 'bpi-settings', $bpi_test_settings_sections['bpi_general_section']['page'] );
    }

    /**
     * Test that registerSettings() registers all seven settings fields.
     */
    public function test_register_settings_registers_all_fields(): void {
        global $bpi_test_settings_fields;

        $this->settingsManager->registerSettings();

        $expected_fields = array(
            'bpi_auto_activate',
            'bpi_max_plugins',
            'bpi_auto_rollback',
            'bpi_max_file_size',
            'bpi_rollback_retention',
            'bpi_email_notifications',
            'bpi_email_recipients',
        );

        foreach ( $expected_fields as $field_id ) {
            $this->assertArrayHasKey( $field_id, $bpi_test_settings_fields, "Field '{$field_id}' should be registered." );
            $this->assertSame( 'bpi-settings', $bpi_test_settings_fields[ $field_id ]['page'] );
            $this->assertSame( 'bpi_general_section', $bpi_test_settings_fields[ $field_id ]['section'] );
        }
    }

    /**
     * Test that the sanitize callback is set on the registered setting.
     */
    public function test_register_settings_sets_sanitize_callback(): void {
        global $bpi_test_registered_settings;

        $this->settingsManager->registerSettings();

        $args = $bpi_test_registered_settings['bpi_settings']['args'];
        $this->assertArrayHasKey( 'sanitize_callback', $args );
        $this->assertIsCallable( $args['sanitize_callback'] );
    }

    // ---------------------------------------------------------------
    // Menu Page Tests
    // ---------------------------------------------------------------

    /**
     * Test that addMenuPage() registers the options page.
     */
    public function test_add_menu_page_registers_options_page(): void {
        global $bpi_test_options_pages;

        $this->settingsManager->addMenuPage();

        $this->assertArrayHasKey( 'bpi-settings', $bpi_test_options_pages );
        $page = $bpi_test_options_pages['bpi-settings'];
        $this->assertSame( 'Bulk Plugin Installer', $page['page_title'] );
        $this->assertSame( 'Bulk Plugin Installer', $page['menu_title'] );
        $this->assertSame( 'install_plugins', $page['capability'] );
    }

    // ---------------------------------------------------------------
    // getOption() Tests
    // ---------------------------------------------------------------

    /**
     * Test that getOption() returns default values when no option is set.
     */
    public function test_get_option_returns_defaults(): void {
        $defaults = $this->settingsManager->getDefaults();

        foreach ( $defaults as $key => $expected ) {
            $this->assertSame( $expected, $this->settingsManager->getOption( $key ), "Default for '{$key}' should match." );
        }
    }

    /**
     * Test that getOption() returns stored value when option exists.
     */
    public function test_get_option_returns_stored_value(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_max_plugins'] = 50;

        $this->assertSame( 50, $this->settingsManager->getOption( 'bpi_max_plugins' ) );
    }

    /**
     * Test that getOption() returns null for unknown keys.
     */
    public function test_get_option_returns_null_for_unknown_key(): void {
        $this->assertNull( $this->settingsManager->getOption( 'bpi_nonexistent_key' ) );
    }

    // ---------------------------------------------------------------
    // sanitizeSettings() Tests — Valid Input
    // ---------------------------------------------------------------

    /**
     * Test that sanitizeSettings() accepts valid input and persists values.
     */
    public function test_sanitize_settings_accepts_valid_input(): void {
        global $bpi_test_options, $bpi_test_settings_errors;

        $input = array(
            'bpi_auto_activate'       => '1',
            'bpi_max_plugins'         => '30',
            'bpi_auto_rollback'       => '1',
            'bpi_max_file_size'       => '64',
            'bpi_rollback_retention'  => '48',
            'bpi_email_notifications' => '1',
            'bpi_email_recipients'    => 'admin@example.com, dev@example.com',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertEmpty( $bpi_test_settings_errors, 'No errors should be generated for valid input.' );
        $this->assertTrue( $result['bpi_auto_activate'] );
        $this->assertSame( 30, $result['bpi_max_plugins'] );
        $this->assertTrue( $result['bpi_auto_rollback'] );
        $this->assertSame( 64, $result['bpi_max_file_size'] );
        $this->assertSame( 48, $result['bpi_rollback_retention'] );
        $this->assertTrue( $result['bpi_email_notifications'] );
        $this->assertSame( 'admin@example.com, dev@example.com', $result['bpi_email_recipients'] );

        // Verify values were persisted.
        $this->assertSame( 30, $bpi_test_options['bpi_max_plugins'] );
        $this->assertSame( 48, $bpi_test_options['bpi_rollback_retention'] );
    }

    /**
     * Test that unchecked checkboxes result in false.
     */
    public function test_sanitize_settings_unchecked_checkboxes_are_false(): void {
        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertFalse( $result['bpi_auto_activate'] );
        $this->assertFalse( $result['bpi_auto_rollback'] );
        $this->assertFalse( $result['bpi_email_notifications'] );
    }

    /**
     * Test that empty email recipients results in empty string.
     */
    public function test_sanitize_settings_empty_email_recipients(): void {
        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertSame( '', $result['bpi_email_recipients'] );
    }

    // ---------------------------------------------------------------
    // sanitizeSettings() Tests — Invalid Input
    // ---------------------------------------------------------------

    /**
     * Test that max_plugins below 1 is rejected.
     */
    public function test_sanitize_rejects_max_plugins_below_minimum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '0',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertNotEmpty( $bpi_test_settings_errors );
        $this->assertSame( 'bpi_invalid_max_plugins', $bpi_test_settings_errors[0]['code'] );
        // Should preserve default (20).
        $this->assertSame( 20, $result['bpi_max_plugins'] );
    }

    /**
     * Test that max_plugins above 100 is rejected.
     */
    public function test_sanitize_rejects_max_plugins_above_maximum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '101',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertNotEmpty( $bpi_test_settings_errors );
        $this->assertSame( 'bpi_invalid_max_plugins', $bpi_test_settings_errors[0]['code'] );
        $this->assertSame( 20, $result['bpi_max_plugins'] );
    }

    /**
     * Test that negative max_plugins is rejected.
     */
    public function test_sanitize_rejects_negative_max_plugins(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '-5',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertNotEmpty( $bpi_test_settings_errors );
        $this->assertSame( 'bpi_invalid_max_plugins', $bpi_test_settings_errors[0]['code'] );
    }

    /**
     * Test that non-numeric file size is rejected.
     */
    public function test_sanitize_rejects_non_numeric_file_size(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => 'abc',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertNotEmpty( $bpi_test_settings_errors );
        $error_codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_max_file_size', $error_codes );
    }

    /**
     * Test that negative file size is rejected.
     */
    public function test_sanitize_rejects_negative_file_size(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '-10',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $error_codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_max_file_size', $error_codes );
    }

    /**
     * Test that rollback retention below 1 is rejected.
     */
    public function test_sanitize_rejects_retention_below_minimum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '0',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $error_codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_rollback_retention', $error_codes );
        $this->assertSame( 24, $result['bpi_rollback_retention'] );
    }

    /**
     * Test that rollback retention above 720 is rejected.
     */
    public function test_sanitize_rejects_retention_above_maximum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '721',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $error_codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_rollback_retention', $error_codes );
        $this->assertSame( 24, $result['bpi_rollback_retention'] );
    }

    /**
     * Test that invalid email addresses are rejected.
     */
    public function test_sanitize_rejects_invalid_email_recipients(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => 'valid@example.com, not-an-email, also@bad',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $error_codes = array_column( $bpi_test_settings_errors, 'code' );
        $this->assertContains( 'bpi_invalid_email_recipients', $error_codes );
    }

    /**
     * Test that invalid values preserve previous valid settings.
     */
    public function test_sanitize_preserves_previous_valid_settings_on_error(): void {
        global $bpi_test_options;

        // Set existing valid values.
        $bpi_test_options['bpi_max_plugins']       = 50;
        $bpi_test_options['bpi_rollback_retention'] = 48;
        $bpi_test_options['bpi_email_recipients']   = 'existing@example.com';

        $input = array(
            'bpi_max_plugins'        => '0',       // Invalid.
            'bpi_max_file_size'      => '10',
            'bpi_rollback_retention' => '999',     // Invalid.
            'bpi_email_recipients'   => 'bad-email', // Invalid.
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $this->assertSame( 50, $result['bpi_max_plugins'] );
        $this->assertSame( 48, $result['bpi_rollback_retention'] );
        $this->assertSame( 'existing@example.com', $result['bpi_email_recipients'] );
    }

    // ---------------------------------------------------------------
    // sanitizeSettings() Tests — Boundary Values
    // ---------------------------------------------------------------

    /**
     * Test that max_plugins at boundary value 1 is accepted.
     */
    public function test_sanitize_accepts_max_plugins_at_minimum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '1',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $max_plugin_errors = array_filter( $bpi_test_settings_errors, fn( $e ) => $e['code'] === 'bpi_invalid_max_plugins' );
        $this->assertEmpty( $max_plugin_errors );
        $this->assertSame( 1, $result['bpi_max_plugins'] );
    }

    /**
     * Test that max_plugins at boundary value 100 is accepted.
     */
    public function test_sanitize_accepts_max_plugins_at_maximum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '100',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $max_plugin_errors = array_filter( $bpi_test_settings_errors, fn( $e ) => $e['code'] === 'bpi_invalid_max_plugins' );
        $this->assertEmpty( $max_plugin_errors );
        $this->assertSame( 100, $result['bpi_max_plugins'] );
    }

    /**
     * Test that rollback retention at boundary value 1 is accepted.
     */
    public function test_sanitize_accepts_retention_at_minimum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '1',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $retention_errors = array_filter( $bpi_test_settings_errors, fn( $e ) => $e['code'] === 'bpi_invalid_rollback_retention' );
        $this->assertEmpty( $retention_errors );
        $this->assertSame( 1, $result['bpi_rollback_retention'] );
    }

    /**
     * Test that rollback retention at boundary value 720 is accepted.
     */
    public function test_sanitize_accepts_retention_at_maximum(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '720',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $retention_errors = array_filter( $bpi_test_settings_errors, fn( $e ) => $e['code'] === 'bpi_invalid_rollback_retention' );
        $this->assertEmpty( $retention_errors );
        $this->assertSame( 720, $result['bpi_rollback_retention'] );
    }

    /**
     * Test that file size 0 (server default) is accepted.
     */
    public function test_sanitize_accepts_zero_file_size(): void {
        global $bpi_test_settings_errors;

        $input = array(
            'bpi_max_plugins'        => '20',
            'bpi_max_file_size'      => '0',
            'bpi_rollback_retention' => '24',
            'bpi_email_recipients'   => '',
        );

        $result = $this->settingsManager->sanitizeSettings( $input );

        $file_size_errors = array_filter( $bpi_test_settings_errors, fn( $e ) => $e['code'] === 'bpi_invalid_max_file_size' );
        $this->assertEmpty( $file_size_errors );
        $this->assertSame( 0, $result['bpi_max_file_size'] );
    }

    // ---------------------------------------------------------------
    // Rendering Tests
    // ---------------------------------------------------------------

    /**
     * Test that renderSettingsPage() outputs the page wrapper and form.
     */
    public function test_render_settings_page_outputs_form(): void {
        ob_start();
        $this->settingsManager->renderSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Bulk Plugin Installer Settings', $output );
        $this->assertStringContainsString( 'options.php', $output );
        $this->assertStringContainsString( 'Activity Log', $output );
    }

    /**
     * Test that renderSettingsPage() shows "No log entries" when log is empty.
     */
    public function test_render_settings_page_shows_empty_log_message(): void {
        global $wpdb;
        $wpdb->reset_bpi_log();

        ob_start();
        $this->settingsManager->renderSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'No log entries found.', $output );
    }

    /**
     * Test that renderSettingsPage() displays log entries when they exist.
     */
    public function test_render_settings_page_displays_log_entries(): void {
        $logManager = new \BPILogManager();
        $logManager->log( 'install', array(
            'plugin_slug' => 'test-plugin',
            'plugin_name' => 'Test Plugin',
            'status'      => 'success',
            'message'     => 'Installed successfully.',
        ) );

        ob_start();
        $this->settingsManager->renderSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Test Plugin', $output );
        $this->assertStringContainsString( 'success', $output );
        $this->assertStringContainsString( 'Clear Log', $output );
    }

    /**
     * Test that renderSettingsPage() shows dry run badge for dry run entries.
     */
    public function test_render_settings_page_shows_dry_run_badge(): void {
        $logManager = new \BPILogManager();
        $logManager->log( 'dry_run', array(
            'plugin_slug' => 'test-plugin',
            'plugin_name' => 'Test Plugin',
            'status'      => 'success',
            'is_dry_run'  => true,
        ) );

        ob_start();
        $this->settingsManager->renderSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Dry Run', $output );
    }

    /**
     * Test that renderCheckboxField() outputs a checkbox input.
     */
    public function test_render_checkbox_field_outputs_checkbox(): void {
        ob_start();
        $this->settingsManager->renderCheckboxField( array(
            'key'         => 'bpi_auto_activate',
            'description' => 'Auto-activate plugins.',
        ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'type="checkbox"', $output );
        $this->assertStringContainsString( 'bpi_settings[bpi_auto_activate]', $output );
        $this->assertStringContainsString( 'Auto-activate plugins.', $output );
    }

    /**
     * Test that renderNumberField() outputs a number input.
     */
    public function test_render_number_field_outputs_number_input(): void {
        ob_start();
        $this->settingsManager->renderNumberField( array(
            'key'         => 'bpi_max_plugins',
            'description' => 'Max plugins per upload.',
            'min'         => 1,
            'max'         => 100,
        ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'type="number"', $output );
        $this->assertStringContainsString( 'bpi_settings[bpi_max_plugins]', $output );
        $this->assertStringContainsString( 'min="1"', $output );
        $this->assertStringContainsString( 'max="100"', $output );
    }

    /**
     * Test that renderTextField() outputs a text input with placeholder.
     */
    public function test_render_text_field_outputs_text_input(): void {
        ob_start();
        $this->settingsManager->renderTextField( array(
            'key'         => 'bpi_email_recipients',
            'description' => 'Additional email recipients.',
            'placeholder' => 'admin@example.com',
        ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'type="text"', $output );
        $this->assertStringContainsString( 'bpi_settings[bpi_email_recipients]', $output );
        $this->assertStringContainsString( 'admin@example.com', $output );
    }

    // ---------------------------------------------------------------
    // Accessor Tests
    // ---------------------------------------------------------------

    /**
     * Test that getPageSlug() returns the correct slug.
     */
    public function test_get_page_slug_returns_correct_value(): void {
        $this->assertSame( 'bpi-settings', $this->settingsManager->getPageSlug() );
    }

    /**
     * Test that getOptionGroup() returns the correct group.
     */
    public function test_get_option_group_returns_correct_value(): void {
        $this->assertSame( 'bpi_settings_group', $this->settingsManager->getOptionGroup() );
    }

    /**
     * Test that getDefaults() returns all expected keys.
     */
    public function test_get_defaults_returns_all_keys(): void {
        $defaults = $this->settingsManager->getDefaults();

        $expected_keys = array(
            'bpi_auto_activate',
            'bpi_max_plugins',
            'bpi_auto_rollback',
            'bpi_max_file_size',
            'bpi_rollback_retention',
            'bpi_email_notifications',
            'bpi_email_recipients',
        );

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $defaults, "Default for '{$key}' should exist." );
        }
    }
}
