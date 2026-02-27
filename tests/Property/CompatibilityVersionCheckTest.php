<?php
/**
 * Property test for compatibility version checking.
 *
 * Feature: bulk-plugin-installer, Property 17: Compatibility version checking
 *
 * **Validates: Requirements 12.1, 12.2, 12.3, 12.4**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPICompatibilityChecker;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class CompatibilityVersionCheckTest extends TestCase {

    use TestTrait;

    private BPICompatibilityChecker $checker;

    protected function setUp(): void {
        $this->checker = new BPICompatibilityChecker();

        // Reset global WP version to default.
        global $bpi_test_wp_version;
        $bpi_test_wp_version = '6.7.0';
    }

    /**
     * Generate a random semantic version string (major.minor.patch).
     *
     * @return \Eris\Generator
     */
    private function versionGenerator() {
        return Generator\map(
            function ( array $parts ): string {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
            },
            Generator\tuple(
                Generator\choose( 0, 20 ),
                Generator\choose( 0, 30 ),
                Generator\choose( 0, 50 )
            )
        );
    }

    /**
     * Build plugin data array with given version requirements.
     *
     * @param string $requires_php PHP version requirement (empty string for none).
     * @param string $requires_wp  WP version requirement (empty string for none).
     * @return array Plugin data suitable for checkPlugin().
     */
    private function buildPluginData( string $requires_php, string $requires_wp ): array {
        return array(
            'slug'               => 'test-plugin',
            'plugin_name'        => 'Test Plugin',
            'plugin_version'     => '1.0.0',
            'requires_php'       => $requires_php,
            'requires_wp'        => $requires_wp,
        );
    }

    /**
     * Property 17 (PHP): When a plugin requires a PHP version higher than
     * the current PHP_VERSION, checkPlugin() flags it with a 'php_version' issue.
     * When the requirement is met, no php_version issue is returned.
     *
     * Since PHP_VERSION is a compile-time constant, we generate random required
     * versions and compare against the actual PHP_VERSION.
     */
    public function test_php_version_incompatibility_flagged_correctly(): void {
        $this
            ->forAll( $this->versionGenerator() )
            ->then( function ( string $requiredVersion ): void {
                $pluginData = $this->buildPluginData( $requiredVersion, '' );
                $issues     = $this->checker->checkPlugin( $pluginData );

                $phpIssues = array_filter( $issues, function ( $issue ) {
                    return $issue['type'] === 'php_version';
                } );

                $isIncompatible = version_compare( PHP_VERSION, $requiredVersion, '<' );

                if ( $isIncompatible ) {
                    $this->assertNotEmpty(
                        $phpIssues,
                        "Plugin requiring PHP {$requiredVersion} should be flagged as incompatible " .
                        "(current: " . PHP_VERSION . ")."
                    );

                    $issue = array_values( $phpIssues )[0];
                    $this->assertSame( $requiredVersion, $issue['required'] );
                    $this->assertSame( PHP_VERSION, $issue['current'] );
                } else {
                    $this->assertEmpty(
                        $phpIssues,
                        "Plugin requiring PHP {$requiredVersion} should NOT be flagged " .
                        "(current: " . PHP_VERSION . ")."
                    );
                }
            } );
    }

    /**
     * Property 17 (WP): When a plugin requires a WP version higher than
     * the current WP version, checkPlugin() flags it with a 'wp_version' issue.
     * When the requirement is met, no wp_version issue is returned.
     *
     * We set $bpi_test_wp_version to a random "current" version, then generate
     * a random "required" version and verify the checker's behavior.
     */
    public function test_wp_version_incompatibility_flagged_correctly(): void {
        $this
            ->forAll(
                $this->versionGenerator(), // current WP version
                $this->versionGenerator()  // required WP version
            )
            ->then( function ( string $currentWp, string $requiredWp ): void {
                global $bpi_test_wp_version;
                $bpi_test_wp_version = $currentWp;

                $pluginData = $this->buildPluginData( '', $requiredWp );
                $issues     = $this->checker->checkPlugin( $pluginData );

                $wpIssues = array_filter( $issues, function ( $issue ) {
                    return $issue['type'] === 'wp_version';
                } );

                $isIncompatible = version_compare( $currentWp, $requiredWp, '<' );

                if ( $isIncompatible ) {
                    $this->assertNotEmpty(
                        $wpIssues,
                        "Plugin requiring WP {$requiredWp} should be flagged as incompatible " .
                        "(current: {$currentWp})."
                    );

                    $issue = array_values( $wpIssues )[0];
                    $this->assertSame( $requiredWp, $issue['required'] );
                    $this->assertSame( $currentWp, $issue['current'] );
                } else {
                    $this->assertEmpty(
                        $wpIssues,
                        "Plugin requiring WP {$requiredWp} should NOT be flagged " .
                        "(current: {$currentWp})."
                    );
                }
            } );
    }

    /**
     * Property 17 (Combined): When both PHP and WP requirements are met,
     * checkPlugin() returns no issues. When either is unmet, the
     * corresponding issue is present.
     */
    public function test_combined_compatibility_check(): void {
        $this
            ->forAll(
                $this->versionGenerator(), // current WP version
                $this->versionGenerator(), // required PHP version
                $this->versionGenerator()  // required WP version
            )
            ->then( function ( string $currentWp, string $requiredPhp, string $requiredWp ): void {
                global $bpi_test_wp_version;
                $bpi_test_wp_version = $currentWp;

                $pluginData = $this->buildPluginData( $requiredPhp, $requiredWp );
                $issues     = $this->checker->checkPlugin( $pluginData );

                $phpIncompat = version_compare( PHP_VERSION, $requiredPhp, '<' );
                $wpIncompat  = version_compare( $currentWp, $requiredWp, '<' );

                $issueTypes = array_column( $issues, 'type' );

                if ( $phpIncompat ) {
                    $this->assertContains( 'php_version', $issueTypes,
                        "PHP incompatibility should be flagged (requires {$requiredPhp}, current " . PHP_VERSION . ")." );
                } else {
                    $this->assertNotContains( 'php_version', $issueTypes,
                        "PHP should be compatible (requires {$requiredPhp}, current " . PHP_VERSION . ")." );
                }

                if ( $wpIncompat ) {
                    $this->assertContains( 'wp_version', $issueTypes,
                        "WP incompatibility should be flagged (requires {$requiredWp}, current {$currentWp})." );
                } else {
                    $this->assertNotContains( 'wp_version', $issueTypes,
                        "WP should be compatible (requires {$requiredWp}, current {$currentWp})." );
                }

                // When both are compatible, no issues at all.
                if ( ! $phpIncompat && ! $wpIncompat ) {
                    $this->assertEmpty( $issues,
                        "No issues expected when both PHP and WP requirements are met." );
                }
            } );
    }

    /**
     * Assert compatibility issues are correctly populated for a single queue item.
     */
    private function assertItemCompatibility( array $item, int $idx, string $currentWp ): void {
        $this->assertArrayHasKey( 'compatibility_issues', $item,
            "Queue item {$idx} must have 'compatibility_issues' key." );

        $issueTypes = array_column( $item['compatibility_issues'], 'type' );

        if ( version_compare( PHP_VERSION, $item['requires_php'], '<' ) ) {
            $this->assertContains( 'php_version', $issueTypes );
        }
        if ( version_compare( $currentWp, $item['requires_wp'], '<' ) ) {
            $this->assertContains( 'wp_version', $issueTypes );
        }
    }

    /**
     * Property 17 (checkAll): Verify checkAll() populates compatibility_issues
     * on each queue item correctly for random version combinations.
     */
    public function test_check_all_populates_issues_on_queue_items(): void {
        $this
            ->forAll(
                $this->versionGenerator(), // current WP version
                Generator\choose( 1, 5 )   // number of plugins
            )
            ->then( function ( string $currentWp, int $pluginCount ): void {
                global $bpi_test_wp_version;
                $bpi_test_wp_version = $currentWp;

                $queue = array();
                for ( $i = 0; $i < $pluginCount; $i++ ) {
                    $phpReq = ( $i % 2 === 0 ) ? '99.0.0' : '5.0.0';
                    $wpReq  = ( $i % 3 === 0 ) ? '99.0.0' : '4.0.0';

                    $queue[] = array(
                        'slug'           => 'plugin-' . $i,
                        'plugin_name'    => 'Plugin ' . $i,
                        'plugin_version' => '1.0.0',
                        'requires_php'   => $phpReq,
                        'requires_wp'    => $wpReq,
                    );
                }

                $result = $this->checker->checkAll( $queue );

                $this->assertCount( $pluginCount, $result );

                foreach ( $result as $idx => $item ) {
                    $this->assertItemCompatibility( $item, $idx, $currentWp );
                }
            } );
    }
}
