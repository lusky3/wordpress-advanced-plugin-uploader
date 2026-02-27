<?php
/**
 * Property test for preview action labeling.
 *
 * Feature: bulk-plugin-installer, Property 4: Preview action labeling
 *
 * **Validates: Requirements 3.1, 3.2, 3.3**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIAdminPage;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 4: Preview action labeling.
 *
 * For any queued plugin, the Preview_Screen should label it as "Update"
 * if and only if its slug matches an installed plugin; otherwise it
 * should be labeled "New Install". When labeled as "Update", both the
 * installed version and the new version should be displayed.
 *
 * **Validates: Requirements 3.1, 3.2, 3.3**
 */
class PreviewActionLabelingTest extends TestCase {

    use TestTrait;

    private const PREVIEW_SUCCESS_MSG = 'Preview should succeed.';

    private BPIAdminPage $adminPage;

    protected function setUp(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        global $bpi_test_transients, $bpi_test_installed_plugins, $bpi_test_current_user_id;

        $bpi_test_nonce_valid       = true;
        $bpi_test_user_can          = true;
        $bpi_test_json_responses    = array();
        $bpi_test_transients        = array();
        $bpi_test_installed_plugins = array();
        $bpi_test_current_user_id   = 1;

        $_POST = array( '_wpnonce' => 'nonce_bpi_preview' );

        $this->adminPage = new BPIAdminPage();
    }

    protected function tearDown(): void {
        $_POST = array();
    }

    /**
     * Build a queue item array for a plugin.
     *
     * @param string $slug    Plugin slug.
     * @param string $version Plugin version.
     * @param string $name    Plugin name.
     * @return array Queue item.
     */
    private function makeQueueItem( string $slug, string $version, string $name ): array {
        return array(
            'slug'               => $slug,
            'file_path'          => '/tmp/' . $slug . '.zip',
            'file_name'          => $slug . '.zip',
            'file_size'          => 1000,
            'plugin_name'        => $name,
            'plugin_version'     => $version,
            'plugin_author'      => 'Test Author',
            'plugin_description' => 'Description for ' . $name,
            'requires_php'       => '',
            'requires_wp'        => '',
        );
    }

    /**
     * Generate a semantic version string from three integers.
     *
     * @param int $major Major version.
     * @param int $minor Minor version.
     * @param int $patch Patch version.
     * @return string Version string.
     */
    private function makeVersion( int $major, int $minor, int $patch ): string {
        return $major . '.' . $minor . '.' . $patch;
    }

    /**
     * Invoke handlePreview() and return the response.
     *
     * @return array The captured JSON response.
     */
    private function callPreview(): array {
        global $bpi_test_json_responses;
        $bpi_test_json_responses = array();
        $this->adminPage->handlePreview();
        return end( $bpi_test_json_responses );
    }

    /**
     * Property 4a: Plugins not matching any installed plugin are labeled "New Install".
     *
     * Generate random queued plugins with no matching installed plugins and
     * verify each is labeled "New Install" with a null installed_version.
     *
     * **Validates: Requirements 3.1, 3.3**
     */
    public function testNewInstallLabelWhenNoMatchingInstalledPlugin(): void {
        $this
            ->forAll(
                Generator\choose( 1, 8 ), // number of plugins
                Generator\choose( 0, 999 ) // seed for slug uniqueness
            )
            ->withMaxSize( 50 )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_transients, $bpi_test_installed_plugins;

                $bpi_test_installed_plugins = array();
                $queue = array();

                for ( $i = 0; $i < $count; $i++ ) {
                    $slug    = 'new-plugin-' . $seed . '-' . $i;
                    $version = $this->makeVersion( 1, $i, 0 );
                    $queue[] = $this->makeQueueItem( $slug, $version, 'New Plugin ' . $i );
                }

                $bpi_test_transients['bpi_queue_1'] = array(
                    'value'      => $queue,
                    'expiration' => 3600,
                );

                $response = $this->callPreview();

                $this->assertTrue( $response['success'], self::PREVIEW_SUCCESS_MSG );
                $plugins = $response['data']['plugins'];
                $this->assertCount( $count, $plugins );

                foreach ( $plugins as $idx => $plugin ) {
                    $this->assertSame(
                        'install',
                        $plugin['action'],
                        "Plugin at index {$idx} should have action 'install'."
                    );
                    $this->assertSame(
                        'New Install',
                        $plugin['action_label'],
                        "Plugin at index {$idx} should be labeled 'New Install'."
                    );
                    $this->assertNull(
                        $plugin['installed_version'],
                        "Plugin at index {$idx} should have null installed_version."
                    );
                }
            } );
    }

    /**
     * Property 4b: Plugins matching an installed plugin are labeled "Update"
     * with both installed and new versions displayed.
     *
     * Generate queued plugins whose slugs match installed plugins and verify
     * each is labeled "Update" with both versions present.
     *
     * **Validates: Requirements 3.1, 3.2**
     */
    public function testUpdateLabelWhenSlugMatchesInstalledPlugin(): void {
        $this
            ->forAll(
                Generator\choose( 1, 8 ),  // number of plugins
                Generator\choose( 1, 9 ),   // installed major version
                Generator\choose( 0, 20 ),  // installed minor version
                Generator\choose( 0, 20 )   // installed patch version
            )
            ->withMaxSize( 50 )
            ->then( function ( int $count, int $instMajor, int $instMinor, int $instPatch ): void {
                global $bpi_test_transients, $bpi_test_installed_plugins;

                $bpi_test_installed_plugins = array();
                $queue = array();

                $installedVersion = $this->makeVersion( $instMajor, $instMinor, $instPatch );
                $newVersion       = $this->makeVersion( $instMajor + 1, 0, 0 );

                for ( $i = 0; $i < $count; $i++ ) {
                    $slug = 'update-plugin-' . $i;

                    $bpi_test_installed_plugins[ $slug . '/' . $slug . '.php' ] = array(
                        'Name'    => 'Update Plugin ' . $i,
                        'Version' => $installedVersion,
                    );

                    $queue[] = $this->makeQueueItem( $slug, $newVersion, 'Update Plugin ' . $i );
                }

                $bpi_test_transients['bpi_queue_1'] = array(
                    'value'      => $queue,
                    'expiration' => 3600,
                );

                $response = $this->callPreview();

                $this->assertTrue( $response['success'], self::PREVIEW_SUCCESS_MSG );
                $plugins = $response['data']['plugins'];
                $this->assertCount( $count, $plugins );

                foreach ( $plugins as $idx => $plugin ) {
                    $this->assertSame(
                        'update',
                        $plugin['action'],
                        "Plugin at index {$idx} should have action 'update'."
                    );
                    $this->assertSame(
                        'Update',
                        $plugin['action_label'],
                        "Plugin at index {$idx} should be labeled 'Update'."
                    );
                    $this->assertSame(
                        $installedVersion,
                        $plugin['installed_version'],
                        "Plugin at index {$idx} must display the installed version."
                    );
                    $this->assertSame(
                        $newVersion,
                        $plugin['plugin_version'],
                        "Plugin at index {$idx} must display the new version."
                    );
                }
            } );
    }

    /**
     * Property 4c: Mixed queue with both new installs and updates.
     *
     * Generate a queue where each plugin randomly either matches an installed
     * plugin or not. Verify the labeling is correct for every plugin.
     *
     * **Validates: Requirements 3.1, 3.2, 3.3**
     */
    public function testMixedQueueLabelsCorrectly(): void {
        $this
            ->forAll(
                Generator\choose( 2, 10 ),   // queue size
                Generator\choose( 0, 32767 ) // seed for random install/new decision
            )
            ->withMaxSize( 50 )
            ->then( function ( int $queueSize, int $seed ): void {
                global $bpi_test_transients, $bpi_test_installed_plugins;

                $bpi_test_installed_plugins = array();
                $queue          = array();
                $expectedLabels = array();
                $rng            = $seed;

                for ( $i = 0; $i < $queueSize; $i++ ) {
                    $slug       = 'mixed-plugin-' . $seed . '-' . $i;
                    $newVersion = $this->makeVersion( 2, $i, 0 );

                    // Deterministic pseudo-random: decide if this plugin is installed.
                    $rng         = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $isInstalled = ( $rng % 2 === 0 );

                    if ( $isInstalled ) {
                        $installedVersion = $this->makeVersion( 1, $i, 0 );
                        $bpi_test_installed_plugins[ $slug . '/' . $slug . '.php' ] = array(
                            'Name'    => 'Mixed Plugin ' . $i,
                            'Version' => $installedVersion,
                        );
                        $expectedLabels[] = array(
                            'action'            => 'update',
                            'action_label'      => 'Update',
                            'installed_version' => $installedVersion,
                            'new_version'       => $newVersion,
                        );
                    } else {
                        $expectedLabels[] = array(
                            'action'            => 'install',
                            'action_label'      => 'New Install',
                            'installed_version' => null,
                            'new_version'       => $newVersion,
                        );
                    }

                    $queue[] = $this->makeQueueItem( $slug, $newVersion, 'Mixed Plugin ' . $i );
                }

                $bpi_test_transients['bpi_queue_1'] = array(
                    'value'      => $queue,
                    'expiration' => 3600,
                );

                $response = $this->callPreview();

                $this->assertTrue( $response['success'], self::PREVIEW_SUCCESS_MSG );
                $plugins = $response['data']['plugins'];
                $this->assertCount( $queueSize, $plugins );

                foreach ( $plugins as $idx => $plugin ) {
                    $expected = $expectedLabels[ $idx ];

                    $this->assertSame(
                        $expected['action'],
                        $plugin['action'],
                        "Plugin at index {$idx} action mismatch."
                    );
                    $this->assertSame(
                        $expected['action_label'],
                        $plugin['action_label'],
                        "Plugin at index {$idx} action_label mismatch."
                    );
                    $this->assertSame(
                        $expected['installed_version'],
                        $plugin['installed_version'],
                        "Plugin at index {$idx} installed_version mismatch."
                    );
                    $this->assertSame(
                        $expected['new_version'],
                        $plugin['plugin_version'],
                        "Plugin at index {$idx} new version mismatch."
                    );
                }
            } );
    }
}
