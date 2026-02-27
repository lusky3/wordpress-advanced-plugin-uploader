<?php
/**
 * Property test for changelog extraction from ZIP.
 *
 * Feature: bulk-plugin-installer, Property 25: Changelog extraction from ZIP
 *
 * **Validates: Requirements 16.1, 16.3**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIChangelogExtractor;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 25: Changelog extraction from ZIP.
 *
 * For any plugin ZIP archive containing a readme.txt or CHANGELOG.md file,
 * the Changelog_Extractor should extract changelog entries. When filtering
 * between two versions, only entries between those versions should be returned.
 *
 * **Validates: Requirements 16.1, 16.3**
 */
class ChangelogExtractionTest extends TestCase {

    use TestTrait;

    private const TEST_DATE = '2024-01-15';

    private BPIChangelogExtractor $extractor;
    private string $tmpDir;

    protected function setUp(): void {
        $this->extractor = new BPIChangelogExtractor();
        $this->tmpDir    = sys_get_temp_dir() . '/bpi_pbt_cl_' . uniqid();
        mkdir( $this->tmpDir, 0777, true );
    }

    protected function tearDown(): void {
        $this->recursiveDelete( $this->tmpDir );
    }

    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $f ) {
            if ( $f->isDir() ) {

                rmdir( $f->getRealPath() );

            } else {

                unlink( $f->getRealPath() );

            }
        }
        rmdir( $path );
    }

    /**
     * Generate 3 ascending semantic versions from random components.
     *
     * @param int $major Base major version (0-9).
     * @param int $minor Base minor version (0-9).
     * @param int $patch Base patch version (0-9).
     * @return array Three version strings in ascending order.
     */
    private function makeAscendingVersions( int $major, int $minor, int $patch ): array {
        // Ensure all components are positive.
        $major = abs( $major ) % 10;
        $minor = abs( $minor ) % 10;
        $patch = abs( $patch ) % 10;

        $v1 = "{$major}.{$minor}.{$patch}";
        $v2 = "{$major}.{$minor}." . ( $patch + 1 );
        $v3 = "{$major}." . ( $minor + 1 ) . '.0';

        return array( $v1, $v2, $v3 );
    }

    /**
     * Build a readme.txt string with changelog entries for given versions.
     *
     * @param array  $versions     Array of version strings (descending order in output).
     * @param string $tested_up_to WordPress version for "Tested up to" header.
     * @param string $last_updated Date string for "Last Updated" header.
     * @return string readme.txt content.
     */
    private function buildReadme( array $versions, string $tested_up_to, string $last_updated ): string {
        $content  = "=== Test Plugin ===\n";
        $content .= "Tested up to: {$tested_up_to}\n";
        $content .= "Last Updated: {$last_updated}\n\n";
        $content .= "== Changelog ==\n\n";

        // Write versions in descending order (newest first), as is convention.
        $reversed = array_reverse( $versions );
        foreach ( $reversed as $ver ) {
            $content .= "= {$ver} =\n";
            $content .= "* Change for version {$ver}\n\n";
        }

        return $content;
    }

    /**
     * Build a CHANGELOG.md string with entries for given versions.
     *
     * @param array $versions Array of version strings (descending order in output).
     * @return string CHANGELOG.md content.
     */
    private function buildChangelogMd( array $versions ): string {
        $content  = "# Changelog\n\n";

        $reversed = array_reverse( $versions );
        foreach ( $reversed as $ver ) {
            $content .= "## [{$ver}] - 2024-01-15\n";
            $content .= "- Change for version {$ver}\n\n";
        }

        return $content;
    }

    /**
     * Create a ZIP file with the given files inside a plugin subdirectory.
     *
     * @param array  $files Map of filename => content.
     * @param string $slug  Plugin subdirectory name.
     * @return string Path to the created ZIP file.
     */
    private function createZip( array $files, string $slug = 'test-plugin' ): string {
        static $counter = 0;
        $counter++;
        $zip_path = $this->tmpDir . '/plugin_' . $counter . '.zip';

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

        foreach ( $files as $name => $content ) {
            $zip->addFromString( "{$slug}/{$name}", $content );
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * Property 25: Extraction from ZIP with readme.txt returns correct entries.
     *
     * Generate random versions, build a readme.txt, pack into a ZIP,
     * and verify extract() returns the correct number of entries with
     * correct version numbers, last_updated, and tested_up_to.
     *
     * **Validates: Requirements 16.1, 16.3**
     */
    public function test_extract_readme_from_zip_returns_correct_entries(): void {
        $this
            ->forAll(
                Generator\choose( 0, 9 ), // major
                Generator\choose( 0, 9 ), // minor
                Generator\choose( 0, 9 )  // patch
            )
            ->then( function ( int $major, int $minor, int $patch ): void {
                $versions = $this->makeAscendingVersions( $major, $minor, $patch );

                $readme  = $this->buildReadme( $versions, '6.4', self::TEST_DATE );
                $zip_path = $this->createZip( array( 'readme.txt' => $readme ) );

                $result = $this->extractor->extract( $zip_path );

                $this->assertArrayHasKey( 'entries', $result, 'Result must contain entries key.' );
                $this->assertCount(
                    count( $versions ),
                    $result['entries'],
                    'Number of extracted entries must match number of versions.'
                );

                // Entries come out in descending order (newest first) as written.
                $extracted_versions = array_column( $result['entries'], 'version' );
                $expected_desc      = array_reverse( $versions );
                $this->assertSame(
                    $expected_desc,
                    $extracted_versions,
                    'Extracted version numbers must match the generated versions in descending order.'
                );

                $this->assertSame( '6.4', $result['tested_up_to'], 'tested_up_to must be extracted.' );
                $this->assertSame( self::TEST_DATE, $result['last_updated'], 'last_updated must be extracted.' );

                @unlink( $zip_path );
            } );
    }

    /**
     * Property 25: Extraction from ZIP with CHANGELOG.md returns correct entries.
     *
     * Generate random versions, build a CHANGELOG.md, pack into a ZIP,
     * and verify extract() returns the correct number of entries.
     *
     * **Validates: Requirements 16.1, 16.3**
     */
    public function test_extract_changelog_md_from_zip_returns_correct_entries(): void {
        $this
            ->forAll(
                Generator\choose( 0, 9 ), // major
                Generator\choose( 0, 9 ), // minor
                Generator\choose( 0, 9 )  // patch
            )
            ->then( function ( int $major, int $minor, int $patch ): void {
                $versions = $this->makeAscendingVersions( $major, $minor, $patch );

                $changelog = $this->buildChangelogMd( $versions );
                $zip_path  = $this->createZip( array( 'CHANGELOG.md' => $changelog ) );

                $result = $this->extractor->extract( $zip_path );

                $this->assertArrayHasKey( 'entries', $result, 'Result must contain entries key.' );
                $this->assertCount(
                    count( $versions ),
                    $result['entries'],
                    'Number of extracted entries must match number of versions.'
                );

                $extracted_versions = array_column( $result['entries'], 'version' );
                $expected_desc      = array_reverse( $versions );
                $this->assertSame(
                    $expected_desc,
                    $extracted_versions,
                    'Extracted version numbers must match the generated versions in descending order.'
                );

                // CHANGELOG.md extraction should have empty last_updated and tested_up_to.
                $this->assertSame( '', $result['last_updated'], 'CHANGELOG.md should not have last_updated.' );
                $this->assertSame( '', $result['tested_up_to'], 'CHANGELOG.md should not have tested_up_to.' );

                @unlink( $zip_path );
            } );
    }

    /**
     * Property 25: getEntriesBetween() filters correctly for extracted entries.
     *
     * Generate 3 ascending versions, extract from ZIP, then call
     * getEntriesBetween() with from=v1 and to=v3. Only v2 and v3
     * should be returned (v1 is excluded as the lower bound).
     *
     * **Validates: Requirements 16.1, 16.3**
     */
    public function test_get_entries_between_filters_extracted_entries(): void {
        $this
            ->forAll(
                Generator\choose( 0, 9 ), // major
                Generator\choose( 0, 9 ), // minor
                Generator\choose( 0, 9 )  // patch
            )
            ->then( function ( int $major, int $minor, int $patch ): void {
                $versions = $this->makeAscendingVersions( $major, $minor, $patch );

                $readme   = $this->buildReadme( $versions, '6.4', self::TEST_DATE );
                $zip_path = $this->createZip( array( 'readme.txt' => $readme ) );

                $result  = $this->extractor->extract( $zip_path );
                $entries = $result['entries'];

                // Filter: from=v1 (exclusive), to=v3 (inclusive) → should get v2 and v3.
                $filtered = $this->extractor->getEntriesBetween( $entries, $versions[0], $versions[2] );

                $this->assertCount(
                    2,
                    $filtered,
                    "getEntriesBetween('{$versions[0]}', '{$versions[2]}') should return exactly 2 entries (v2 and v3)."
                );

                $filtered_versions = array_column( $filtered, 'version' );
                $this->assertContains( $versions[1], $filtered_versions, "Filtered entries must include v2 ({$versions[1]})." );
                $this->assertContains( $versions[2], $filtered_versions, "Filtered entries must include v3 ({$versions[2]})." );
                $this->assertNotContains( $versions[0], $filtered_versions, "Filtered entries must exclude from-version ({$versions[0]})." );

                @unlink( $zip_path );
            } );
    }

    /**
     * Property 25: getEntriesBetween() with tight range returns single entry.
     *
     * Generate 3 ascending versions, filter from=v1 to=v2 → only v2.
     *
     * **Validates: Requirements 16.1, 16.3**
     */
    public function test_get_entries_between_tight_range_returns_single_entry(): void {
        $this
            ->forAll(
                Generator\choose( 0, 9 ), // major
                Generator\choose( 0, 9 ), // minor
                Generator\choose( 0, 9 )  // patch
            )
            ->then( function ( int $major, int $minor, int $patch ): void {
                $versions = $this->makeAscendingVersions( $major, $minor, $patch );

                $readme   = $this->buildReadme( $versions, '6.4', self::TEST_DATE );
                $zip_path = $this->createZip( array( 'readme.txt' => $readme ) );

                $result  = $this->extractor->extract( $zip_path );
                $entries = $result['entries'];

                // Filter: from=v1 (exclusive), to=v2 (inclusive) → only v2.
                $filtered = $this->extractor->getEntriesBetween( $entries, $versions[0], $versions[1] );

                $this->assertCount(
                    1,
                    $filtered,
                    "getEntriesBetween('{$versions[0]}', '{$versions[1]}') should return exactly 1 entry."
                );

                $this->assertSame(
                    $versions[1],
                    $filtered[0]['version'],
                    "The single filtered entry must be v2 ({$versions[1]})."
                );

                @unlink( $zip_path );
            } );
    }
}
