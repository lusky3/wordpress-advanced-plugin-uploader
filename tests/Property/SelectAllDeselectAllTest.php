<?php
/**
 * Property test for select all / deselect all toggle.
 *
 * Feature: bulk-plugin-installer, Property 6: Select all / deselect all toggle
 *
 * **Validates: Requirements 3.8**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIAdminPage;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 6: Select all / deselect all toggle.
 *
 * For any set of queued plugins in the Preview_Screen, clicking "Select All"
 * should check every plugin entry, and clicking "Deselect All" should uncheck
 * every plugin entry.
 *
 * Since Select All / Deselect All is client-side JavaScript behavior, this
 * property test verifies the server-side preview data structure supports it:
 * 1. All plugins in the preview response have a `checked` boolean field
 * 2. The `checked` field defaults correctly (true for compatible, false for incompatible)
 * 3. The preview returns exactly N items for N queued plugins (none filtered out)
 *
 * **Validates: Requirements 3.8**
 */
class SelectAllDeselectAllTest extends TestCase {

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
     * Build a queue item for a compatible plugin.
     *
     * @param string $slug    Plugin slug.
     * @param string $version Plugin version.
     * @return array Queue item.
     */
    private function makeCompatibleItem( string $slug, string $version ): array {
        return array(
            'slug'               => $slug,
            'file_path'          => '/tmp/' . $slug . '.zip',
            'file_name'          => $slug . '.zip',
            'file_size'          => 1000,
            'plugin_name'        => 'Plugin ' . $slug,
            'plugin_version'     => $version,
            'plugin_author'      => 'Test Author',
            'plugin_description' => 'Description for ' . $slug,
            'requires_php'       => '',
            'requires_wp'        => '',
        );
    }

    /**
     * Build a queue item for an incompatible plugin (requires future PHP).
     *
     * @param string $slug    Plugin slug.
     * @param string $version Plugin version.
     * @return array Queue item.
     */
    private function makeIncompatibleItem( string $slug, string $version ): array {
        return array(
            'slug'               => $slug,
            'file_path'          => '/tmp/' . $slug . '.zip',
            'file_name'          => $slug . '.zip',
            'file_size'          => 1000,
            'plugin_name'        => 'Plugin ' . $slug,
            'plugin_version'     => $version,
            'plugin_author'      => 'Test Author',
            'plugin_description' => 'Description for ' . $slug,
            'requires_php'       => '99.0.0',
            'requires_wp'        => '',
        );
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
     * Property 6a: Every plugin in the preview has a boolean `checked` field.
     *
     * For any random queue of N plugins, the preview response must contain
     * exactly N items, each with an `array_key_exists('checked')` boolean.
     * This ensures Select All / Deselect All can toggle every entry.
     *
     * **Validates: Requirements 3.8**
     */
    public function testAllPreviewItemsHaveCheckedField(): void {
        $this
            ->forAll(
                Generator\choose( 1, 15 ),
                Generator\choose( 0, 9999 )
            )
            ->withMaxSize( 50 )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_transients;

                $queue = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $slug    = 'check-plugin-' . $seed . '-' . $i;
                    $version = '1.' . $i . '.0';
                    $queue[] = $this->makeCompatibleItem( $slug, $version );
                }

                $bpi_test_transients['bpi_queue_1'] = array(
                    'value'      => $queue,
                    'expiration' => 3600,
                );

                $response = $this->callPreview();

                $this->assertTrue( $response['success'], self::PREVIEW_SUCCESS_MSG );
                $plugins = $response['data']['plugins'];

                // Exactly N items returned — none filtered out.
                $this->assertCount(
                    $count,
                    $plugins,
                    "Preview must return exactly {$count} items for {$count} queued plugins."
                );

                // Every item has a boolean `checked` field.
                foreach ( $plugins as $idx => $plugin ) {
                    $this->assertArrayHasKey(
                        'checked',
                        $plugin,
                        "Plugin at index {$idx} must have a 'checked' field."
                    );
                    $this->assertIsBool(
                        $plugin['checked'],
                        "Plugin at index {$idx} 'checked' field must be boolean."
                    );
                }
            } );
    }

    /**
     * Property 6b: Compatible plugins default to checked=true,
     * incompatible plugins default to checked=false.
     *
     * Generate a mixed queue where each plugin is randomly compatible or
     * incompatible. Verify the `checked` default matches compatibility.
     *
     * **Validates: Requirements 3.8**
     */
    public function testCheckedDefaultMatchesCompatibility(): void {
        $this
            ->forAll(
                Generator\choose( 2, 12 ),
                Generator\choose( 0, 32767 )
            )
            ->withMaxSize( 50 )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_transients;

                $queue       = array();
                $expectation = array();
                $rng         = $seed;

                for ( $i = 0; $i < $count; $i++ ) {
                    $slug    = 'compat-plugin-' . $seed . '-' . $i;
                    $version = '1.' . $i . '.0';

                    $rng          = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $isCompatible = ( $rng % 2 === 0 );

                    if ( $isCompatible ) {
                        $queue[] = $this->makeCompatibleItem( $slug, $version );
                    } else {
                        $queue[] = $this->makeIncompatibleItem( $slug, $version );
                    }
                    $expectation[] = $isCompatible;
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
                    $expectedChecked = $expectation[ $idx ];
                    $this->assertSame(
                        $expectedChecked,
                        $plugin['checked'],
                        sprintf(
                            "Plugin at index %d (%s) should have checked=%s.",
                            $idx,
                            $expectedChecked ? 'compatible' : 'incompatible',
                            $expectedChecked ? 'true' : 'false'
                        )
                    );
                }
            } );
    }

    /**
     * Property 6c: Preview preserves all queued plugins regardless of count.
     *
     * For any queue size N (1..20), the preview returns exactly N items
     * with matching slugs, ensuring Select All can reach every plugin.
     *
     * **Validates: Requirements 3.8**
     */
    public function testPreviewContainsAllQueuedPlugins(): void {
        $this
            ->forAll(
                Generator\choose( 1, 20 ),
                Generator\choose( 0, 9999 )
            )
            ->withMaxSize( 50 )
            ->then( function ( int $count, int $seed ): void {
                global $bpi_test_transients;

                $queue         = array();
                $expectedSlugs = array();

                for ( $i = 0; $i < $count; $i++ ) {
                    $slug            = 'all-plugin-' . $seed . '-' . $i;
                    $version         = '2.' . $i . '.0';
                    $queue[]         = $this->makeCompatibleItem( $slug, $version );
                    $expectedSlugs[] = $slug;
                }

                $bpi_test_transients['bpi_queue_1'] = array(
                    'value'      => $queue,
                    'expiration' => 3600,
                );

                $response = $this->callPreview();

                $this->assertTrue( $response['success'], self::PREVIEW_SUCCESS_MSG );
                $plugins = $response['data']['plugins'];

                $this->assertCount( $count, $plugins );

                $returnedSlugs = array_map(
                    function ( $p ) {
                        return $p['slug'];
                    },
                    $plugins
                );

                $this->assertSame(
                    $expectedSlugs,
                    $returnedSlugs,
                    'Preview must return all queued plugin slugs in order.'
                );
            } );
    }
}
