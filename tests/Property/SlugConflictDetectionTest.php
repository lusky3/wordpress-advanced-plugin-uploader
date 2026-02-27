<?php
/**
 * Property test for slug conflict detection.
 *
 * Feature: bulk-plugin-installer, Property 18: Slug conflict detection
 *
 * **Validates: Requirements 12.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPICompatibilityChecker;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class SlugConflictDetectionTest extends TestCase {

    use TestTrait;

    private BPICompatibilityChecker $checker;

    protected function setUp(): void {
        $this->checker = new BPICompatibilityChecker();

        // Reset global WP version to default.
        global $bpi_test_wp_version;
        $bpi_test_wp_version = '6.7.0';
    }

    /**
     * Build a queue item with the given slug and variant.
     *
     * @param string $slug    Plugin slug.
     * @param int    $variant Variant number to differentiate items.
     * @return array Queue item array.
     */
    private function buildQueueItem( string $slug, int $variant = 0 ): array {
        return array(
            'slug'               => $slug,
            'file_name'          => $slug . '-v' . $variant . '.zip',
            'file_size'          => 1000 + $variant,
            'plugin_name'        => ucfirst( $slug ) . ' Plugin v' . $variant,
            'plugin_version'     => '1.' . $variant . '.0',
            'plugin_author'      => 'Author ' . $variant,
            'plugin_description' => 'Description ' . $variant,
            'requires_php'       => '',
            'requires_wp'        => '',
            'action'             => 'install',
            'installed_version'  => null,
        );
    }

    /**
     * Property 18: checkSlugConflicts() detects slugs appearing more than once
     * and does NOT report unique slugs.
     *
     * Generates a queue from a small slug pool (ensuring duplicates) and verifies:
     * 1. Slugs appearing 2+ times are reported as conflicts.
     * 2. The reported count matches the actual number of occurrences.
     * 3. Unique slugs do NOT appear in the conflicts.
     */
    public function test_slug_conflicts_detected_for_duplicates_only(): void {
        $slugPool = array( 'alpha', 'bravo', 'charlie', 'delta', 'echo' );

        $this
            ->forAll(
                Generator\seq( Generator\elements( ...$slugPool ) )
            )
            ->withMaxSize( 30 )
            ->then( function ( array $slugList ): void {
                if ( count( $slugList ) < 2 ) {
                    return;
                }

                // Build queue with one item per slug occurrence.
                $queue   = array();
                $variant = 0;
                foreach ( $slugList as $slug ) {
                    $variant++;
                    $queue[] = $this->buildQueueItem( $slug, $variant );
                }

                $conflicts = $this->checker->checkSlugConflicts( $queue );

                // Count actual occurrences of each slug.
                $slugCounts = array_count_values( $slugList );

                foreach ( $slugCounts as $slug => $count ) {
                    if ( $count > 1 ) {
                        // Duplicate slug must be reported.
                        $this->assertArrayHasKey(
                            $slug,
                            $conflicts,
                            "Slug '{$slug}' appears {$count} times and must be reported as a conflict."
                        );

                        // Verify the conflict contains exactly one issue entry.
                        $this->assertCount(
                            1,
                            $conflicts[ $slug ],
                            "Conflict for slug '{$slug}' should contain exactly one issue entry."
                        );

                        $issue = $conflicts[ $slug ][0];

                        // Verify issue type.
                        $this->assertSame(
                            'slug_conflict',
                            $issue['type'],
                            "Conflict issue for '{$slug}' must have type 'slug_conflict'."
                        );

                        // Verify count matches actual occurrences.
                        $this->assertSame(
                            $count,
                            $issue['count'],
                            "Conflict count for '{$slug}' must be {$count}, got {$issue['count']}."
                        );

                        // Verify slug is included in the issue.
                        $this->assertSame(
                            $slug,
                            $issue['slug'],
                            "Conflict issue must include the slug '{$slug}'."
                        );
                    } else {
                        // Unique slug must NOT be reported.
                        $this->assertArrayNotHasKey(
                            $slug,
                            $conflicts,
                            "Slug '{$slug}' appears only once and must NOT be in conflicts."
                        );
                    }
                }

                // No extra slugs in conflicts beyond what we expect.
                foreach ( array_keys( $conflicts ) as $conflictSlug ) {
                    $this->assertGreaterThan(
                        1,
                        $slugCounts[ $conflictSlug ] ?? 0,
                        "Conflict reported for '{$conflictSlug}' but it doesn't appear more than once."
                    );
                }
            } );
    }

    /**
     * Property 18: checkAll() includes slug_conflict issues in the
     * compatibility_issues of all items that share a conflicting slug.
     *
     * Generates a queue with a mix of unique and duplicate slugs, runs
     * checkAll(), and verifies that every item with a conflicting slug
     * has a slug_conflict issue, while items with unique slugs do not.
     */
    public function test_check_all_adds_slug_conflict_issues_to_affected_items(): void {
        $slugPool = array( 'foo', 'bar', 'baz', 'qux' );

        $this
            ->forAll(
                Generator\seq( Generator\elements( ...$slugPool ) )
            )
            ->withMaxSize( 25 )
            ->then( function ( array $slugList ): void {
                if ( count( $slugList ) < 2 ) {
                    return;
                }

                $queue   = array();
                $variant = 0;
                foreach ( $slugList as $slug ) {
                    $variant++;
                    $queue[] = $this->buildQueueItem( $slug, $variant );
                }

                $result     = $this->checker->checkAll( $queue );
                $slugCounts = array_count_values( $slugList );

                $this->assertCount(
                    count( $queue ),
                    $result,
                    'checkAll() must return the same number of items as the input queue.'
                );

                foreach ( $result as $idx => $item ) {
                    $this->assertArrayHasKey(
                        'compatibility_issues',
                        $item,
                        "Queue item {$idx} must have 'compatibility_issues' key."
                    );

                    $slug       = $item['slug'];
                    $issueTypes = array_column( $item['compatibility_issues'], 'type' );

                    if ( $slugCounts[ $slug ] > 1 ) {
                        $this->assertContains(
                            'slug_conflict',
                            $issueTypes,
                            "Item '{$slug}' (index {$idx}) has duplicates and must have a slug_conflict issue."
                        );
                    } else {
                        $this->assertNotContains(
                            'slug_conflict',
                            $issueTypes,
                            "Item '{$slug}' (index {$idx}) is unique and must NOT have a slug_conflict issue."
                        );
                    }
                }
            } );
    }

    /**
     * Property 18: A queue with all unique slugs produces zero conflicts.
     *
     * Generates a queue where every item has a distinct slug and verifies
     * checkSlugConflicts() returns an empty array.
     */
    public function test_all_unique_slugs_produce_no_conflicts(): void {
        $this
            ->forAll(
                Generator\choose( 1, 15 )
            )
            ->then( function ( int $count ): void {
                $queue = array();
                for ( $i = 0; $i < $count; $i++ ) {
                    $queue[] = $this->buildQueueItem( 'unique-plugin-' . $i, $i );
                }

                $conflicts = $this->checker->checkSlugConflicts( $queue );

                $this->assertEmpty(
                    $conflicts,
                    "A queue with {$count} unique slugs must produce zero conflicts, " .
                    'got ' . count( $conflicts ) . '.'
                );
            } );
    }
}
