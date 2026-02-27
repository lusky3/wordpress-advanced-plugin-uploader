<?php
/**
 * Property test for queue addition preserving existing items.
 *
 * Feature: bulk-plugin-installer, Property 19: Queue addition preserves existing items
 *
 * **Validates: Requirements 13.3, 13.4, 13.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIQueueManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class QueueAdditionPreservationTest extends TestCase {

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
     * Build a plugin data array for a given slug and file size.
     *
     * @param string $slug     Plugin slug.
     * @param int    $fileSize File size in bytes.
     * @return array Plugin data suitable for BPIQueueManager::add().
     */
    private function buildPluginData( string $slug, int $fileSize ): array {
        return array(
            'slug'               => $slug,
            'file_name'          => $slug . '.zip',
            'file_size'          => $fileSize,
            'plugin_name'        => ucfirst( $slug ) . ' Plugin',
            'plugin_version'     => '1.0.0',
            'plugin_author'      => 'Author of ' . $slug,
            'plugin_description' => 'Description for ' . $slug,
            'requires_php'       => '7.4',
            'requires_wp'        => '5.8',
            'action'             => 'install',
            'installed_version'  => null,
        );
    }

    /**
     * Add a set of plugins to the queue and return their data keyed by slug.
     *
     * @param array $slugs    Slug list.
     * @param int   $baseSize Base file size.
     * @param int   $offset   Size offset multiplier.
     * @param int   $step     Size step per index.
     * @return array Plugin data keyed by slug.
     */
    private function addPluginsToQueue( array $slugs, int $baseSize, int $offset, int $step ): array {
        $dataMap = array();
        foreach ( $slugs as $idx => $slug ) {
            $size = $baseSize + $offset + ( $idx * $step );
            $data = $this->buildPluginData( $slug, $size );
            $this->queue->add( '/tmp/' . $slug . '.zip', $data );
            $dataMap[ $slug ] = $data;
        }
        return $dataMap;
    }

    /**
     * Assert that all items in $dataMap are present and unchanged in $afterBySlug.
     *
     * @param array $dataMap     Original data keyed by slug.
     * @param array $afterBySlug Queue items keyed by slug.
     */
    private function assertItemsPreserved( array $dataMap, array $afterBySlug ): void {
        foreach ( $dataMap as $slug => $originalData ) {
            $this->assertArrayHasKey( $slug, $afterBySlug, "Item '{$slug}' must be present." );
            $found = $afterBySlug[ $slug ];
            $this->assertSame( $originalData['file_size'], $found['file_size'], "File size for '{$slug}' must be unchanged." );
            $this->assertSame( $originalData['plugin_name'], $found['plugin_name'], "Plugin name for '{$slug}' must be unchanged." );
            $this->assertSame( $originalData['plugin_version'], $found['plugin_version'], "Plugin version for '{$slug}' must be unchanged." );
            $this->assertSame( $originalData['plugin_author'], $found['plugin_author'], "Plugin author for '{$slug}' must be unchanged." );
        }
    }

    /**
     * Sum file sizes from a data map.
     *
     * @param array $dataMap Plugin data keyed by slug.
     * @return int Total file size.
     */
    private function sumFileSizes( array $dataMap ): int {
        $total = 0;
        foreach ( $dataMap as $data ) {
            $total += $data['file_size'];
        }
        return $total;
    }

    /**
     * Generate a list of slugs with a given prefix.
     *
     * @param string $prefix Slug prefix.
     * @param int    $count  Number of slugs.
     * @return array List of slugs.
     */
    private function generateSlugs( string $prefix, int $count ): array {
        $slugs = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $slugs[] = $prefix . $i;
        }
        return $slugs;
    }

    /**
     * Property 19: Adding new files with different slugs does not remove or alter
     * previously queued items. Total count equals previous + new, and combined
     * file size equals sum of all individual sizes.
     *
     * Generates two disjoint sets of slugs (existing and new), adds existing first,
     * then adds new, and verifies preservation of existing items.
     */
    public function test_adding_new_items_preserves_existing_queue_items(): void {
        $this
            ->forAll(
                Generator\choose( 1, 8 ),  // number of existing items
                Generator\choose( 1, 8 ),  // number of new items
                Generator\choose( 100, 50000 ) // base file size
            )
            ->then( function ( int $existingCount, int $newCount, int $baseSize ): void {
                global $bpi_test_transients;
                $bpi_test_transients = array();

                $existingSlugs = $this->generateSlugs( 'existing-plugin-', $existingCount );
                $newSlugs      = $this->generateSlugs( 'new-plugin-', $newCount );

                $existingData = $this->addPluginsToQueue( $existingSlugs, $baseSize, 0, 100 );

                $countBefore = $this->queue->getCount();
                $sizeBefore  = $this->queue->getTotalSize();
                $this->assertCount( $existingCount, $this->queue->getAll() );

                $newData = $this->addPluginsToQueue( $newSlugs, $baseSize, 50000, 200 );

                $queueAfter = $this->queue->getAll();
                $countAfter = $this->queue->getCount();
                $sizeAfter  = $this->queue->getTotalSize();

                // 1. Total count equals previous count + new count.
                $this->assertSame( $countBefore + $newCount, $countAfter );

                // 2. All original items remain with their original data intact.
                $afterBySlug = array();
                foreach ( $queueAfter as $item ) {
                    $afterBySlug[ $item['slug'] ] = $item;
                }
                $this->assertItemsPreserved( $existingData, $afterBySlug );

                // 3. All new items are present.
                foreach ( $newData as $slug => $_data ) {
                    $this->assertArrayHasKey( $slug, $afterBySlug, "New item '{$slug}' must be present." );
                }

                // 4. Combined file size equals sum of all individual sizes.
                $expectedTotalSize = $this->sumFileSizes( $existingData ) + $this->sumFileSizes( $newData );
                $this->assertSame( $expectedTotalSize, $sizeAfter );

                // 5. Size after equals size before + sum of new sizes.
                $this->assertSame( $sizeBefore + $this->sumFileSizes( $newData ), $sizeAfter );
            } );
    }
}
