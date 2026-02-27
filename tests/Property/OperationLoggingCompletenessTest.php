<?php
/**
 * Property test for operation logging completeness.
 *
 * Feature: bulk-plugin-installer, Property 14: Operation logging completeness
 *
 * Generates batch operations with random action types, plugin names, and outcomes,
 * then verifies each log entry contains timestamp, user_id, plugin names, action type,
 * outcome, and is_dry_run flag.
 *
 * **Validates: Requirements 8.3, 8.5, 19.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPILogManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 14: Operation logging completeness.
 *
 * For any bulk operation (install, update, rollback, batch_rollback, or dry_run),
 * the Log_Manager should create a log entry containing the timestamp, user ID,
 * plugin slug, plugin name, action type, status, and is_dry_run flag.
 */
class OperationLoggingCompletenessTest extends TestCase {

    use TestTrait;

    private const VALID_ACTIONS = array( 'install', 'update', 'rollback', 'batch_rollback', 'dry_run' );

    private const VALID_STATUSES = array( 'success', 'failed', 'rolled_back' );

    private BPILogManager $logManager;

    protected function setUp(): void {
        global $wpdb;
        $wpdb->reset_bpi_log();
        $this->logManager = new BPILogManager();
    }

    /**
     * Build a generator for plugin-slug-like strings (lowercase alphanumeric with hyphens).
     */
    private function pluginSlugGenerator(): \Eris\Generator {
        $chars = array_merge( range( 'a', 'z' ), range( '0', '9' ), array( '-' ) );

        return Generator\map(
            function ( array $charArray ): string {
                // Ensure it starts with a letter and is non-empty.
                $slug = implode( '', $charArray );
                // Prefix with 'p' to guarantee it starts with a letter.
                return 'p' . $slug;
            },
            Generator\vector(
                8,
                Generator\elements( $chars )
            )
        );
    }

    /**
     * Build a generator for plugin display names (readable strings).
     */
    private function pluginNameGenerator(): \Eris\Generator {
        return Generator\map(
            function ( string $name ): string {
                // names() returns realistic names; prefix to ensure non-empty after sanitize.
                return 'Plugin ' . $name;
            },
            Generator\names()
        );
    }

    /**
     * Property 14: Every logged operation contains all required fields.
     *
     * Generate random action types, plugin slugs, plugin names, statuses,
     * is_dry_run booleans, and user IDs, then verify each logged entry
     * contains: timestamp, user_id, plugin_slug, plugin_name, action, status, is_dry_run.
     */
    public function test_every_log_entry_contains_all_required_fields(): void {
        $this
            ->forAll(
                Generator\elements( self::VALID_ACTIONS ),
                $this->pluginSlugGenerator(),
                $this->pluginNameGenerator(),
                Generator\elements( self::VALID_STATUSES ),
                Generator\bool(),
                Generator\choose( 1, 9999 )
            )
            ->__invoke( function (
                string $action,
                string $plugin_slug,
                string $plugin_name,
                string $status,
                bool $is_dry_run,
                int $user_id
            ): void {
                global $wpdb, $bpi_test_current_user_id;

                $wpdb->reset_bpi_log();
                $bpi_test_current_user_id = $user_id;

                $this->logManager->log( $action, array(
                    'plugin_slug' => $plugin_slug,
                    'plugin_name' => $plugin_name,
                    'status'      => $status,
                    'is_dry_run'  => $is_dry_run,
                ) );

                $rows = $wpdb->bpi_log_rows;
                $this->assertCount( 1, $rows, 'Exactly one log entry should be created per log() call.' );

                $row = $rows[0];

                // Verify timestamp is present and non-empty.
                $this->assertArrayHasKey( 'timestamp', $row );
                $this->assertNotEmpty( $row['timestamp'], 'Timestamp must not be empty.' );

                // Verify user_id matches the current user.
                $this->assertArrayHasKey( 'user_id', $row );
                $this->assertSame( $user_id, $row['user_id'], 'user_id must match the current user.' );

                // Verify plugin_slug is present and non-empty.
                $this->assertArrayHasKey( 'plugin_slug', $row );
                $this->assertNotEmpty( $row['plugin_slug'], 'plugin_slug must not be empty.' );

                // Verify plugin_name is present and non-empty.
                $this->assertArrayHasKey( 'plugin_name', $row );
                $this->assertNotEmpty( $row['plugin_name'], 'plugin_name must not be empty.' );

                // Verify action type matches input.
                $this->assertArrayHasKey( 'action', $row );
                $this->assertSame( $action, $row['action'], 'Action must match the logged action type.' );

                // Verify status (outcome) matches input.
                $this->assertArrayHasKey( 'status', $row );
                $this->assertSame( $status, $row['status'], 'Status must match the logged outcome.' );

                // Verify is_dry_run flag is correct.
                $this->assertArrayHasKey( 'is_dry_run', $row );
                $expected_dry_run = $is_dry_run ? 1 : 0;
                $this->assertSame( $expected_dry_run, $row['is_dry_run'], 'is_dry_run flag must reflect the input.' );
            } );
    }

    /**
     * Property 14 (dry run distinguishability): Dry run entries are distinguishable
     * from non-dry-run entries via the is_dry_run flag.
     */
    public function test_dry_run_entries_are_distinguishable(): void {
        $this
            ->forAll(
                Generator\elements( self::VALID_ACTIONS ),
                $this->pluginSlugGenerator(),
                Generator\elements( self::VALID_STATUSES ),
                Generator\choose( 1, 9999 )
            )
            ->__invoke( function (
                string $action,
                string $plugin_slug,
                string $status,
                int $user_id
            ): void {
                global $wpdb, $bpi_test_current_user_id;

                $wpdb->reset_bpi_log();
                $bpi_test_current_user_id = $user_id;

                // Log a real operation.
                $this->logManager->log( $action, array(
                    'plugin_slug' => $plugin_slug,
                    'plugin_name' => 'Test Plugin',
                    'status'      => $status,
                    'is_dry_run'  => false,
                ) );

                // Log a dry run operation.
                $this->logManager->log( $action, array(
                    'plugin_slug' => $plugin_slug,
                    'plugin_name' => 'Test Plugin',
                    'status'      => $status,
                    'is_dry_run'  => true,
                ) );

                $rows = $wpdb->bpi_log_rows;
                $this->assertCount( 2, $rows, 'Two log entries should exist.' );

                // First entry: real operation.
                $this->assertSame( 0, $rows[0]['is_dry_run'], 'Real operation must have is_dry_run = 0.' );

                // Second entry: dry run.
                $this->assertSame( 1, $rows[1]['is_dry_run'], 'Dry run operation must have is_dry_run = 1.' );
            } );
    }
}
