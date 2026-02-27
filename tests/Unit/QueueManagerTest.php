<?php
/**
 * Unit tests for the BPIQueueManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIQueueManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for queue management: add, remove, getAll, clear,
 * getCount, getTotalSize, hasDuplicate, and AJAX handler.
 */
class QueueManagerTest extends TestCase {

    /**
     * The queue manager instance under test.
     *
     * @var BPIQueueManager
     */
    private BPIQueueManager $queue;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        global $bpi_test_transients, $bpi_test_nonce_valid, $bpi_test_user_can,
            $bpi_test_json_responses, $bpi_test_current_user_id;

        $bpi_test_transients     = array();
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $bpi_test_current_user_id = 1;

        $this->queue = new BPIQueueManager();
    }

    /**
     * Reset globals after each test.
     */
    protected function tearDown(): void {
        global $bpi_test_transients;
        $bpi_test_transients = array();
    }

    // ---------------------------------------------------------------
    // Helper: create plugin data array
    // ---------------------------------------------------------------

    /**
     * Build a plugin data array for queue addition.
     *
     * @param string $slug      Plugin slug.
     * @param int    $file_size File size in bytes.
     * @param array  $overrides Optional field overrides.
     * @return array Plugin data.
     */
    private function makePluginData(
        string $slug = 'my-plugin',
        int $file_size = 10240,
        array $overrides = array()
    ): array {
        return array_merge(
            array(
                'slug'               => $slug,
                'file_name'          => $slug . '.zip',
                'file_size'          => $file_size,
                'plugin_name'        => ucwords( str_replace( '-', ' ', $slug ) ),
                'plugin_version'     => '1.0.0',
                'plugin_author'      => 'Test Author',
                'plugin_description' => 'A test plugin.',
                'requires_php'       => '7.4',
                'requires_wp'        => '5.8',
                'action'             => 'install',
                'installed_version'  => null,
                'compatibility_issues' => array(),
                'changelog'          => array(),
            ),
            $overrides
        );
    }

    // ---------------------------------------------------------------
    // add() tests
    // ---------------------------------------------------------------

    public function test_add_stores_item_in_queue(): void {
        $result = $this->queue->add( '/tmp/my-plugin.zip', $this->makePluginData() );

        $this->assertTrue( $result );
        $this->assertSame( 1, $this->queue->getCount() );
    }

    public function test_add_returns_false_for_empty_slug(): void {
        $data = $this->makePluginData();
        $data['slug'] = '';

        $result = $this->queue->add( '/tmp/test.zip', $data );

        $this->assertFalse( $result );
        $this->assertSame( 0, $this->queue->getCount() );
    }

    public function test_add_stores_correct_item_data(): void {
        $data = $this->makePluginData( 'test-plugin', 5000 );
        $this->queue->add( '/tmp/test-plugin.zip', $data );

        $items = $this->queue->getAll();
        $this->assertCount( 1, $items );

        $item = $items[0];
        $this->assertSame( 'test-plugin', $item['slug'] );
        $this->assertSame( '/tmp/test-plugin.zip', $item['file_path'] );
        $this->assertSame( 'test-plugin.zip', $item['file_name'] );
        $this->assertSame( 5000, $item['file_size'] );
        $this->assertSame( 'Test Plugin', $item['plugin_name'] );
        $this->assertSame( '1.0.0', $item['plugin_version'] );
        $this->assertSame( 'Test Author', $item['plugin_author'] );
        $this->assertSame( 'install', $item['action'] );
        $this->assertArrayHasKey( 'added_at', $item );
    }

    public function test_add_multiple_items(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a', 1000 ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b', 2000 ) );
        $this->queue->add( '/tmp/c.zip', $this->makePluginData( 'plugin-c', 3000 ) );

        $this->assertSame( 3, $this->queue->getCount() );
    }

    // ---------------------------------------------------------------
    // Duplicate handling tests
    // ---------------------------------------------------------------

    public function test_add_deduplicates_by_slug(): void {
        $this->queue->add( '/tmp/v1.zip', $this->makePluginData( 'my-plugin', 1000, array( 'plugin_version' => '1.0.0' ) ) );
        $this->queue->add( '/tmp/v2.zip', $this->makePluginData( 'my-plugin', 2000, array( 'plugin_version' => '2.0.0' ) ) );

        $this->assertSame( 1, $this->queue->getCount() );

        $items = $this->queue->getAll();
        $this->assertSame( '2.0.0', $items[0]['plugin_version'] );
        $this->assertSame( 2000, $items[0]['file_size'] );
    }

    public function test_add_duplicate_preserves_other_items(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a', 1000 ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b', 2000 ) );

        // Add duplicate of plugin-a.
        $this->queue->add( '/tmp/a-v2.zip', $this->makePluginData( 'plugin-a', 1500, array( 'plugin_version' => '2.0.0' ) ) );

        $this->assertSame( 2, $this->queue->getCount() );

        $slugs = array_column( $this->queue->getAll(), 'slug' );
        $this->assertContains( 'plugin-a', $slugs );
        $this->assertContains( 'plugin-b', $slugs );
    }

    public function test_has_duplicate_returns_true_for_existing_slug(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );

        $this->assertTrue( $this->queue->hasDuplicate( 'plugin-a' ) );
    }

    public function test_has_duplicate_returns_false_for_missing_slug(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );

        $this->assertFalse( $this->queue->hasDuplicate( 'plugin-b' ) );
    }

    public function test_has_duplicate_returns_false_on_empty_queue(): void {
        $this->assertFalse( $this->queue->hasDuplicate( 'anything' ) );
    }

    // ---------------------------------------------------------------
    // remove() tests
    // ---------------------------------------------------------------

    public function test_remove_existing_item(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b' ) );

        $result = $this->queue->remove( 'plugin-a' );

        $this->assertTrue( $result );
        $this->assertSame( 1, $this->queue->getCount() );
        $this->assertFalse( $this->queue->hasDuplicate( 'plugin-a' ) );
        $this->assertTrue( $this->queue->hasDuplicate( 'plugin-b' ) );
    }

    public function test_remove_nonexistent_item_returns_false(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );

        $result = $this->queue->remove( 'nonexistent' );

        $this->assertFalse( $result );
        $this->assertSame( 1, $this->queue->getCount() );
    }

    public function test_remove_last_item_deletes_transient(): void {
        global $bpi_test_transients;

        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );
        $this->queue->remove( 'plugin-a' );

        $this->assertSame( 0, $this->queue->getCount() );
        $this->assertArrayNotHasKey( 'bpi_queue_1', $bpi_test_transients );
    }

    // ---------------------------------------------------------------
    // getAll() tests
    // ---------------------------------------------------------------

    public function test_get_all_returns_empty_array_when_no_queue(): void {
        $this->assertSame( array(), $this->queue->getAll() );
    }

    public function test_get_all_returns_all_items(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b' ) );

        $items = $this->queue->getAll();
        $this->assertCount( 2, $items );
    }

    // ---------------------------------------------------------------
    // clear() tests
    // ---------------------------------------------------------------

    public function test_clear_removes_all_items(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b' ) );

        $this->queue->clear();

        $this->assertSame( 0, $this->queue->getCount() );
        $this->assertSame( array(), $this->queue->getAll() );
    }

    public function test_clear_on_empty_queue_does_not_error(): void {
        $this->queue->clear();
        $this->assertSame( 0, $this->queue->getCount() );
    }

    // ---------------------------------------------------------------
    // getCount() tests
    // ---------------------------------------------------------------

    public function test_get_count_returns_zero_for_empty_queue(): void {
        $this->assertSame( 0, $this->queue->getCount() );
    }

    public function test_get_count_reflects_additions(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );
        $this->assertSame( 1, $this->queue->getCount() );

        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b' ) );
        $this->assertSame( 2, $this->queue->getCount() );
    }

    // ---------------------------------------------------------------
    // getTotalSize() tests
    // ---------------------------------------------------------------

    public function test_get_total_size_returns_zero_for_empty_queue(): void {
        $this->assertSame( 0, $this->queue->getTotalSize() );
    }

    public function test_get_total_size_sums_all_file_sizes(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a', 1000 ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b', 2500 ) );
        $this->queue->add( '/tmp/c.zip', $this->makePluginData( 'plugin-c', 500 ) );

        $this->assertSame( 4000, $this->queue->getTotalSize() );
    }

    public function test_get_total_size_updates_after_removal(): void {
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a', 1000 ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b', 2000 ) );

        $this->queue->remove( 'plugin-a' );

        $this->assertSame( 2000, $this->queue->getTotalSize() );
    }

    // ---------------------------------------------------------------
    // User isolation tests
    // ---------------------------------------------------------------

    public function test_queue_is_isolated_per_user(): void {
        global $bpi_test_current_user_id;

        $bpi_test_current_user_id = 1;
        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a' ) );

        $bpi_test_current_user_id = 2;
        $this->assertSame( 0, $this->queue->getCount() );

        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b' ) );
        $this->assertSame( 1, $this->queue->getCount() );

        // Switch back to user 1.
        $bpi_test_current_user_id = 1;
        $this->assertSame( 1, $this->queue->getCount() );
        $this->assertTrue( $this->queue->hasDuplicate( 'plugin-a' ) );
        $this->assertFalse( $this->queue->hasDuplicate( 'plugin-b' ) );
    }

    // ---------------------------------------------------------------
    // AJAX handler tests
    // ---------------------------------------------------------------

    public function test_handle_queue_remove_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce'] = 'invalid';
        $_POST['slug']     = 'my-plugin';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['slug'] );
    }

    public function test_handle_queue_remove_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce'] = 'valid';
        $_POST['slug']     = 'my-plugin';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['slug'] );
    }

    public function test_handle_queue_remove_rejects_empty_slug(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['slug']     = '';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 400, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['slug'] );
    }

    public function test_handle_queue_remove_returns_404_for_missing_slug(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['slug']     = 'nonexistent';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 404, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['_wpnonce'], $_POST['slug'] );
    }

    public function test_handle_queue_remove_successfully_removes_item(): void {
        global $bpi_test_json_responses;

        $this->queue->add( '/tmp/a.zip', $this->makePluginData( 'plugin-a', 1000 ) );
        $this->queue->add( '/tmp/b.zip', $this->makePluginData( 'plugin-b', 2000 ) );

        $_POST['_wpnonce'] = 'valid';
        $_POST['slug']     = 'plugin-a';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        $response_data = $bpi_test_json_responses[0]['data'];
        $this->assertSame( 1, $response_data['count'] );
        $this->assertSame( 2000, $response_data['size'] );
        $this->assertCount( 1, $response_data['queue'] );

        unset( $_POST['_wpnonce'], $_POST['slug'] );
    }

    public function test_handle_queue_remove_rejects_missing_nonce(): void {
        global $bpi_test_json_responses;

        $_POST['slug'] = 'my-plugin';

        $this->queue->handleQueueRemove();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );

        unset( $_POST['slug'] );
    }
}
