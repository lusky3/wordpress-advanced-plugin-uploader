<?php
/**
 * Unit tests for the BPIChangelogExtractor class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIChangelogExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for changelog parsing, version filtering, update classification,
 * and ZIP extraction.
 */
class ChangelogExtractorTest extends TestCase {

    /**
     * The extractor instance under test.
     *
     * @var BPIChangelogExtractor
     */
    private BPIChangelogExtractor $extractor;

    /**
     * Temporary directory for ZIP files.
     *
     * @var string
     */
    private string $tmpDir;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        $this->extractor = new BPIChangelogExtractor();
        $this->tmpDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bpi_changelog_test_' . uniqid();
        mkdir( $this->tmpDir, 0755, true );
    }

    /**
     * Tear down test fixtures.
     */
    protected function tearDown(): void {
        $this->recursiveDelete( $this->tmpDir );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a ZIP archive with the given files.
     *
     * @param array $files Associative array of path => content inside the ZIP.
     * @return string Path to the created ZIP file.
     */
    private function createZip( array $files ): string {
        $zip_path = $this->tmpDir . DIRECTORY_SEPARATOR . 'test_' . uniqid() . '.zip';
        $zip      = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );

        foreach ( $files as $name => $content ) {
            $zip->addFromString( $name, $content );
        }

        $zip->close();
        return $zip_path;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path Directory path.
     */
    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getPathname() );
            } else {
                unlink( $item->getPathname() );
            }
        }

        rmdir( $path );
    }

    /**
     * Build a sample WordPress readme.txt content.
     *
     * @return string
     */
    private function sampleReadme(): string {
        return <<<'README'
=== My Plugin ===
Contributors: developer
Tags: utility
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 2.1.0
Requires PHP: 7.4
Last Updated: 2024-06-15

A sample plugin for testing.

== Changelog ==

= 2.1.0 - 2024-06-15 =
* Added new dashboard widget
* Fixed compatibility with PHP 8.3

= 2.0.0 - 2024-01-10 =
* Major rewrite of core engine
* Dropped support for PHP 7.2

= 1.5.0 - 2023-09-01 =
* Added REST API endpoints
* Improved caching

= 1.4.2 - 2023-06-20 =
* Fixed XSS vulnerability
* Updated translations

== Upgrade Notice ==

= 2.0.0 =
Major update, please backup before upgrading.
README;
    }

    /**
     * Build a sample CHANGELOG.md content.
     *
     * @return string
     */
    private function sampleChangelogMd(): string {
        return <<<'CHANGELOG'
# Changelog

All notable changes to this project will be documented in this file.

## [3.0.0] - 2024-07-01
- Complete UI overhaul
- New settings API

## [2.5.0] - 2024-04-15
- Added bulk export feature
- Performance improvements

## [2.4.1] - 2024-02-28
- Fixed pagination bug
- Updated dependencies

## 2.4.0 - 2024-01-05
- Added dark mode support
CHANGELOG;
    }

    // ------------------------------------------------------------------
    // parseReadme() tests
    // ------------------------------------------------------------------

    public function test_parse_readme_extracts_changelog_entries(): void {
        $result = $this->extractor->parseReadme( $this->sampleReadme() );

        $this->assertCount( 4, $result['entries'] );

        $this->assertSame( '2.1.0', $result['entries'][0]['version'] );
        $this->assertSame( '2024-06-15', $result['entries'][0]['date'] );
        $this->assertCount( 2, $result['entries'][0]['changes'] );
        $this->assertSame( 'Added new dashboard widget', $result['entries'][0]['changes'][0] );

        $this->assertSame( '2.0.0', $result['entries'][1]['version'] );
        $this->assertSame( '1.5.0', $result['entries'][2]['version'] );
        $this->assertSame( '1.4.2', $result['entries'][3]['version'] );
    }

    public function test_parse_readme_extracts_last_updated_and_tested_up_to(): void {
        $result = $this->extractor->parseReadme( $this->sampleReadme() );

        $this->assertSame( '2024-06-15', $result['last_updated'] );
        $this->assertSame( '6.5', $result['tested_up_to'] );
    }

    public function test_parse_readme_returns_empty_entries_when_no_changelog_section(): void {
        $content = "=== My Plugin ===\nTested up to: 6.5\n\nJust a description.\n";
        $result  = $this->extractor->parseReadme( $content );

        $this->assertSame( array(), $result['entries'] );
        $this->assertSame( '6.5', $result['tested_up_to'] );
    }

    public function test_parse_readme_handles_version_without_date(): void {
        $content = "== Changelog ==\n\n= 1.0.0 =\n* Initial release\n";
        $result  = $this->extractor->parseReadme( $content );

        $this->assertCount( 1, $result['entries'] );
        $this->assertSame( '1.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '', $result['entries'][0]['date'] );
        $this->assertSame( array( 'Initial release' ), $result['entries'][0]['changes'] );
    }

    // ------------------------------------------------------------------
    // parseChangelogMd() tests
    // ------------------------------------------------------------------

    public function test_parse_changelog_md_extracts_entries(): void {
        $result = $this->extractor->parseChangelogMd( $this->sampleChangelogMd() );

        $this->assertCount( 4, $result['entries'] );

        $this->assertSame( '3.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '2024-07-01', $result['entries'][0]['date'] );
        $this->assertCount( 2, $result['entries'][0]['changes'] );
        $this->assertSame( 'Complete UI overhaul', $result['entries'][0]['changes'][0] );

        $this->assertSame( '2.5.0', $result['entries'][1]['version'] );
        $this->assertSame( '2.4.1', $result['entries'][2]['version'] );
        $this->assertSame( '2.4.0', $result['entries'][3]['version'] );
    }

    public function test_parse_changelog_md_handles_version_without_brackets(): void {
        $content = "## 1.0.0 - 2024-01-01\n- First release\n";
        $result  = $this->extractor->parseChangelogMd( $content );

        $this->assertCount( 1, $result['entries'] );
        $this->assertSame( '1.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '2024-01-01', $result['entries'][0]['date'] );
    }

    public function test_parse_changelog_md_returns_empty_for_no_versions(): void {
        $content = "# Changelog\n\nNo versions yet.\n";
        $result  = $this->extractor->parseChangelogMd( $content );

        $this->assertSame( array(), $result['entries'] );
    }

    // ------------------------------------------------------------------
    // getEntriesBetween() tests
    // ------------------------------------------------------------------

    public function test_get_entries_between_filters_correctly(): void {
        $changelog = array(
            array( 'version' => '2.1.0', 'date' => '', 'changes' => array( 'A' ) ),
            array( 'version' => '2.0.0', 'date' => '', 'changes' => array( 'B' ) ),
            array( 'version' => '1.5.0', 'date' => '', 'changes' => array( 'C' ) ),
            array( 'version' => '1.4.2', 'date' => '', 'changes' => array( 'D' ) ),
        );

        // From 1.4.2 to 2.0.0 should include 1.5.0 and 2.0.0.
        $result = $this->extractor->getEntriesBetween( $changelog, '1.4.2', '2.0.0' );

        $this->assertCount( 2, $result );
        $versions = array_column( $result, 'version' );
        $this->assertContains( '2.0.0', $versions );
        $this->assertContains( '1.5.0', $versions );
        $this->assertNotContains( '2.1.0', $versions );
        $this->assertNotContains( '1.4.2', $versions );
    }

    public function test_get_entries_between_returns_empty_when_no_match(): void {
        $changelog = array(
            array( 'version' => '1.0.0', 'date' => '', 'changes' => array() ),
        );

        $result = $this->extractor->getEntriesBetween( $changelog, '1.0.0', '1.0.0' );
        $this->assertSame( array(), $result );
    }

    public function test_get_entries_between_includes_to_version(): void {
        $changelog = array(
            array( 'version' => '1.2.0', 'date' => '', 'changes' => array( 'X' ) ),
        );

        $result = $this->extractor->getEntriesBetween( $changelog, '1.1.0', '1.2.0' );
        $this->assertCount( 1, $result );
        $this->assertSame( '1.2.0', $result[0]['version'] );
    }

    public function test_get_entries_between_excludes_from_version(): void {
        $changelog = array(
            array( 'version' => '1.1.0', 'date' => '', 'changes' => array( 'X' ) ),
        );

        $result = $this->extractor->getEntriesBetween( $changelog, '1.1.0', '1.2.0' );
        $this->assertSame( array(), $result );
    }

    // ------------------------------------------------------------------
    // classifyUpdate() tests
    // ------------------------------------------------------------------

    public function test_classify_update_returns_major(): void {
        $this->assertSame( 'major', $this->extractor->classifyUpdate( '1.0.0', '2.0.0' ) );
        $this->assertSame( 'major', $this->extractor->classifyUpdate( '1.5.3', '3.0.0' ) );
    }

    public function test_classify_update_returns_minor(): void {
        $this->assertSame( 'minor', $this->extractor->classifyUpdate( '1.0.0', '1.1.0' ) );
        $this->assertSame( 'minor', $this->extractor->classifyUpdate( '2.3.1', '2.5.0' ) );
    }

    public function test_classify_update_returns_patch(): void {
        $this->assertSame( 'patch', $this->extractor->classifyUpdate( '1.0.0', '1.0.1' ) );
        $this->assertSame( 'patch', $this->extractor->classifyUpdate( '2.3.1', '2.3.5' ) );
    }

    public function test_classify_update_returns_patch_for_same_version(): void {
        $this->assertSame( 'patch', $this->extractor->classifyUpdate( '1.0.0', '1.0.0' ) );
    }

    public function test_classify_update_handles_two_part_versions(): void {
        $this->assertSame( 'major', $this->extractor->classifyUpdate( '1.0', '2.0' ) );
        $this->assertSame( 'minor', $this->extractor->classifyUpdate( '1.0', '1.1' ) );
    }

    // ------------------------------------------------------------------
    // extract() tests
    // ------------------------------------------------------------------

    public function test_extract_returns_empty_for_zip_without_changelog(): void {
        $zip_path = $this->createZip( array(
            'my-plugin/my-plugin.php' => "<?php\n// Plugin Name: My Plugin\n",
        ) );

        $result = $this->extractor->extract( $zip_path );

        $this->assertSame( array(), $result );
    }

    public function test_extract_finds_readme_in_subdirectory(): void {
        $readme   = $this->sampleReadme();
        $zip_path = $this->createZip( array(
            'my-plugin/my-plugin.php' => "<?php\n// Plugin Name: My Plugin\n",
            'my-plugin/readme.txt'    => $readme,
        ) );

        $result = $this->extractor->extract( $zip_path );

        $this->assertNotEmpty( $result );
        $this->assertArrayHasKey( 'entries', $result );
        $this->assertCount( 4, $result['entries'] );
        $this->assertSame( '2024-06-15', $result['last_updated'] );
        $this->assertSame( '6.5', $result['tested_up_to'] );
    }

    public function test_extract_prefers_readme_over_changelog_md(): void {
        $zip_path = $this->createZip( array(
            'my-plugin/readme.txt'    => "Tested up to: 6.5\n\n== Changelog ==\n\n= 1.0.0 =\n* From readme\n",
            'my-plugin/CHANGELOG.md'  => "## [2.0.0]\n- From changelog md\n",
        ) );

        $result = $this->extractor->extract( $zip_path );

        $this->assertSame( '1.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '6.5', $result['tested_up_to'] );
    }

    public function test_extract_falls_back_to_changelog_md(): void {
        $zip_path = $this->createZip( array(
            'my-plugin/my-plugin.php' => "<?php\n// Plugin Name: My Plugin\n",
            'my-plugin/CHANGELOG.md'  => "## [2.0.0] - 2024-01-01\n- Big change\n",
        ) );

        $result = $this->extractor->extract( $zip_path );

        $this->assertNotEmpty( $result );
        $this->assertSame( '2.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '', $result['last_updated'] );
        $this->assertSame( '', $result['tested_up_to'] );
    }

    public function test_extract_returns_empty_for_nonexistent_file(): void {
        $result = $this->extractor->extract( $this->tmpDir . '/nonexistent.zip' );
        $this->assertSame( array(), $result );
    }

    public function test_extract_finds_readme_at_root_level(): void {
        $zip_path = $this->createZip( array(
            'readme.txt' => "Tested up to: 6.4\n\n== Changelog ==\n\n= 1.0.0 =\n* Root level\n",
        ) );

        $result = $this->extractor->extract( $zip_path );

        $this->assertNotEmpty( $result );
        $this->assertSame( '1.0.0', $result['entries'][0]['version'] );
        $this->assertSame( '6.4', $result['tested_up_to'] );
    }
}
