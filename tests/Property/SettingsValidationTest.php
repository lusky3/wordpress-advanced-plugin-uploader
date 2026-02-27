<?php
/**
 * Property test for settings validation.
 *
 * Feature: bulk-plugin-installer, Property 11: Settings validation
 *
 * Generates random settings values (valid and invalid: negative max plugins,
 * non-numeric file size, out-of-range retention, invalid emails) and verifies
 * valid values are saved and retrievable, invalid values are rejected with
 * descriptive errors, and previous valid settings are preserved.
 *
 * **Validates: Requirements 6.6, 6.7**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPISettingsManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 11: Settings validation.
 *
 * For any settings input, valid values should be saved successfully and
 * retrievable with the same values, while invalid values should be rejected
 * with a descriptive error message and the previous valid settings should
 * be preserved.
 */
class SettingsValidationTest extends TestCase {

    use TestTrait;

    private BPISettingsManager $settingsManager;

    protected function setUp(): void {
        $this->resetGlobalState();
        $this->settingsManager = new BPISettingsManager();
    }

    /**
     * Reset all global test state before each iteration.
     */
    private function resetGlobalState(): void {
        global $bpi_test_options, $bpi_test_settings_errors,
            $bpi_test_hooks, $bpi_test_registered_settings,
            $bpi_test_settings_sections, $bpi_test_settings_fields,
            $bpi_test_options_pages;

        $bpi_test_options             = array();
        $bpi_test_settings_errors     = array();
        $bpi_test_hooks               = array();
        $bpi_test_registered_settings = array();
        $bpi_test_settings_sections   = array();
        $bpi_test_settings_fields     = array();
        $bpi_test_options_pages       = array();
    }

    /**
     * Test: Valid settings within range are saved and retrievable.
     *
     * Generate random valid values for all settings fields and verify they
     * are accepted without errors and persisted correctly.
     */
    public function test_valid_settings_are_saved_and_retrievable(): void {
        $this
            ->forAll(
                Generator\choose( 1, 100 ),       // bpi_max_plugins
                Generator\choose( 0, 9999 ),       // bpi_max_file_size
                Generator\choose( 1, 720 ),        // bpi_rollback_retention
                Generator\bool(),                   // bpi_auto_activate
                Generator\bool(),                   // bpi_auto_rollback
                Generator\bool()                    // bpi_email_notifications
            )
            ->withMaxSize( 100 )
            ->__invoke( function (
                int $max_plugins,
                int $max_file_size,
                int $retention,
                bool $auto_activate,
                bool $auto_rollback,
                bool $email_notifications
            ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                $bpi_test_options         = array();
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => (string) $max_plugins,
                    'bpi_max_file_size'      => (string) $max_file_size,
                    'bpi_rollback_retention' => (string) $retention,
                    'bpi_email_recipients'   => '',
                );

                if ( $auto_activate ) {
                    $input['bpi_auto_activate'] = '1';
                }
                if ( $auto_rollback ) {
                    $input['bpi_auto_rollback'] = '1';
                }
                if ( $email_notifications ) {
                    $input['bpi_email_notifications'] = '1';
                }

                $result = $this->settingsManager->sanitizeSettings( $input );

                // No errors should be generated for valid input.
                $this->assertEmpty(
                    $bpi_test_settings_errors,
                    'Valid settings should not produce errors. Got: ' . json_encode( $bpi_test_settings_errors )
                );

                // Values should match what was submitted.
                $this->assertSame( $max_plugins, $result['bpi_max_plugins'] );
                $this->assertSame( $max_file_size, $result['bpi_max_file_size'] );
                $this->assertSame( $retention, $result['bpi_rollback_retention'] );
                $this->assertSame( $auto_activate, $result['bpi_auto_activate'] );
                $this->assertSame( $auto_rollback, $result['bpi_auto_rollback'] );
                $this->assertSame( $email_notifications, $result['bpi_email_notifications'] );

                // Values should be persisted in options.
                $this->assertSame( $max_plugins, $bpi_test_options['bpi_max_plugins'] );
                $this->assertSame( $max_file_size, $bpi_test_options['bpi_max_file_size'] );
                $this->assertSame( $retention, $bpi_test_options['bpi_rollback_retention'] );
            } );
    }

    /**
     * Test: Invalid max_plugins (negative, zero, >100, non-numeric) are rejected.
     *
     * Generate out-of-range max_plugins values and verify they produce an error
     * with the correct error code.
     */
    public function test_invalid_max_plugins_are_rejected(): void {
        $this
            ->forAll(
                Generator\oneOf(
                    Generator\choose( -1000, 0 ),    // Zero and negative
                    Generator\choose( 101, 10000 )   // Above maximum
                )
            )
            ->withMaxSize( 100 )
            ->__invoke( function ( int $invalid_max_plugins ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                $bpi_test_options         = array();
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => (string) $invalid_max_plugins,
                    'bpi_max_file_size'      => '10',
                    'bpi_rollback_retention' => '24',
                    'bpi_email_recipients'   => '',
                );

                $this->settingsManager->sanitizeSettings( $input );

                // Should produce a settings error.
                $error_codes = array_column( $bpi_test_settings_errors, 'code' );
                $this->assertContains(
                    'bpi_invalid_max_plugins',
                    $error_codes,
                    "max_plugins={$invalid_max_plugins} should be rejected."
                );

                // Error message should be descriptive.
                $error_messages = array_column( $bpi_test_settings_errors, 'message' );
                $has_descriptive = false;
                foreach ( $error_messages as $msg ) {
                    if ( stripos( $msg, 'maximum plugins' ) !== false || stripos( $msg, 'positive integer' ) !== false ) {
                        $has_descriptive = true;
                        break;
                    }
                }
                $this->assertTrue( $has_descriptive, 'Error message should be descriptive.' );
            } );
    }

    /**
     * Test: Invalid file size (negative, non-numeric) are rejected.
     *
     * Generate invalid file size values and verify they produce an error.
     */
    public function test_invalid_file_size_rejected(): void {
        $this
            ->forAll(
                Generator\oneOf(
                    Generator\choose( -10000, -1 ),                          // Negative numbers
                    Generator\map(
                        function ( string $s ): string {
                            // Ensure non-numeric by prefixing with a letter.
                            return 'x' . $s;
                        },
                        Generator\names()
                    )
                )
            )
            ->withMaxSize( 100 )
            ->__invoke( function ( $invalid_file_size ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                $bpi_test_options         = array();
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => '20',
                    'bpi_max_file_size'      => (string) $invalid_file_size,
                    'bpi_rollback_retention' => '24',
                    'bpi_email_recipients'   => '',
                );

                $this->settingsManager->sanitizeSettings( $input );

                $error_codes = array_column( $bpi_test_settings_errors, 'code' );
                $this->assertContains(
                    'bpi_invalid_max_file_size',
                    $error_codes,
                    "file_size='{$invalid_file_size}' should be rejected."
                );
            } );
    }

    /**
     * Test: Invalid retention (zero, negative, >720) are rejected.
     *
     * Generate out-of-range retention values and verify they produce an error.
     */
    public function test_invalid_retention_rejected(): void {
        $this
            ->forAll(
                Generator\oneOf(
                    Generator\choose( -1000, 0 ),    // Zero and negative
                    Generator\choose( 721, 10000 )   // Above maximum
                )
            )
            ->withMaxSize( 100 )
            ->__invoke( function ( int $invalid_retention ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                $bpi_test_options         = array();
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => '20',
                    'bpi_max_file_size'      => '10',
                    'bpi_rollback_retention' => (string) $invalid_retention,
                    'bpi_email_recipients'   => '',
                );

                $this->settingsManager->sanitizeSettings( $input );

                $error_codes = array_column( $bpi_test_settings_errors, 'code' );
                $this->assertContains(
                    'bpi_invalid_rollback_retention',
                    $error_codes,
                    "retention={$invalid_retention} should be rejected."
                );
            } );
    }

    /**
     * Test: Invalid email addresses are rejected.
     *
     * Generate strings that are not valid email addresses and verify they
     * produce an error when submitted as email recipients.
     */
    public function test_invalid_emails_rejected(): void {
        $this
            ->forAll(
                Generator\elements(
                    'not-an-email',
                    'missing@',
                    '@nodomain',
                    'spaces in@email.com',
                    'double@@at.com',
                    'no-tld@example',
                    '<script>@evil.com',
                    'plain text',
                    'user@.com',
                    '.user@domain.com'
                )
            )
            ->withMaxSize( 100 )
            ->__invoke( function ( string $invalid_email ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                $bpi_test_options         = array();
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => '20',
                    'bpi_max_file_size'      => '10',
                    'bpi_rollback_retention' => '24',
                    'bpi_email_recipients'   => $invalid_email,
                );

                $this->settingsManager->sanitizeSettings( $input );

                $error_codes = array_column( $bpi_test_settings_errors, 'code' );
                $this->assertContains(
                    'bpi_invalid_email_recipients',
                    $error_codes,
                    "email='{$invalid_email}' should be rejected."
                );
            } );
    }

    /**
     * Test: Previous valid settings are preserved when invalid values are submitted.
     *
     * Set valid settings first, then submit invalid values and verify the
     * previous valid settings are preserved in the sanitized output.
     */
    public function test_previous_valid_settings_preserved_on_invalid_input(): void {
        $this
            ->forAll(
                Generator\choose( 1, 100 ),       // Previous valid max_plugins
                Generator\choose( 1, 720 ),        // Previous valid retention
                Generator\choose( -1000, 0 ),      // Invalid new max_plugins
                Generator\choose( 721, 10000 )     // Invalid new retention
            )
            ->withMaxSize( 100 )
            ->__invoke( function (
                int $prev_max_plugins,
                int $prev_retention,
                int $invalid_max_plugins,
                int $invalid_retention
            ): void {
                global $bpi_test_options, $bpi_test_settings_errors;

                // Set previous valid values.
                $bpi_test_options = array(
                    'bpi_max_plugins'       => $prev_max_plugins,
                    'bpi_rollback_retention' => $prev_retention,
                    'bpi_email_recipients'   => 'valid@example.com',
                );
                $bpi_test_settings_errors = array();

                $input = array(
                    'bpi_max_plugins'        => (string) $invalid_max_plugins,
                    'bpi_max_file_size'      => '10',
                    'bpi_rollback_retention' => (string) $invalid_retention,
                    'bpi_email_recipients'   => 'bad-email',
                );

                $result = $this->settingsManager->sanitizeSettings( $input );

                // Previous valid values should be preserved.
                $this->assertSame(
                    $prev_max_plugins,
                    $result['bpi_max_plugins'],
                    'Previous valid max_plugins should be preserved.'
                );
                $this->assertSame(
                    $prev_retention,
                    $result['bpi_rollback_retention'],
                    'Previous valid retention should be preserved.'
                );
                $this->assertSame(
                    'valid@example.com',
                    $result['bpi_email_recipients'],
                    'Previous valid email should be preserved.'
                );

                // Errors should have been generated.
                $error_codes = array_column( $bpi_test_settings_errors, 'code' );
                $this->assertContains( 'bpi_invalid_max_plugins', $error_codes );
                $this->assertContains( 'bpi_invalid_rollback_retention', $error_codes );
                $this->assertContains( 'bpi_invalid_email_recipients', $error_codes );
            } );
    }
}
