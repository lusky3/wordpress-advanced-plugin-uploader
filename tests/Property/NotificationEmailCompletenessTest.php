<?php
/**
 * Property test for notification email content completeness.
 *
 * Feature: bulk-plugin-installer, Property 26: Notification email content completeness
 *
 * **Validates: Requirements 18.1, 18.3, 18.4, 18.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPINotificationManager;
use BPISettingsManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 26: Notification email content completeness.
 *
 * For any completed batch operation when email notifications are enabled,
 * the sent email should contain the timestamp, the admin user who performed
 * the operation, the list of all processed plugins, and the outcome
 * (success/failed/rolled back) for each plugin.
 *
 * **Validates: Requirements 18.1, 18.3, 18.4, 18.6**
 */
class NotificationEmailCompletenessTest extends TestCase {

    use TestTrait;

    private BPINotificationManager $manager;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_emails, $bpi_test_current_user_login;

        $bpi_test_options            = array();
        $bpi_test_emails             = array();
        $bpi_test_current_user_login = 'admin';

        $settings      = new BPISettingsManager();
        $this->manager = new BPINotificationManager( $settings );
    }

    protected function tearDown(): void {
        global $bpi_test_options, $bpi_test_emails, $bpi_test_current_user_login;

        $bpi_test_options            = array();
        $bpi_test_emails             = array();
        $bpi_test_current_user_login = 'admin';
    }

    /**
     * Property 26: Email body contains timestamp, admin user, every plugin
     * name, every plugin outcome, and correct summary counts.
     *
     * Generate batch results with random plugins (1-10), random names/slugs,
     * random actions (install/update), random statuses (success/failed),
     * and random timestamps. Verify the email body includes all of them.
     *
     * **Validates: Requirements 18.1, 18.3, 18.4, 18.6**
     */
    public function test_email_contains_all_batch_details(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),    // number of plugins
                Generator\choose( 0, 32767 )  // seed for randomisation
            )
            ->then( function ( int $pluginCount, int $seed ): void {
                global $bpi_test_options, $bpi_test_emails, $bpi_test_current_user_login;

                // Reset globals for each iteration.
                $bpi_test_options                          = array();
                $bpi_test_emails                           = array();
                $bpi_test_options['bpi_email_notifications'] = true;
                $bpi_test_options['admin_email']            = 'admin@example.com';

                // Generate a random admin username.
                $rng       = ( $seed * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $usernames = array( 'alice', 'bob', 'charlie', 'dave', 'eve', 'frank' );
                $username  = $usernames[ $rng % count( $usernames ) ];
                $bpi_test_current_user_login = $username;

                // Generate a random timestamp.
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $year  = 2020 + ( $rng % 5 );
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $month = 1 + ( $rng % 12 );
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $day   = 1 + ( $rng % 28 );
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $hour  = $rng % 24;
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $min   = $rng % 60;
                $rng   = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                $sec   = $rng % 60;

                $timestamp = sprintf(
                    '%04d-%02d-%02d %02d:%02d:%02d',
                    $year, $month, $day, $hour, $min, $sec
                );

                // Build plugins array with generated data.
                $actions  = array( 'install', 'update' );
                $statuses = array( 'success', 'failed' );
                $plugins  = array();

                $installed_count = 0;
                $updated_count   = 0;
                $failed_count    = 0;

                for ( $i = 0; $i < $pluginCount; $i++ ) {
                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $action = $actions[ $rng % 2 ];

                    $rng    = ( $rng * 1103515245 + 12345 ) & 0x7FFFFFFF;
                    $status = $statuses[ $rng % 2 ];

                    $slug = 'gen-plugin-' . $i . '-' . ( $rng % 1000 );
                    $name = 'Generated Plugin ' . $i . ' V' . ( $rng % 100 );

                    $plugins[] = array(
                        'slug'   => $slug,
                        'name'   => $name,
                        'action' => $action,
                        'status' => $status,
                    );

                    if ( 'failed' === $status ) {
                        $failed_count++;
                    } elseif ( 'install' === $action ) {
                        $installed_count++;
                    } else {
                        $updated_count++;
                    }
                }

                $summary = array(
                    'total'     => $pluginCount,
                    'installed' => $installed_count,
                    'updated'   => $updated_count,
                    'failed'    => $failed_count,
                );

                $batch_summary = array(
                    'timestamp' => $timestamp,
                    'user_id'   => 1,
                    'plugins'   => $plugins,
                    'summary'   => $summary,
                );

                // Send the email.
                $this->manager->sendBatchEmail( $batch_summary );

                // Verify an email was sent.
                $this->assertCount(
                    1,
                    $bpi_test_emails,
                    'Exactly one email should be sent for the batch.'
                );

                $body = $bpi_test_emails[0]['message'];

                // 1. Email contains the timestamp.
                $this->assertStringContainsString(
                    $timestamp,
                    $body,
                    "Email body must contain the timestamp '$timestamp'."
                );

                // 2. Email contains the admin username.
                $this->assertStringContainsString(
                    $username,
                    $body,
                    "Email body must contain the admin username '$username'."
                );

                // 3. Email contains every plugin name.
                foreach ( $plugins as $plugin ) {
                    $this->assertStringContainsString(
                        $plugin['name'],
                        $body,
                        "Email body must contain plugin name '{$plugin['name']}'."
                    );
                }

                // 4. Email contains every plugin's outcome (status).
                foreach ( $plugins as $plugin ) {
                    $this->assertStringContainsString(
                        $plugin['status'],
                        $body,
                        "Email body must contain status '{$plugin['status']}' for plugin '{$plugin['name']}'."
                    );
                }

                // 5. Summary counts match the generated data.
                $this->assertStringContainsString(
                    'Total: ' . $pluginCount,
                    $body,
                    "Email body must contain 'Total: $pluginCount'."
                );
                $this->assertStringContainsString(
                    'Installed: ' . $installed_count,
                    $body,
                    "Email body must contain 'Installed: $installed_count'."
                );
                $this->assertStringContainsString(
                    'Updated: ' . $updated_count,
                    $body,
                    "Email body must contain 'Updated: $updated_count'."
                );
                $this->assertStringContainsString(
                    'Failed: ' . $failed_count,
                    $body,
                    "Email body must contain 'Failed: $failed_count'."
                );
            } );
    }
}
