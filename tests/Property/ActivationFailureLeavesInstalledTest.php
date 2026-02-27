<?php
/**
 * Property test for activation failure leaves plugin installed.
 *
 * Feature: bulk-plugin-installer, Property 16: Activation failure leaves plugin installed
 *
 * **Validates: Requirements 10.4**
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
 * Testable subclass where installation always succeeds but activation
 * can be configured to fail (return WP_Error) for specified plugins.
 */
class ActivationFailureTestableProcessor extends BPIPluginProcessor {

    /**
     * Set of plugin_file values for which wpActivatePlugin returns WP_Error.
     *
     * @var array<string, true>
     */
    public array $failingActivations = array();

    /**
     * List of plugin_file values that had wpActivatePlugin() called.
     *
     * @var array<string>
     */
    public array $activationCalls = array();

    /** @inheritDoc — always succeeds. */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        return true;
    }

    /** @inheritDoc — temp dir. */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . '/bpi_pbt_actfail_' . $slug;
    }

    /** @inheritDoc — always inactive. */
    protected function isPluginActive( string $plugin_file ): bool {
        return false;
    }

    /** @inheritDoc — returns WP_Error for configured plugins, null otherwise. */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        $this->activationCalls[] = $plugin_file;

        if ( isset( $this->failingActivations[ $plugin_file ] ) ) {
            return new \WP_Error(
                'activation_failed',
                'Plugin activation failed for ' . $plugin_file
            );
        }

        return null;
    }
}

/**
 * Property 16: Activation failure leaves plugin installed.
 *
 * For any plugin where activation fails after successful installation,
 * the plugin should remain installed (status = 'success') but in a
 * deactivated state (activated = false), and a warning message about
 * activation failure should be present.
 *
 * **Validates: Requirements 10.4**
 */
class ActivationFailureLeavesInstalledTest extends TestCase {

    use TestTrait;

    private const ACTIVATION_FAILURE_MSG = 'could not be activated';

    private ActivationFailureTestableProcessor $processor;

    protected function setUp(): void {
        global $bpi_test_options, $wpdb, $bpi_test_settings_errors;

        $bpi_test_options         = array();
        $bpi_test_settings_errors = array();
        $wpdb->reset_bpi_log();

        // Enable auto-activate so activation is attempted.
        $bpi_test_options['bpi_auto_activate'] = true;
        $bpi_test_options['bpi_auto_rollback'] = false;

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        $this->processor = new ActivationFailureTestableProcessor( $rollback, $logger, $settings );
    }

    /**
     * Build a plugin data array requesting activation.
     *
     * @param int       $index  Unique plugin index.
     * @param bool|null $toggle Per-plugin activate toggle (null uses global).
     * @return array Plugin data.
     */
    private function makePlugin( int $index, ?bool $toggle = null ): array {
        $slug = 'actfail-plugin-' . $index;
        $data = array(
            'slug'              => $slug,
            'action'            => 'install',
            'plugin_name'       => 'ActFail Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '1.0.0',
            'installed_version' => '',
        );

        if ( null !== $toggle ) {
            $data['activate'] = $toggle;
        }

        return $data;
    }

    /**
     * Property 16a: Single plugin activation failure leaves it installed.
     *
     * For any plugin where activation is requested and fails, verify:
     * 1. status is 'success' (plugin IS installed)
     * 2. activated is false
     * 3. messages contain a warning about activation failure
     * 4. wpActivatePlugin was called
     *
     * **Validates: Requirements 10.4**
     */
    public function testActivationFailureLeavesPluginInstalled(): void {
        $this
            ->forAll(
                Generator\choose( 0, 999 ) // plugin index
            )
            ->then( function ( int $index ): void {
                $this->processor->activationCalls    = array();
                $this->processor->failingActivations = array();

                $plugin     = $this->makePlugin( $index );
                $pluginFile = $plugin['plugin_file'];

                // Configure activation to fail for this plugin.
                $this->processor->failingActivations[ $pluginFile ] = true;

                $result = $this->processor->processPlugin( $plugin );

                // 1. Plugin is installed (status = success).
                $this->assertSame(
                    'success',
                    $result['status'],
                    'Plugin should remain installed (status=success) even when activation fails.'
                );

                // 2. Plugin is NOT activated.
                $this->assertFalse(
                    $result['activated'],
                    'Plugin should be deactivated when activation fails.'
                );

                // 3. Messages contain activation failure warning.
                $this->assertTrue(
                    $this->hasActivationFailureWarning( $result['messages'] ),
                    'Messages should contain a warning about activation failure. Got: '
                    . implode( ' | ', $result['messages'] )
                );

                // 4. wpActivatePlugin was called.
                $this->assertContains(
                    $pluginFile,
                    $this->processor->activationCalls,
                    'wpActivatePlugin should have been called for the plugin.'
                );
            } );
    }

    /**
     * Check if messages contain the activation failure warning.
     */
    private function hasActivationFailureWarning( array $messages ): bool {
        foreach ( $messages as $msg ) {
            if ( stripos( $msg, self::ACTIVATION_FAILURE_MSG ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assert a plugin result matches expected activation failure behavior.
     */
    private function assertActivationFailureResult( array $result, string $slug ): void {
        $this->assertFalse(
            $result['activated'],
            sprintf( 'Plugin "%s" should be deactivated after activation failure.', $slug )
        );
        $this->assertTrue(
            $this->hasActivationFailureWarning( $result['messages'] ),
            sprintf(
                'Plugin "%s" should have activation failure warning. Got: %s',
                $slug,
                implode( ' | ', $result['messages'] )
            )
        );
    }

    /**
     * Property 16b: Batch with mixed activation outcomes.
     *
     * Generate random batches where activation is requested for all plugins
     * but some fail. Verify the property holds for every plugin where
     * activation failed.
     *
     * **Validates: Requirements 10.4**
     */
    public function testBatchWithMixedActivationOutcomes(): void {
        $this
            ->forAll(
                Generator\choose( 2, 8 ),                  // batch size
                Generator\choose( 1, 100 )                 // seed for failure selection
            )
            ->then( function ( int $batchSize, int $seed ): void {
                global $bpi_test_options;

                $bpi_test_options['bpi_auto_activate'] = true;
                $this->processor->activationCalls      = array();
                $this->processor->failingActivations   = array();

                $plugins       = array();
                $failingFiles  = array();

                for ( $i = 0; $i < $batchSize; $i++ ) {
                    $plugin  = $this->makePlugin( $seed * 1000 + $i );
                    $plugins[] = $plugin;

                    if ( $i % 2 === 1 ) {
                        $this->processor->failingActivations[ $plugin['plugin_file'] ] = true;
                        $failingFiles[] = $plugin['plugin_file'];
                    }
                }

                foreach ( $plugins as $plugin ) {
                    $result     = $this->processor->processPlugin( $plugin );
                    $pluginFile = $plugin['plugin_file'];
                    $shouldFail = in_array( $pluginFile, $failingFiles, true );

                    $this->assertSame(
                        'success',
                        $result['status'],
                        sprintf( 'Plugin "%s" should be installed regardless of activation outcome.', $plugin['slug'] )
                    );

                    if ( $shouldFail ) {
                        $this->assertActivationFailureResult( $result, $plugin['slug'] );
                    } else {
                        $this->assertTrue(
                            $result['activated'],
                            sprintf( 'Plugin "%s" should be activated when activation succeeds.', $plugin['slug'] )
                        );
                    }

                    $this->assertContains(
                        $pluginFile,
                        $this->processor->activationCalls,
                        sprintf( 'wpActivatePlugin should have been called for "%s".', $plugin['slug'] )
                    );
                }
            } );
    }

    /**
     * Property 16c: Per-plugin toggle true with activation failure.
     *
     * When per-plugin toggle explicitly requests activation but activation
     * fails, the plugin should still be installed but deactivated.
     *
     * **Validates: Requirements 10.4**
     */
    public function testPerPluginToggleTrueWithActivationFailure(): void {
        $this
            ->forAll(
                Generator\elements( true, false ), // global auto-activate
                Generator\choose( 0, 999 )         // plugin index
            )
            ->then( function ( bool $globalActivate, int $index ): void {
                global $bpi_test_options;

                $bpi_test_options['bpi_auto_activate'] = $globalActivate;
                $this->processor->activationCalls      = array();
                $this->processor->failingActivations   = array();

                // Per-plugin toggle = true (always request activation).
                $plugin     = $this->makePlugin( $index, true );
                $pluginFile = $plugin['plugin_file'];

                $this->processor->failingActivations[ $pluginFile ] = true;

                $result = $this->processor->processPlugin( $plugin );

                // Installed successfully.
                $this->assertSame( 'success', $result['status'] );

                // Not activated.
                $this->assertFalse(
                    $result['activated'],
                    'Plugin should be deactivated when activation fails, even with per-plugin toggle=true.'
                );

                // Warning present.
                $this->assertTrue(
                    $this->hasActivationFailureWarning( $result['messages'] ),
                    'Activation failure warning should be present.'
                );

                // Activation was attempted.
                $this->assertContains(
                    $pluginFile,
                    $this->processor->activationCalls,
                    'wpActivatePlugin should have been called.'
                );
            } );
    }
}
