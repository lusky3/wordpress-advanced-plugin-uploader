<?php
/**
 * Unit tests for the BPICompatibilityChecker class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPICompatibilityChecker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for compatibility checking: PHP version, WP version,
 * single plugin checks, checkAll, and slug conflict detection.
 */
class CompatibilityCheckerTest extends TestCase {

    /**
     * The checker instance under test.
     *
     * @var BPICompatibilityChecker
     */
    private BPICompatibilityChecker $checker;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        global $bpi_test_wp_version;
        $bpi_test_wp_version = '6.7.0';

        $this->checker = new BPICompatibilityChecker();
    }

    /**
     * Reset globals after each test.
     */
    protected function tearDown(): void {
        global $bpi_test_wp_version;
        $bpi_test_wp_version = '6.7.0';
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    /**
     * Build a plugin data array for testing.
     *
     * @param array $overrides Optional field overrides.
     * @return array Plugin data.
     */
    private function makePluginData( array $overrides = array() ): array {
        return array_merge(
            array(
                'slug'           => 'test-plugin',
                'plugin_name'    => 'Test Plugin',
                'plugin_version' => '1.0.0',
                'requires_php'   => '',
                'requires_wp'    => '',
            ),
            $overrides
        );
    }

    // ---------------------------------------------------------------
    // checkPhpVersion() tests
    // ---------------------------------------------------------------

    public function test_check_php_version_passes_when_requirement_met(): void {
        // Current PHP is 8.3.x, requiring 7.4 should pass.
        $this->assertTrue( $this->checker->checkPhpVersion( '7.4' ) );
    }

    public function test_check_php_version_passes_for_exact_match(): void {
        $this->assertTrue( $this->checker->checkPhpVersion( PHP_VERSION ) );
    }

    public function test_check_php_version_fails_when_requirement_exceeds_current(): void {
        // Require a version far in the future.
        $this->assertFalse( $this->checker->checkPhpVersion( '99.0.0' ) );
    }

    // ---------------------------------------------------------------
    // checkWpVersion() tests
    // ---------------------------------------------------------------

    public function test_check_wp_version_passes_when_requirement_met(): void {
        $this->assertTrue( $this->checker->checkWpVersion( '5.0' ) );
    }

    public function test_check_wp_version_passes_for_exact_match(): void {
        $this->assertTrue( $this->checker->checkWpVersion( '6.7.0' ) );
    }

    public function test_check_wp_version_fails_when_requirement_exceeds_current(): void {
        $this->assertFalse( $this->checker->checkWpVersion( '99.0.0' ) );
    }

    public function test_check_wp_version_respects_global_override(): void {
        global $bpi_test_wp_version;
        $bpi_test_wp_version = '5.5.0';

        $this->assertTrue( $this->checker->checkWpVersion( '5.5' ) );
        $this->assertFalse( $this->checker->checkWpVersion( '6.0' ) );
    }

    // ---------------------------------------------------------------
    // checkPlugin() tests
    // ---------------------------------------------------------------

    public function test_check_plugin_returns_empty_for_compatible_plugin(): void {
        $data   = $this->makePluginData( array(
            'requires_php' => '7.4',
            'requires_wp'  => '5.0',
        ) );
        $issues = $this->checker->checkPlugin( $data );

        $this->assertSame( array(), $issues );
    }

    public function test_check_plugin_returns_empty_when_no_requirements(): void {
        $data   = $this->makePluginData();
        $issues = $this->checker->checkPlugin( $data );

        $this->assertSame( array(), $issues );
    }

    public function test_check_plugin_returns_php_issue_for_incompatible_php(): void {
        $data   = $this->makePluginData( array( 'requires_php' => '99.0.0' ) );
        $issues = $this->checker->checkPlugin( $data );

        $this->assertCount( 1, $issues );
        $this->assertSame( 'php_version', $issues[0]['type'] );
        $this->assertSame( '99.0.0', $issues[0]['required'] );
        $this->assertSame( PHP_VERSION, $issues[0]['current'] );
        $this->assertNotEmpty( $issues[0]['message'] );
    }

    public function test_check_plugin_returns_wp_issue_for_incompatible_wp(): void {
        $data   = $this->makePluginData( array( 'requires_wp' => '99.0.0' ) );
        $issues = $this->checker->checkPlugin( $data );

        $this->assertCount( 1, $issues );
        $this->assertSame( 'wp_version', $issues[0]['type'] );
        $this->assertSame( '99.0.0', $issues[0]['required'] );
        $this->assertSame( '6.7.0', $issues[0]['current'] );
        $this->assertNotEmpty( $issues[0]['message'] );
    }

    public function test_check_plugin_returns_both_issues_when_both_incompatible(): void {
        $data   = $this->makePluginData( array(
            'requires_php' => '99.0.0',
            'requires_wp'  => '99.0.0',
        ) );
        $issues = $this->checker->checkPlugin( $data );

        $this->assertCount( 2, $issues );
        $types = array_column( $issues, 'type' );
        $this->assertContains( 'php_version', $types );
        $this->assertContains( 'wp_version', $types );
    }

    // ---------------------------------------------------------------
    // checkSlugConflicts() tests
    // ---------------------------------------------------------------

    public function test_check_slug_conflicts_detects_duplicate_slugs(): void {
        $queue = array(
            $this->makePluginData( array( 'slug' => 'my-plugin' ) ),
            $this->makePluginData( array( 'slug' => 'other-plugin' ) ),
            $this->makePluginData( array( 'slug' => 'my-plugin' ) ),
        );

        $conflicts = $this->checker->checkSlugConflicts( $queue );

        $this->assertArrayHasKey( 'my-plugin', $conflicts );
        $this->assertArrayNotHasKey( 'other-plugin', $conflicts );
        $this->assertSame( 'slug_conflict', $conflicts['my-plugin'][0]['type'] );
        $this->assertSame( 2, $conflicts['my-plugin'][0]['count'] );
    }

    public function test_check_slug_conflicts_returns_empty_for_unique_slugs(): void {
        $queue = array(
            $this->makePluginData( array( 'slug' => 'plugin-a' ) ),
            $this->makePluginData( array( 'slug' => 'plugin-b' ) ),
        );

        $conflicts = $this->checker->checkSlugConflicts( $queue );

        $this->assertSame( array(), $conflicts );
    }

    public function test_check_slug_conflicts_returns_empty_for_empty_queue(): void {
        $conflicts = $this->checker->checkSlugConflicts( array() );
        $this->assertSame( array(), $conflicts );
    }

    // ---------------------------------------------------------------
    // checkAll() tests
    // ---------------------------------------------------------------

    public function test_check_all_populates_compatibility_issues_on_each_item(): void {
        $queue = array(
            $this->makePluginData( array(
                'slug'         => 'compatible-plugin',
                'requires_php' => '7.4',
                'requires_wp'  => '5.0',
            ) ),
            $this->makePluginData( array(
                'slug'         => 'incompatible-plugin',
                'requires_php' => '99.0.0',
                'requires_wp'  => '99.0.0',
            ) ),
        );

        $result = $this->checker->checkAll( $queue );

        $this->assertCount( 2, $result );
        $this->assertSame( array(), $result[0]['compatibility_issues'] );
        $this->assertCount( 2, $result[1]['compatibility_issues'] );
    }

    public function test_check_all_includes_slug_conflicts(): void {
        $queue = array(
            $this->makePluginData( array( 'slug' => 'dupe-plugin' ) ),
            $this->makePluginData( array( 'slug' => 'dupe-plugin' ) ),
        );

        $result = $this->checker->checkAll( $queue );

        // Both items should have the slug conflict issue.
        $this->assertNotEmpty( $result[0]['compatibility_issues'] );
        $this->assertNotEmpty( $result[1]['compatibility_issues'] );
        $this->assertSame( 'slug_conflict', $result[0]['compatibility_issues'][0]['type'] );
    }

    public function test_check_all_handles_empty_queue(): void {
        $result = $this->checker->checkAll( array() );
        $this->assertSame( array(), $result );
    }
}
