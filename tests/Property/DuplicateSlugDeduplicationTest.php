<?php
/**
 * Property test for duplicate slug deduplication.
 *
 * Feature: bulk-plugin-installer, Property 2: Duplicate slug deduplication
 *
 * **Validates: Requirements 1.5**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIQueueManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class DuplicateSlugDeduplicationTest extends TestCase {

    use TestTrait;

    private BPIQueueManager $queue;

    protected function setUp(): void {
        $this->queue = new BPIQueueManager();

        // Reset transient storage and user state.
        global $bpi_test_transients, $bpi_test_current_user_id;
        $bpi_test_transients      = array();
        $bpi_test_current_user_id = 1;
    }

    /**
     * Build a plugin data array for a given slug and version suffix.
     *
     * @param string $slug    Plugin slug.
     * @param int    $variant Variant number to differentiate data for the same slug.
     * @return array Plugin data suitable for BPIQueueManager::add().
     */
    private function buildPluginData( string $slug, int $variant = 0 ): array {
        return array(
            'slug'               => $slug,
            'file_name'          => $slug . '-v' . $variant . '.zip',
            'file_size'          => 1000 + $variant,
            'plugin_name'        => ucfirst( $slug ) . ' Plugin v' . $variant,
            'plugin_version'     => '1.' . $variant . '.0',
            'plugin_author'      => 'Author ' . $variant,
            'plugin_description' => 'Description for ' . $slug . ' variant ' . $variant,
            'requires_php'       => '7.4',
            'requires_wp'        => '5.8',
            'action'             => 'install',
            'installed_version'  => null,
        );
    }

    /**
     * Property 2: After adding items with duplicate slugs, the queue contains
     * exactly one entry per unique slug.
     *
     * Generates a list of slugs (drawn from a small pool to ensure duplicates),
     * adds them all to the queue, then verifies uniqueness.
     */
    public function test_queue_contains_one_entry_per_unique_slug(): void {
        $slugPool = array( 'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot' );

        $this
            ->forAll(
                Generator\seq( Generator\elements( ...$slugPool ) )
            )
            ->withMaxSize( 30 )
            ->then( function ( array $slugList ): void {
                if ( empty( $slugList ) ) {
                    return;
                }

                // Reset queue for this iteration.
                global $bpi_test_transients;
                $bpi_test_transients = array();

                // Add each slug to the queue with a variant counter.
                $variant = 0;
                foreach ( $slugList as $slug ) {
                    $variant++;
                    $this->queue->add(
                        '/tmp/' . $slug . '-v' . $variant . '.zip',
                        $this->buildPluginData( $slug, $variant )
                    );
                }

                $queue       = $this->queue->getAll();
                $uniqueSlugs = array_unique( $slugList );

                // 1. Queue count equals number of unique slugs.
                $this->assertCount(
                    count( $uniqueSlugs ),
                    $queue,
                    'Queue must contain exactly one entry per unique slug. ' .
                    'Input slugs: [' . implode( ', ', $slugList ) . '], ' .
                    'unique: ' . count( $uniqueSlugs ) . ', queue: ' . count( $queue )
                );

                // 2. Each unique slug appears exactly once.
                $queueSlugs = array_column( $queue, 'slug' );
                foreach ( $uniqueSlugs as $slug ) {
                    $occurrences = array_count_values( $queueSlugs )[ $slug ] ?? 0;
                    $this->assertSame(
                        1,
                        $occurrences,
                        "Slug '{$slug}' must appear exactly once in the queue."
                    );
                }

                // 3. getCount() matches unique slug count.
                $this->assertSame(
                    count( $uniqueSlugs ),
                    $this->queue->getCount(),
                    'getCount() must equal the number of unique slugs.'
                );
            } );
    }

    /**
     * Property 2: The retained entry for a duplicate slug is the LAST one added.
     *
     * Generates items with duplicate slugs and verifies the queue retains
     * the data from the most recent add() call for each slug.
     */
    public function test_last_added_entry_is_retained_for_duplicate_slugs(): void {
        $slugPool = array( 'plugin-a', 'plugin-b', 'plugin-c' );

        $this
            ->forAll(
                Generator\seq( Generator\elements( ...$slugPool ) )
            )
            ->withMaxSize( 25 )
            ->then( function ( array $slugList ): void {
                if ( empty( $slugList ) ) {
                    return;
                }

                // Reset queue for this iteration.
                global $bpi_test_transients;
                $bpi_test_transients = array();

                // Track the last variant used for each slug.
                $lastVariant = array();
                $variant     = 0;

                foreach ( $slugList as $slug ) {
                    $variant++;
                    $lastVariant[ $slug ] = $variant;
                    $this->queue->add(
                        '/tmp/' . $slug . '-v' . $variant . '.zip',
                        $this->buildPluginData( $slug, $variant )
                    );
                }

                $queue = $this->queue->getAll();

                // For each unique slug, verify the retained entry matches the last add.
                foreach ( $lastVariant as $slug => $expectedVariant ) {
                    $found = null;
                    foreach ( $queue as $item ) {
                        if ( $item['slug'] === $slug ) {
                            $found = $item;
                            break;
                        }
                    }

                    $this->assertNotNull(
                        $found,
                        "Slug '{$slug}' must be present in the queue."
                    );

                    $expectedVersion = '1.' . $expectedVariant . '.0';
                    $this->assertSame(
                        $expectedVersion,
                        $found['plugin_version'],
                        "Slug '{$slug}' must retain the last added version ({$expectedVersion}), " .
                        "but found '{$found['plugin_version']}'."
                    );

                    $expectedFileName = $slug . '-v' . $expectedVariant . '.zip';
                    $this->assertSame(
                        $expectedFileName,
                        $found['file_name'],
                        "Slug '{$slug}' must retain the last added file name."
                    );
                }
            } );
    }
}
