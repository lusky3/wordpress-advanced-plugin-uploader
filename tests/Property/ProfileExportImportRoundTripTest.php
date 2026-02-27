<?php
/**
 * Property test for profile export/import round trip.
 *
 * Feature: bulk-plugin-installer, Property 20: Profile export/import round trip
 *
 * **Validates: Requirements 14.4, 14.5**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIProfileManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class ProfileExportImportRoundTripTest extends TestCase {

    use TestTrait;

    private BPIProfileManager $manager;

    protected function setUp(): void {
        global $bpi_test_options;
        $bpi_test_options = array();

        $this->manager = new BPIProfileManager();
    }

    /**
     * Generate a safe profile name (alphanumeric + spaces, no HTML tags).
     * sanitize_text_field strips tags and trims, so we generate names that survive that.
     *
     * @return \Eris\Generator
     */
    private function profileNameGenerator() {
        return Generator\map(
            function ( array $parts ) {
                $words = array(
                    'Standard', 'Dev', 'Production', 'Starter', 'Agency',
                    'Client', 'Blog', 'Shop', 'Portfolio', 'Enterprise',
                    'Basic', 'Advanced', 'Custom', 'Default', 'Minimal',
                    'Full', 'Test', 'Staging', 'Live', 'Backup',
                );
                $count = max( 1, $parts[0] % 4 + 1 );
                $selected = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $selected[] = $words[ abs( $parts[1] + $i ) % count( $words ) ];
                }
                return implode( ' ', $selected );
            },
            Generator\tuple(
                Generator\choose( 0, 100 ),
                Generator\choose( 0, 100 )
            )
        );
    }

    /**
     * Generate a random plugin list (0-10 plugins).
     *
     * @return \Eris\Generator
     */
    private function pluginListGenerator() {
        $slugPool = array(
            'wordfence', 'wp-super-cache', 'akismet', 'jetpack',
            'yoast-seo', 'contact-form-7', 'woocommerce', 'elementor',
            'classic-editor', 'updraftplus', 'redirection', 'wp-mail-smtp',
            'all-in-one-seo', 'sucuri-scanner', 'litespeed-cache',
        );
        $namePool = array(
            'Wordfence Security', 'WP Super Cache', 'Akismet', 'Jetpack',
            'Yoast SEO', 'Contact Form 7', 'WooCommerce', 'Elementor',
            'Classic Editor', 'UpdraftPlus', 'Redirection', 'WP Mail SMTP',
            'All in One SEO', 'Sucuri Scanner', 'LiteSpeed Cache',
        );

        return Generator\map(
            function ( array $params ) use ( $slugPool, $namePool ) {
                $count   = $params[0];
                $seed    = $params[1];
                $plugins = array();
                $used    = array();

                for ( $i = 0; $i < $count; $i++ ) {
                    $idx = abs( $seed + $i ) % count( $slugPool );
                    if ( isset( $used[ $idx ] ) ) {
                        continue;
                    }
                    $used[ $idx ] = true;

                    $major = abs( $seed + $i ) % 10;
                    $minor = abs( $seed + $i * 3 ) % 20;
                    $patch = abs( $seed + $i * 7 ) % 15;

                    $plugins[] = array(
                        'slug'    => $slugPool[ $idx ],
                        'name'    => $namePool[ $idx ],
                        'version' => "$major.$minor.$patch",
                    );
                }

                return $plugins;
            },
            Generator\tuple(
                Generator\choose( 0, 10 ),
                Generator\choose( 0, 9999 )
            )
        );
    }

    /**
     * Property 20: Profile export/import round trip.
     *
     * For any saved profile, exporting as JSON and importing that JSON
     * produces a profile with the same name, plugin list, and version information,
     * but a different ID.
     *
     * **Validates: Requirements 14.4, 14.5**
     */
    public function test_export_import_round_trip_preserves_data(): void {
        $this
            ->forAll(
                $this->profileNameGenerator(),
                $this->pluginListGenerator()
            )
            ->then( function ( string $name, array $plugins ): void {
                global $bpi_test_options;
                $bpi_test_options = array();

                // 1. Save the original profile.
                $originalId = $this->manager->saveProfile( $name, $plugins );
                $this->assertGreaterThan( 0, $originalId, 'saveProfile must return a positive ID.' );

                // 2. Export as JSON.
                $json = $this->manager->exportProfile( $originalId );
                $this->assertNotEmpty( $json, 'exportProfile must return non-empty JSON.' );

                // Verify it's valid JSON.
                $decoded = json_decode( $json, true );
                $this->assertNotNull( $decoded, 'Exported JSON must be valid.' );

                // 3. Import the JSON.
                $importedId = $this->manager->importProfile( $json );
                $this->assertIsInt( $importedId, 'importProfile must return an integer ID.' );
                $this->assertGreaterThan( 0, $importedId, 'Imported profile must have a positive ID.' );

                // 4. Verify imported profile has a different ID.
                $this->assertNotEquals(
                    $originalId,
                    $importedId,
                    'Imported profile must have a different ID than the original.'
                );

                // 5. Retrieve both profiles.
                $original = $this->manager->getProfile( $originalId );
                $imported = $this->manager->getProfile( $importedId );

                $this->assertNotNull( $original, 'Original profile must still exist.' );
                $this->assertNotNull( $imported, 'Imported profile must be retrievable.' );

                // 6. Verify name is preserved.
                // importProfile runs sanitize_text_field on the name,
                // so we compare against the sanitized version.
                $expectedName = trim( strip_tags( $name ) );
                $this->assertSame(
                    $expectedName,
                    $imported['name'],
                    'Imported profile name must match the original (after sanitization).'
                );

                // 7. Verify plugin list is preserved.
                $this->assertCount(
                    count( $plugins ),
                    $imported['plugins'],
                    'Imported profile must have the same number of plugins.'
                );

                foreach ( $plugins as $i => $expectedPlugin ) {
                    $importedPlugin = $imported['plugins'][ $i ];
                    $this->assertSame(
                        $expectedPlugin['slug'],
                        $importedPlugin['slug'],
                        "Plugin $i slug must match."
                    );
                    $this->assertSame(
                        $expectedPlugin['name'],
                        $importedPlugin['name'],
                        "Plugin $i name must match."
                    );
                    $this->assertSame(
                        $expectedPlugin['version'],
                        $importedPlugin['version'],
                        "Plugin $i version must match."
                    );
                }
            } );
    }
}
