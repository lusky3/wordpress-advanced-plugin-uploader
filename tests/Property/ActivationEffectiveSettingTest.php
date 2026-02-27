<?php
/**
 * Property test for activation respects effective setting.
 *
 * Feature: bulk-plugin-installer, Property 15: Activation respects effective setting
 *
 * **Validates: Requirements 10.1, 10.2, 10.3, 10.5**
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
 * Testable subclass that allows configuring per-plugin active state
 * and tracking activation calls.
 */
class ActivationEffectiveTestableProcessor extends BPIPluginProcessor {

    public const TEMP_DIR_PREFIX = '/bpi_pbt_activation_';

    /**
     * Map of plugin_file => bool indicating whether the plugin is already active.
     *
     * @var array<string, bool>
     */
    public array $activePlugins = array();

    /**
     * List of plugin_file values that had wpActivatePlugin() called.
     *
     * @var array<string>
     */
    public array $activationCalls = array();

    /** @inheritDoc */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        return true;
    }

    /** @inheritDoc */
    protected function getPluginDir( string $slug ): string {
        return sys_get_temp_dir() . self::TEMP_DIR_PREFIX . $slug;
    }

    /** @inheritDoc */
    protected function isPluginActive( string $plugin_file ): bool {
        return $this->activePlugins[ $plugin_file ] ?? false;
    }

    /** @inheritDoc */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        $this->activationCalls[] = $plugin_file;
        return null; // success
    }
}


/**
 * Property 15: Activation respects effective setting.
 *
 * For any newly installed plugin, its post-install activation state should match
 * the effective activation setting: the per-plugin toggle overrides the global
 * auto-activate setting. Updates to already-active plugins should skip the
 * activation step entirely.
 *
 * **Validates: Requirements 10.1, 10.2, 10.3, 10.5**
 */
class ActivationEffectiveSettingTest extends TestCase {

    use TestTrait;

    private ActivationEffectiveTestableProcessor $processor;

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

        $this->processor = new ActivationEffectiveTestableProcessor( $rollback, $logger, $settings );
    }

    /**
     * Build a plugin data array.
     *
     * @param int         $index   Plugin index for unique slug.
     * @param string      $action  'install' or 'update'.
     * @param bool|null   $toggle  Per-plugin activate toggle (null = not set).
     * @return array Plugin data.
     */
    private function makePlugin( int $index, string $action = 'install', ?bool $toggle = null ): array {
        $slug = 'act-plugin-' . $index;
        $data = array(
            'slug'              => $slug,
            'action'            => $action,
            'plugin_name'       => 'Activation Plugin ' . $index,
            'file_path'         => '/tmp/' . $slug . '.zip',
            'plugin_file'       => $slug . '/' . $slug . '.php',
            'plugin_version'    => '2.0.0',
            'installed_version' => 'update' === $action ? '1.0.0' : '',
        );

        if ( null !== $toggle ) {
            $data['activate'] = $toggle;
        }

        return $data;
    }

    /**
     * Property 15a: Per-plugin toggle overrides global setting.
     *
     * When a per-plugin activate toggle is set, it takes precedence over
     * the global bpi_auto_activate setting regardless of the global value.
     *
     * **Validates: Requirements 10.1, 10.2, 10.3**
     */
    public function testPerPluginToggleOverridesGlobal(): void {
        $this
            ->forAll(
                Generator\elements( true, false ),  // global auto-activate
                Generator\elements( true, false ),  // per-plugin toggle
                Generator\choose( 0, 99 )           // plugin index
            )
            ->then( function ( bool $globalActivate, bool $perPluginToggle, int $index ): void {
                global $bpi_test_options;

                // Reset state.
                $bpi_test_options['bpi_auto_activate'] = $globalActivate;
                $this->processor->activePlugins       = array();
                $this->processor->activationCalls     = array();

                $plugin = $this->makePlugin( $index, 'install', $perPluginToggle );
                $result = $this->processor->processPlugin( $plugin );

                $this->assertSame( 'success', $result['status'] );

                // The effective setting is the per-plugin toggle, not global.
                $this->assertSame(
                    $perPluginToggle,
                    $result['activated'],
                    sprintf(
                        'Per-plugin toggle=%s should override global=%s. activated=%s',
                        $perPluginToggle ? 'true' : 'false',
                        $globalActivate ? 'true' : 'false',
                        $result['activated'] ? 'true' : 'false'
                    )
                );

                // Verify activatePlugin was called only when toggle is true.
                $pluginFile    = $plugin['plugin_file'];
                $wasActivated  = in_array( $pluginFile, $this->processor->activationCalls, true );
                $this->assertSame(
                    $perPluginToggle,
                    $wasActivated,
                    'activatePlugin call should match per-plugin toggle.'
                );
            } );
    }

    /**
     * Property 15b: Global setting applies when per-plugin toggle is not set.
     *
     * When no per-plugin activate toggle is provided, the global
     * bpi_auto_activate setting determines activation.
     *
     * **Validates: Requirements 10.1, 10.2**
     */
    public function testGlobalSettingAppliesWhenToggleNotSet(): void {
        $this
            ->forAll(
                Generator\elements( true, false ), // global auto-activate
                Generator\choose( 0, 99 )          // plugin index
            )
            ->then( function ( bool $globalActivate, int $index ): void {
                global $bpi_test_options;

                $bpi_test_options['bpi_auto_activate'] = $globalActivate;
                $this->processor->activePlugins       = array();
                $this->processor->activationCalls     = array();

                // No per-plugin toggle (null => key not set in data).
                $plugin = $this->makePlugin( $index, 'install', null );
                $result = $this->processor->processPlugin( $plugin );

                $this->assertSame( 'success', $result['status'] );

                $this->assertSame(
                    $globalActivate,
                    $result['activated'],
                    sprintf(
                        'Without per-plugin toggle, global=%s should determine activation. activated=%s',
                        $globalActivate ? 'true' : 'false',
                        $result['activated'] ? 'true' : 'false'
                    )
                );

                $pluginFile   = $plugin['plugin_file'];
                $wasActivated = in_array( $pluginFile, $this->processor->activationCalls, true );
                $this->assertSame(
                    $globalActivate,
                    $wasActivated,
                    'activatePlugin call should match global setting when toggle not set.'
                );
            } );
    }

    /**
     * Ensure the temp plugin directory exists so backup creation succeeds for updates.
     *
     * @param string $slug Plugin slug.
     */
    private function ensurePluginDir( string $slug ): void {
        $dir = sys_get_temp_dir() . ActivationEffectiveTestableProcessor::TEMP_DIR_PREFIX . $slug;
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
    }

    /**
     * Clean up a temp plugin directory.
     *
     * @param string $slug Plugin slug.
     */
    private function cleanupPluginDir( string $slug ): void {
        $dir = sys_get_temp_dir() . ActivationEffectiveTestableProcessor::TEMP_DIR_PREFIX . $slug;
        if ( is_dir( $dir ) ) {
            @rmdir( $dir );
        }
    }

    /**
     * Property 15c: Updates to already-active plugins skip activation.
     *
     * When a plugin is being updated and is already active, the activation
     * step is skipped entirely (wpActivatePlugin is never called),
     * regardless of global or per-plugin settings.
     *
     * **Validates: Requirements 10.5**
     */
    public function testUpdatesToActivePluginsSkipActivation(): void {
        $this
            ->forAll(
                Generator\elements( true, false ),              // global auto-activate
                Generator\elements( true, false, null ),        // per-plugin toggle (or not set)
                Generator\choose( 0, 99 )                       // plugin index
            )
            ->then( function ( bool $globalActivate, ?bool $perPluginToggle, int $index ): void {
                global $bpi_test_options;

                $bpi_test_options['bpi_auto_activate'] = $globalActivate;
                $this->processor->activationCalls     = array();

                $plugin     = $this->makePlugin( $index, 'update', $perPluginToggle );
                $pluginFile = $plugin['plugin_file'];

                // Mark this plugin as already active.
                $this->processor->activePlugins = array( $pluginFile => true );

                // Ensure the plugin directory exists so backup succeeds.
                $this->ensurePluginDir( $plugin['slug'] );

                $result = $this->processor->processPlugin( $plugin );

                // Clean up.
                $this->cleanupPluginDir( $plugin['slug'] );

                $this->assertSame( 'success', $result['status'] );

                // activated should be true (plugin stays active).
                $this->assertTrue(
                    $result['activated'],
                    'Updates to already-active plugins should report activated=true.'
                );

                // But wpActivatePlugin should NOT have been called.
                $this->assertNotContains(
                    $pluginFile,
                    $this->processor->activationCalls,
                    'wpActivatePlugin must not be called for updates to already-active plugins.'
                );
            } );
    }

    /**
     * Assert that an update to an already-active plugin skips activation.
     */
    private function assertUpdateToActiveSkipsActivation( array $result, string $pluginFile ): void {
        $this->assertTrue(
            $result['activated'],
            'Update to active plugin should report activated=true.'
        );
        $this->assertNotContains(
            $pluginFile,
            $this->processor->activationCalls,
            'wpActivatePlugin must not be called for update to active plugin.'
        );
    }

    /**
     * Assert that the effective activation setting matches the result.
     */
    private function assertEffectiveSettingMatches(
        array $result,
        string $pluginFile,
        bool $globalActivate,
        ?bool $perPluginToggle,
        string $action,
        bool $alreadyActive
    ): void {
        $effectiveSetting = ( null !== $perPluginToggle )
            ? $perPluginToggle
            : $globalActivate;

        $toggleLabel = null === $perPluginToggle ? 'null' : var_export( $perPluginToggle, true );

        $this->assertSame(
            $effectiveSetting,
            $result['activated'],
            sprintf(
                'Effective setting mismatch: global=%s, toggle=%s, action=%s, active=%s → expected activated=%s, got=%s',
                $globalActivate ? 'true' : 'false',
                $toggleLabel,
                $action,
                $alreadyActive ? 'true' : 'false',
                $effectiveSetting ? 'true' : 'false',
                $result['activated'] ? 'true' : 'false'
            )
        );

        $wasActivated = in_array( $pluginFile, $this->processor->activationCalls, true );
        $this->assertSame(
            $effectiveSetting,
            $wasActivated,
            'wpActivatePlugin call should match effective setting.'
        );
    }

    /**
     * Property 15d: Combined — effective setting across all combinations.
     *
     * Generate plugins with various combinations of global auto-activate,
     * per-plugin toggle, action type, and active state. Verify the result's
     * 'activated' field matches the effective setting in every case.
     *
     * **Validates: Requirements 10.1, 10.2, 10.3, 10.5**
     */
    public function testEffectiveSettingAcrossAllCombinations(): void {
        $this
            ->forAll(
                Generator\elements( true, false ),         // global auto-activate
                Generator\elements( true, false, null ),   // per-plugin toggle
                Generator\elements( 'install', 'update' ), // action
                Generator\elements( true, false ),         // already active (relevant for updates)
                Generator\choose( 0, 99 )                  // plugin index
            )
            ->then( function (
                bool $globalActivate,
                ?bool $perPluginToggle,
                string $action,
                bool $alreadyActive,
                int $index
            ): void {
                global $bpi_test_options;

                $bpi_test_options['bpi_auto_activate'] = $globalActivate;
                $this->processor->activationCalls     = array();

                $plugin     = $this->makePlugin( $index, $action, $perPluginToggle );
                $pluginFile = $plugin['plugin_file'];

                // Set active state (only meaningful for updates).
                $this->processor->activePlugins = array();
                if ( $alreadyActive ) {
                    $this->processor->activePlugins[ $pluginFile ] = true;
                }

                // Ensure plugin directory exists for updates so backup succeeds.
                if ( 'update' === $action ) {
                    $this->ensurePluginDir( $plugin['slug'] );
                }

                $result = $this->processor->processPlugin( $plugin );

                // Clean up.
                if ( 'update' === $action ) {
                    $this->cleanupPluginDir( $plugin['slug'] );
                }

                $this->assertSame( 'success', $result['status'] );

                $isUpdateToActive = ( 'update' === $action && $alreadyActive );

                if ( $isUpdateToActive ) {
                    $this->assertUpdateToActiveSkipsActivation( $result, $pluginFile );
                } else {
                    $this->assertEffectiveSettingMatches(
                        $result, $pluginFile, $globalActivate, $perPluginToggle, $action, $alreadyActive
                    );
                }
            } );
    }
}
