<?php
/**
 * Unit tests for the BPINotificationManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPINotificationManager;
use BPISettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for email notifications, admin notices, and recipient resolution.
 */
class NotificationManagerTest extends TestCase {

    private const ADMIN_EMAIL = 'admin@example.com';
    private const DEV_EMAIL = 'dev@example.com';
    private const PLUGIN_A = 'Plugin A';
    private const PLUGIN_B = 'Plugin B';

    /**
     * @var BPINotificationManager
     */
    private BPINotificationManager $manager;

    /**
     * @var BPISettingsManager
     */
    private BPISettingsManager $settings;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_hooks, $bpi_test_emails,
            $bpi_test_transients, $bpi_test_current_user_id,
            $bpi_test_current_user_login;

        $bpi_test_options            = array();
        $bpi_test_hooks              = array();
        $bpi_test_emails             = array();
        $bpi_test_transients         = array();
        $bpi_test_current_user_id    = 1;
        $bpi_test_current_user_login = 'admin';

        // Set a default admin email.
        $bpi_test_options['admin_email'] = self::ADMIN_EMAIL;

        $this->settings = new BPISettingsManager();
        $this->manager  = new BPINotificationManager( $this->settings );
    }

    protected function tearDown(): void {
        global $bpi_test_options, $bpi_test_hooks, $bpi_test_emails,
            $bpi_test_transients;

        $bpi_test_options    = array();
        $bpi_test_hooks      = array();
        $bpi_test_emails     = array();
        $bpi_test_transients = array();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Build a sample batch summary.
     */
    private function sampleBatchSummary(): array {
        return array(
            'timestamp' => '2024-06-15 10:30:00',
            'user_id'   => 1,
            'plugins'   => array(
                array(
                    'slug'   => 'plugin-a',
                    'name'   => self::PLUGIN_A,
                    'action' => 'install',
                    'status' => 'success',
                ),
                array(
                    'slug'   => 'plugin-b',
                    'name'   => self::PLUGIN_B,
                    'action' => 'update',
                    'status' => 'failed',
                ),
            ),
            'summary'   => array(
                'total'     => 2,
                'installed' => 1,
                'updated'   => 0,
                'failed'    => 1,
            ),
        );
    }

    /**
     * Build a sample rollback summary.
     */
    private function sampleRollbackSummary(): array {
        return array(
            'batch_id'  => 'bpi_1718444400_1',
            'timestamp' => '2024-06-15 11:00:00',
            'user_id'   => 1,
            'plugins'   => array(
                array(
                    'slug'   => 'plugin-a',
                    'name'   => self::PLUGIN_A,
                    'status' => 'restored',
                ),
                array(
                    'slug'   => 'plugin-b',
                    'name'   => self::PLUGIN_B,
                    'status' => 'removed',
                ),
            ),
            'reason'    => 'Admin requested rollback',
        );
    }

    /**
     * Enable email notifications in settings.
     */
    private function enableEmailNotifications(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_email_notifications'] = true;
    }

    // ---------------------------------------------------------------
    // registerHooks()
    // ---------------------------------------------------------------

    public function test_register_hooks_adds_admin_notices_action(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $this->manager->registerHooks();

        $admin_notice_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'action' === $hook['type'] && 'admin_notices' === $hook['hook'];
        });

        $this->assertNotEmpty( $admin_notice_hooks );
    }

    // ---------------------------------------------------------------
    // getEmailRecipients()
    // ---------------------------------------------------------------

    public function test_get_email_recipients_returns_admin_email(): void {
        $recipients = $this->manager->getEmailRecipients();

        $this->assertContains( self::ADMIN_EMAIL, $recipients );
    }

    public function test_get_email_recipients_includes_additional_recipients(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_email_recipients'] = self::DEV_EMAIL . ', ops@example.com';

        $recipients = $this->manager->getEmailRecipients();

        $this->assertContains( self::ADMIN_EMAIL, $recipients );
        $this->assertContains( self::DEV_EMAIL, $recipients );
        $this->assertContains( 'ops@example.com', $recipients );
        $this->assertCount( 3, $recipients );
    }

    public function test_get_email_recipients_deduplicates_admin_email(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_email_recipients'] = self::ADMIN_EMAIL . ', ' . self::DEV_EMAIL;

        $recipients = $this->manager->getEmailRecipients();

        $this->assertCount( 2, $recipients );
        $this->assertContains( self::ADMIN_EMAIL, $recipients );
        $this->assertContains( self::DEV_EMAIL, $recipients );
    }

    public function test_get_email_recipients_skips_invalid_emails(): void {
        global $bpi_test_options;
        $bpi_test_options['bpi_email_recipients'] = 'valid@example.com, not-an-email, another@test.com';

        $recipients = $this->manager->getEmailRecipients();

        $this->assertContains( self::ADMIN_EMAIL, $recipients );
        $this->assertContains( 'valid@example.com', $recipients );
        $this->assertContains( 'another@test.com', $recipients );
        $this->assertNotContains( 'not-an-email', $recipients );
    }

    public function test_get_email_recipients_returns_empty_when_no_admin_email(): void {
        global $bpi_test_options;
        unset( $bpi_test_options['admin_email'] );

        $recipients = $this->manager->getEmailRecipients();

        $this->assertEmpty( $recipients );
    }

    // ---------------------------------------------------------------
    // sendBatchEmail()
    // ---------------------------------------------------------------

    public function test_send_batch_email_does_nothing_when_disabled(): void {
        global $bpi_test_emails;

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertEmpty( $bpi_test_emails );
    }

    public function test_send_batch_email_sends_when_enabled(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertCount( 1, $bpi_test_emails );
    }

    public function test_send_batch_email_subject_contains_batch_complete(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertStringContainsString( 'Batch Complete', $bpi_test_emails[0]['subject'] );
    }

    public function test_send_batch_email_body_contains_timestamp(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertStringContainsString( '2024-06-15 10:30:00', $bpi_test_emails[0]['message'] );
    }

    public function test_send_batch_email_body_contains_admin_user(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertStringContainsString( 'admin', $bpi_test_emails[0]['message'] );
    }

    public function test_send_batch_email_body_contains_plugin_names(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $body = $bpi_test_emails[0]['message'];
        $this->assertStringContainsString( self::PLUGIN_A, $body );
        $this->assertStringContainsString( self::PLUGIN_B, $body );
    }

    public function test_send_batch_email_body_contains_per_plugin_outcome(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $body = $bpi_test_emails[0]['message'];
        $this->assertStringContainsString( 'success', $body );
        $this->assertStringContainsString( 'failed', $body );
    }

    public function test_send_batch_email_body_contains_summary_counts(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $body = $bpi_test_emails[0]['message'];
        $this->assertStringContainsString( 'Total: 2', $body );
        $this->assertStringContainsString( 'Installed: 1', $body );
        $this->assertStringContainsString( 'Failed: 1', $body );
    }

    public function test_send_batch_email_sent_to_all_recipients(): void {
        global $bpi_test_emails, $bpi_test_options;
        $this->enableEmailNotifications();
        $bpi_test_options['bpi_email_recipients'] = self::DEV_EMAIL;

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $to = $bpi_test_emails[0]['to'];
        $this->assertContains( self::ADMIN_EMAIL, $to );
        $this->assertContains( self::DEV_EMAIL, $to );
    }

    // ---------------------------------------------------------------
    // sendRollbackEmail()
    // ---------------------------------------------------------------

    public function test_send_rollback_email_does_nothing_when_disabled(): void {
        global $bpi_test_emails;

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertEmpty( $bpi_test_emails );
    }

    public function test_send_rollback_email_sends_when_enabled(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertCount( 1, $bpi_test_emails );
    }

    public function test_send_rollback_email_subject_contains_rollback(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertStringContainsString( 'Rollback', $bpi_test_emails[0]['subject'] );
    }

    public function test_send_rollback_email_body_contains_batch_id(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertStringContainsString( 'bpi_1718444400_1', $bpi_test_emails[0]['message'] );
    }

    public function test_send_rollback_email_body_contains_reason(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertStringContainsString( 'Admin requested rollback', $bpi_test_emails[0]['message'] );
    }

    public function test_send_rollback_email_body_contains_plugin_statuses(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $body = $bpi_test_emails[0]['message'];
        $this->assertStringContainsString( self::PLUGIN_A, $body );
        $this->assertStringContainsString( 'restored', $body );
        $this->assertStringContainsString( self::PLUGIN_B, $body );
        $this->assertStringContainsString( 'removed', $body );
    }

    public function test_send_rollback_email_body_contains_timestamp(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $this->manager->sendRollbackEmail( $this->sampleRollbackSummary() );

        $this->assertStringContainsString( '2024-06-15 11:00:00', $bpi_test_emails[0]['message'] );
    }

    // ---------------------------------------------------------------
    // queueAdminNotice()
    // ---------------------------------------------------------------

    public function test_queue_admin_notice_stores_in_transient(): void {
        global $bpi_test_transients;

        $this->manager->queueAdminNotice( 'Test message', 'success' );

        $transient = $bpi_test_transients['bpi_admin_notices_1'] ?? null;
        $this->assertNotNull( $transient );
        $this->assertCount( 1, $transient['value'] );
        $this->assertSame( 'Test message', $transient['value'][0]['message'] );
        $this->assertSame( 'success', $transient['value'][0]['type'] );
    }

    public function test_queue_admin_notice_appends_multiple_notices(): void {
        global $bpi_test_transients;

        $this->manager->queueAdminNotice( 'First', 'success' );
        $this->manager->queueAdminNotice( 'Second', 'error' );

        $notices = $bpi_test_transients['bpi_admin_notices_1']['value'];
        $this->assertCount( 2, $notices );
        $this->assertSame( 'First', $notices[0]['message'] );
        $this->assertSame( 'Second', $notices[1]['message'] );
    }

    public function test_queue_admin_notice_uses_user_id_in_key(): void {
        global $bpi_test_transients, $bpi_test_current_user_id;
        $bpi_test_current_user_id = 42;

        $this->manager->queueAdminNotice( 'User 42 notice', 'info' );

        $this->assertArrayHasKey( 'bpi_admin_notices_42', $bpi_test_transients );
        $this->assertArrayNotHasKey( 'bpi_admin_notices_1', $bpi_test_transients );
    }

    public function test_queue_admin_notice_default_type_is_success(): void {
        global $bpi_test_transients;

        $this->manager->queueAdminNotice( 'Default type' );

        $notices = $bpi_test_transients['bpi_admin_notices_1']['value'];
        $this->assertSame( 'success', $notices[0]['type'] );
    }

    // ---------------------------------------------------------------
    // displayAdminNotices()
    // ---------------------------------------------------------------

    public function test_display_admin_notices_outputs_html(): void {
        $this->manager->queueAdminNotice( 'Hello World', 'success' );

        ob_start();
        $this->manager->displayAdminNotices();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $output );
        $this->assertStringContainsString( 'Hello World', $output );
        $this->assertStringContainsString( 'is-dismissible', $output );
    }

    public function test_display_admin_notices_clears_transient(): void {
        global $bpi_test_transients;

        $this->manager->queueAdminNotice( 'Temporary', 'info' );
        $this->assertArrayHasKey( 'bpi_admin_notices_1', $bpi_test_transients );

        ob_start();
        $this->manager->displayAdminNotices();
        ob_end_clean();

        $this->assertArrayNotHasKey( 'bpi_admin_notices_1', $bpi_test_transients );
    }

    public function test_display_admin_notices_outputs_nothing_when_empty(): void {
        ob_start();
        $this->manager->displayAdminNotices();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_display_admin_notices_renders_multiple(): void {
        $this->manager->queueAdminNotice( 'Success msg', 'success' );
        $this->manager->queueAdminNotice( 'Error msg', 'error' );

        ob_start();
        $this->manager->displayAdminNotices();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $output );
        $this->assertStringContainsString( 'notice-error', $output );
        $this->assertStringContainsString( 'Success msg', $output );
        $this->assertStringContainsString( 'Error msg', $output );
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    public function test_send_batch_email_no_recipients_does_not_send(): void {
        global $bpi_test_emails, $bpi_test_options;
        $this->enableEmailNotifications();
        unset( $bpi_test_options['admin_email'] );

        $this->manager->sendBatchEmail( $this->sampleBatchSummary() );

        $this->assertEmpty( $bpi_test_emails );
    }

    public function test_send_batch_email_with_empty_plugins_array(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $summary = $this->sampleBatchSummary();
        $summary['plugins'] = array();

        $this->manager->sendBatchEmail( $summary );

        $this->assertCount( 1, $bpi_test_emails );
        $this->assertStringContainsString( 'Total: 2', $bpi_test_emails[0]['message'] );
    }

    public function test_send_rollback_email_without_reason(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $summary = $this->sampleRollbackSummary();
        unset( $summary['reason'] );

        $this->manager->sendRollbackEmail( $summary );

        $this->assertCount( 1, $bpi_test_emails );
        $this->assertStringNotContainsString( 'Reason:', $bpi_test_emails[0]['message'] );
    }

    public function test_send_rollback_email_without_batch_id(): void {
        global $bpi_test_emails;
        $this->enableEmailNotifications();

        $summary = $this->sampleRollbackSummary();
        unset( $summary['batch_id'] );

        $this->manager->sendRollbackEmail( $summary );

        $this->assertCount( 1, $bpi_test_emails );
        $this->assertStringNotContainsString( 'Batch ID:', $bpi_test_emails[0]['message'] );
    }
}
