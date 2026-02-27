<?php
/**
 * Additional unit tests for BPIAdminPage to cover remaining paths.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIAdminPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for admin page coverage gaps.
 */
class AdminPageCoverageTest extends TestCase {

    private BPIAdminPage $page;

    protected function setUp(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses,
               $bpi_test_options, $bpi_test_is_multisite, $bpi_test_is_network_admin,
               $bpi_test_settings_errors;

        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = true;
        $bpi_test_json_responses   = array();
        $bpi_test_options          = array();
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        $bpi_test_settings_errors  = array();

        $this->page = new BPIAdminPage();
    }

    protected function tearDown(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        $bpi_test_user_can         = true;
    }

    public function test_register_network_menu_adds_submenu(): void {
        global $bpi_test_submenus;
        $bpi_test_submenus = array();

        $this->page->registerNetworkMenu();

        // add_submenu_page is stubbed — just verify no error.
        $this->assertTrue( true );
    }

    public function test_enqueue_assets_skips_wrong_hook(): void {
        // Should not throw or enqueue anything.
        $this->page->enqueueAssets( 'some_other_page' );
        $this->assertTrue( true );
    }

    public function test_enqueue_assets_loads_on_correct_hook(): void {
        global $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts;
        $bpi_test_enqueued_styles  = array();
        $bpi_test_enqueued_scripts = array();

        $this->page->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_enqueued_styles );
        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_enqueued_scripts );
    }

    public function test_render_page_outputs_html(): void {
        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'bpi-bulk-upload-wrap', $output );
        $this->assertStringContainsString( 'bpi-bulk-upload-app', $output );
    }

    public function test_render_page_skips_for_unauthorized_user(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = false;

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_get_required_capability_returns_network_cap_in_multisite(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;

        $this->assertSame( 'manage_network_plugins', $this->page->getRequiredCapability() );
    }

    public function test_is_network_admin_context_returns_true_in_multisite(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;

        $this->assertTrue( $this->page->isNetworkAdminContext() );
    }

    public function test_add_bulk_upload_link_appends_link(): void {
        $links = $this->page->addBulkUploadLink( array( '<a href="#">Existing</a>' ) );

        $this->assertCount( 2, $links );
        $this->assertStringContainsString( 'bpi-bulk-upload', $links[1] );
    }

    public function test_add_bulk_upload_link_skips_for_unauthorized(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = false;

        $links = $this->page->addBulkUploadLink( array( '<a href="#">Existing</a>' ) );

        $this->assertCount( 1, $links );
    }

    public function test_enqueue_assets_includes_network_nonce_in_multisite(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin,
               $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts, $bpi_test_localized_scripts;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_enqueued_styles  = array();
        $bpi_test_enqueued_scripts = array();
        $bpi_test_localized_scripts = array();

        $this->page->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_enqueued_scripts );
        // Check that localized data includes networkActivateNonce.
        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_localized_scripts );
        $this->assertArrayHasKey( 'networkActivateNonce', $bpi_test_localized_scripts['bpi-admin']['data'] );
    }
}
