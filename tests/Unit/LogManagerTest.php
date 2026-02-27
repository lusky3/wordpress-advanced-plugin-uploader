<?php
/**
 * Unit tests for the BPILogManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPILogManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Log Manager CRUD operations and table lifecycle.
 */
class LogManagerTest extends TestCase {

    private const PLUGIN_NAME = 'My Plugin';
    private const VERSION_100 = '1.0.0';

    /**
     * The Log Manager instance under test.
     *
     * @var BPILogManager
     */
    private BPILogManager $logManager;

    /**
     * Reset global state and create a fresh Log Manager before each test.
     */
    protected function setUp(): void {
        global $wpdb, $bpi_test_current_user_id;

        $wpdb->reset_bpi_log();
        $bpi_test_current_user_id = 1;
        $this->logManager        = new BPILogManager();
    }

    /**
     * Test that createTable() calls dbDelta and marks the table as created.
     */
    public function test_create_table_marks_table_as_created(): void {
        global $wpdb;

        $this->assertFalse( $wpdb->bpi_log_table_exists );

        $this->logManager->createTable();

        $this->assertTrue( $wpdb->bpi_log_table_exists );
    }

    /**
     * Test that dropTable() resets the table state.
     */
    public function test_drop_table_removes_table(): void {
        global $wpdb;

        $this->logManager->createTable();
        $this->assertTrue( $wpdb->bpi_log_table_exists );

        $this->logManager->dropTable();
        $this->assertFalse( $wpdb->bpi_log_table_exists );
    }

    /**
     * Test that log() inserts a row with all expected fields.
     */
    public function test_log_inserts_entry_with_all_fields(): void {
        global $wpdb;

        $this->logManager->log( 'install', array(
            'batch_id'     => 'batch_001',
            'plugin_slug'  => 'my-plugin',
            'plugin_name'  => self::PLUGIN_NAME,
            'from_version' => '',
            'to_version'   => self::VERSION_100,
            'status'       => 'success',
            'message'      => 'Installed successfully.',
            'is_dry_run'   => false,
        ) );

        $this->assertCount( 1, $wpdb->bpi_log_rows );

        $row = $wpdb->bpi_log_rows[0];
        $this->assertSame( 'install', $row['action'] );
        $this->assertSame( 'batch_001', $row['batch_id'] );
        $this->assertSame( 'my-plugin', $row['plugin_slug'] );
        $this->assertSame( self::PLUGIN_NAME, $row['plugin_name'] );
        $this->assertSame( '', $row['from_version'] );
        $this->assertSame( self::VERSION_100, $row['to_version'] );
        $this->assertSame( 'success', $row['status'] );
        $this->assertSame( 'Installed successfully.', $row['message'] );
        $this->assertSame( 0, $row['is_dry_run'] );
        $this->assertSame( 1, $row['user_id'] );
        $this->assertNotEmpty( $row['timestamp'] );
    }

    /**
     * Test that log() records the correct user ID.
     */
    public function test_log_records_current_user_id(): void {
        global $wpdb, $bpi_test_current_user_id;

        $bpi_test_current_user_id = 42;

        $this->logManager->log( 'update', array(
            'plugin_slug' => 'test-plugin',
            'status'      => 'success',
        ) );

        $this->assertSame( 42, $wpdb->bpi_log_rows[0]['user_id'] );
    }

    /**
     * Test that log() sets is_dry_run = 1 when flagged.
     */
    public function test_log_sets_dry_run_flag(): void {
        global $wpdb;

        $this->logManager->log( 'dry_run', array(
            'plugin_slug' => 'test-plugin',
            'status'      => 'success',
            'is_dry_run'  => true,
        ) );

        $this->assertSame( 1, $wpdb->bpi_log_rows[0]['is_dry_run'] );
    }

    /**
     * Test that log() defaults missing detail fields to empty strings.
     */
    public function test_log_defaults_missing_fields(): void {
        global $wpdb;

        $this->logManager->log( 'install', array() );

        $row = $wpdb->bpi_log_rows[0];
        $this->assertSame( '', $row['batch_id'] );
        $this->assertSame( '', $row['plugin_slug'] );
        $this->assertSame( '', $row['plugin_name'] );
        $this->assertSame( '', $row['from_version'] );
        $this->assertSame( '', $row['to_version'] );
        $this->assertSame( '', $row['status'] );
        $this->assertSame( '', $row['message'] );
        $this->assertSame( 0, $row['is_dry_run'] );
    }

    /**
     * Test that getEntries() returns entries in reverse chronological order.
     */
    public function test_get_entries_returns_entries_in_order(): void {
        $this->logManager->log( 'install', array(
            'plugin_slug' => 'first-plugin',
            'status'      => 'success',
        ) );

        // Slight delay to ensure different timestamps.
        usleep( 1100000 ); // 1.1 seconds to get a different second.

        $this->logManager->log( 'update', array(
            'plugin_slug' => 'second-plugin',
            'status'      => 'success',
        ) );

        $entries = $this->logManager->getEntries();

        $this->assertCount( 2, $entries );
        // Most recent first.
        $this->assertSame( 'second-plugin', $entries[0]->plugin_slug );
        $this->assertSame( 'first-plugin', $entries[1]->plugin_slug );
    }

    /**
     * Test that getEntries() respects the limit parameter.
     */
    public function test_get_entries_respects_limit(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->logManager->log( 'install', array(
                'plugin_slug' => "plugin-{$i}",
                'status'      => 'success',
            ) );
        }

        $entries = $this->logManager->getEntries( 3 );
        $this->assertCount( 3, $entries );
    }

    /**
     * Test that getEntries() respects the offset parameter.
     */
    public function test_get_entries_respects_offset(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->logManager->log( 'install', array(
                'plugin_slug' => "plugin-{$i}",
                'status'      => 'success',
            ) );
        }

        $entries = $this->logManager->getEntries( 50, 2 );
        $this->assertCount( 3, $entries );
    }

    /**
     * Test that getEntries() returns empty array when no entries exist.
     */
    public function test_get_entries_returns_empty_when_no_entries(): void {
        $entries = $this->logManager->getEntries();
        $this->assertIsArray( $entries );
        $this->assertEmpty( $entries );
    }

    /**
     * Test that clear() removes all log entries.
     */
    public function test_clear_removes_all_entries(): void {
        $this->logManager->log( 'install', array(
            'plugin_slug' => 'plugin-a',
            'status'      => 'success',
        ) );
        $this->logManager->log( 'update', array(
            'plugin_slug' => 'plugin-b',
            'status'      => 'failed',
        ) );

        $this->assertCount( 2, $this->logManager->getEntries() );

        $this->logManager->clear();

        $this->assertEmpty( $this->logManager->getEntries() );
    }

    /**
     * Test that dropTable() also clears all entries.
     */
    public function test_drop_table_clears_entries(): void {
        $this->logManager->log( 'install', array(
            'plugin_slug' => 'plugin-a',
            'status'      => 'success',
        ) );

        $this->logManager->dropTable();

        $this->assertEmpty( $this->logManager->getEntries() );
    }

    /**
     * Test that log entries get auto-incrementing IDs.
     */
    public function test_log_entries_have_auto_incrementing_ids(): void {
        global $wpdb;

        $this->logManager->log( 'install', array( 'plugin_slug' => 'a' ) );
        $this->logManager->log( 'install', array( 'plugin_slug' => 'b' ) );
        $this->logManager->log( 'install', array( 'plugin_slug' => 'c' ) );

        $this->assertSame( 1, $wpdb->bpi_log_rows[0]['id'] );
        $this->assertSame( 2, $wpdb->bpi_log_rows[1]['id'] );
        $this->assertSame( 3, $wpdb->bpi_log_rows[2]['id'] );
    }

    /**
     * Test that all valid action types can be logged.
     */
    public function test_log_accepts_all_action_types(): void {
        $actions = array( 'install', 'update', 'rollback', 'batch_rollback', 'dry_run' );

        foreach ( $actions as $action ) {
            $this->logManager->log( $action, array(
                'plugin_slug' => 'test-plugin',
                'status'      => 'success',
            ) );
        }

        $entries = $this->logManager->getEntries( 100 );
        $this->assertCount( 5, $entries );

        $logged_actions = array_map( fn( $e ) => $e->action, $entries );
        foreach ( $actions as $action ) {
            $this->assertContains( $action, $logged_actions );
        }
    }

    /**
     * Test that all valid status values can be logged.
     */
    public function test_log_accepts_all_status_values(): void {
        $statuses = array( 'success', 'failed', 'rolled_back' );

        foreach ( $statuses as $status ) {
            $this->logManager->log( 'install', array(
                'plugin_slug' => 'test-plugin',
                'status'      => $status,
            ) );
        }

        $entries = $this->logManager->getEntries( 100 );
        $this->assertCount( 3, $entries );

        $logged_statuses = array_map( fn( $e ) => $e->status, $entries );
        foreach ( $statuses as $status ) {
            $this->assertContains( $status, $logged_statuses );
        }
    }

    /**
     * Test that log() records update details with from_version and to_version.
     */
    public function test_log_records_version_info_for_updates(): void {
        global $wpdb;

        $this->logManager->log( 'update', array(
            'plugin_slug'  => 'my-plugin',
            'plugin_name'  => self::PLUGIN_NAME,
            'from_version' => self::VERSION_100,
            'to_version'   => '2.0.0',
            'status'       => 'success',
        ) );

        $row = $wpdb->bpi_log_rows[0];
        $this->assertSame( self::VERSION_100, $row['from_version'] );
        $this->assertSame( '2.0.0', $row['to_version'] );
    }
}
