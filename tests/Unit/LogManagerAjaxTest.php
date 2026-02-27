<?php
/**
 * Unit tests for BPILogManager AJAX handlers (handleGetLog, handleClearLog).
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPILogManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AJAX handler methods in BPILogManager.
 */
class LogManagerAjaxTest extends TestCase {

    private BPILogManager $manager;

    protected function setUp(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses, $wpdb;
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $wpdb->reset_bpi_log();
        unset( $_GET['_wpnonce'], $_POST['_wpnonce'], $_GET['limit'], $_GET['offset'] );

        $this->manager = new BPILogManager();
    }

    protected function tearDown(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can;
        $bpi_test_nonce_valid = true;
        $bpi_test_user_can    = true;
        unset( $_GET['_wpnonce'], $_POST['_wpnonce'], $_GET['limit'], $_GET['offset'] );
    }

    public function test_handle_get_log_rejects_missing_nonce(): void {
        global $bpi_test_json_responses;

        $this->manager->handleGetLog();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_handle_get_log_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;
        $_GET['_wpnonce']     = 'bad';

        $this->manager->handleGetLog();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_handle_get_log_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;
        $_GET['_wpnonce']  = 'valid';

        $this->manager->handleGetLog();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_handle_get_log_returns_entries(): void {
        global $bpi_test_json_responses;
        $_GET['_wpnonce'] = 'valid';

        $this->manager->log( 'install', array( 'plugin_slug' => 'test-plugin', 'status' => 'success' ) );
        $this->manager->handleGetLog();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertNotEmpty( $bpi_test_json_responses[0]['data']['entries'] );
    }

    public function test_handle_get_log_respects_limit_and_offset(): void {
        global $bpi_test_json_responses;
        $_GET['_wpnonce'] = 'valid';
        $_GET['limit']    = '1';
        $_GET['offset']   = '0';

        $this->manager->log( 'install', array( 'plugin_slug' => 'a', 'status' => 'success' ) );
        $this->manager->log( 'install', array( 'plugin_slug' => 'b', 'status' => 'success' ) );
        $this->manager->handleGetLog();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertCount( 1, $bpi_test_json_responses[0]['data']['entries'] );
    }

    public function test_handle_get_log_accepts_post_nonce(): void {
        global $bpi_test_json_responses;
        $_POST['_wpnonce'] = 'valid';

        $this->manager->handleGetLog();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        unset( $_POST['_wpnonce'] );
    }

    public function test_handle_clear_log_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid  = false;
        $_POST['_wpnonce']     = 'bad';

        $this->manager->handleClearLog();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
        unset( $_POST['_wpnonce'] );
    }

    public function test_handle_clear_log_rejects_missing_nonce(): void {
        global $bpi_test_json_responses;

        $this->manager->handleClearLog();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_handle_clear_log_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;
        $_POST['_wpnonce'] = 'valid';

        $this->manager->handleClearLog();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
        unset( $_POST['_wpnonce'] );
    }

    public function test_handle_clear_log_clears_entries(): void {
        global $bpi_test_json_responses;
        $_POST['_wpnonce'] = 'valid';

        $this->manager->log( 'install', array( 'plugin_slug' => 'test', 'status' => 'success' ) );
        $this->manager->handleClearLog();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertStringContainsString( 'cleared', $bpi_test_json_responses[0]['data']['message'] );

        $entries = $this->manager->getEntries();
        $this->assertEmpty( $entries );
        unset( $_POST['_wpnonce'] );
    }
}
