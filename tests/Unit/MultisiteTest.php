<?php
/**
 * Unit tests for WordPress Multisite Network support.
 *
 * Tests multisite-aware logic in bootstrap, admin page, bulk uploader,
 * and plugin processor components.
 *
 * @package BulkPluginInstaller
 */

use PHPUnit\Framework\TestCase;

/**
 * Class MultisiteTest
 *
 * Verifies multisite detection, network admin menu registration,
 * capability checks, preview network_activate field, and single-site
 * behavior within multisite.
 */
class MultisiteTest extends TestCase {

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        global $bpi_test_hooks, $bpi_test_submenu_pages, $bpi_test_user_can;
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts, $bpi_test_localized_scripts;
        global $bpi_test_nonce_valid, $bpi_test_json_responses, $bpi_test_options, $bpi_test_transients;

        $bpi_test_hooks              = array();
        $bpi_test_submenu_pages      = array();
        $bpi_test_user_can           = true;
        $bpi_test_is_multisite       = false;
        $bpi_test_is_network_admin   = false;
        $bpi_test_enqueued_styles    = array();
        $bpi_test_enqueued_scripts   = array();
        $bpi_test_localized_scripts  = array();
        $bpi_test_nonce_valid        = true;
        $bpi_test_json_responses     = array();
        $bpi_test_options            = array();
        $bpi_test_transients         = array();
    }

    /**
     * Tear down test fixtures.
     */
    protected function tearDown(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        parent::tearDown();
    }

    // ── Bootstrap Tests ──────────────────────────────────────

    /**
     * Test that init registers network_admin_menu hook when multisite is active.
     */
    public function test_bootstrap_registers_network_admin_menu_on_multisite(): void {
        global $bpi_test_hooks, $bpi_test_is_multisite;
        $bpi_test_is_multisite = true;
        $bpi_test_hooks        = array();

        $plugin = BulkPluginInstaller::getInstance();
        // Use reflection to reset singleton for fresh init.
        $ref = new \ReflectionClass( $plugin );
        $method = $ref->getMethod( 'init' );
        $method->invoke( $plugin );

        $network_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'action' === $hook['type'] && 'network_admin_menu' === $hook['hook'];
        } );

        $this->assertNotEmpty( $network_hooks, 'network_admin_menu action should be registered on multisite' );
    }

    /**
     * Test that init does NOT register network_admin_menu when not multisite.
     */
    public function test_bootstrap_no_network_admin_menu_on_single_site(): void {
        global $bpi_test_hooks, $bpi_test_is_multisite;
        $bpi_test_is_multisite = false;
        $bpi_test_hooks        = array();

        $plugin = BulkPluginInstaller::getInstance();
        $ref = new \ReflectionClass( $plugin );
        $method = $ref->getMethod( 'init' );
        $method->invoke( $plugin );

        $network_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'action' === $hook['type'] && 'network_admin_menu' === $hook['hook'];
        } );

        $this->assertEmpty( $network_hooks, 'network_admin_menu should NOT be registered on single site' );
    }

    // ── Admin Page: Network Menu Registration ────────────────

    /**
     * Test that registerNetworkMenu adds submenu with manage_network_plugins capability.
     */
    public function test_register_network_menu_uses_manage_network_plugins(): void {
        global $bpi_test_submenu_pages;

        $adminPage = new BPIAdminPage();
        $adminPage->registerNetworkMenu();

        $this->assertArrayHasKey( 'bpi-bulk-upload', $bpi_test_submenu_pages );
        $page = $bpi_test_submenu_pages['bpi-bulk-upload'];
        $this->assertSame( 'manage_network_plugins', $page['capability'] );
        $this->assertSame( 'plugins.php', $page['parent_slug'] );
    }

    /**
     * Test that registerMenu (single site) uses install_plugins capability.
     */
    public function test_register_menu_uses_install_plugins(): void {
        global $bpi_test_submenu_pages;

        $adminPage = new BPIAdminPage();
        $adminPage->registerMenu();

        $page = $bpi_test_submenu_pages['bpi-bulk-upload'];
        $this->assertSame( 'install_plugins', $page['capability'] );
    }

    // ── Admin Page: Capability Detection ─────────────────────

    /**
     * Test getRequiredCapability returns manage_network_plugins in network admin.
     */
    public function test_get_required_capability_network_admin(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;

        $adminPage = new BPIAdminPage();
        $this->assertSame( 'manage_network_plugins', $adminPage->getRequiredCapability() );
    }

    /**
     * Test getRequiredCapability returns install_plugins on single site.
     */
    public function test_get_required_capability_single_site(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;

        $adminPage = new BPIAdminPage();
        $this->assertSame( 'install_plugins', $adminPage->getRequiredCapability() );
    }

    /**
     * Test getRequiredCapability returns install_plugins on single site within multisite.
     */
    public function test_get_required_capability_single_site_within_multisite(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = false;

        $adminPage = new BPIAdminPage();
        $this->assertSame( 'install_plugins', $adminPage->getRequiredCapability() );
    }

    /**
     * Test isNetworkAdminContext returns true in network admin.
     */
    public function test_is_network_admin_context_true(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;

        $adminPage = new BPIAdminPage();
        $this->assertTrue( $adminPage->isNetworkAdminContext() );
    }

    /**
     * Test isNetworkAdminContext returns false on single site.
     */
    public function test_is_network_admin_context_false_single_site(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;

        $adminPage = new BPIAdminPage();
        $this->assertFalse( $adminPage->isNetworkAdminContext() );
    }

    // ── Admin Page: renderPage Capability Check ─────────────

    /**
     * Test renderPage outputs content when user has manage_network_plugins in network admin.
     */
    public function test_render_page_network_admin_with_capability(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => true );

        $adminPage = new BPIAdminPage();
        ob_start();
        $adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'bpi-bulk-upload-wrap', $output );
    }

    /**
     * Test renderPage outputs nothing when user lacks manage_network_plugins in network admin.
     */
    public function test_render_page_network_admin_without_capability(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => false );

        $adminPage = new BPIAdminPage();
        ob_start();
        $adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    // ── Admin Page: enqueueAssets Multisite Data ────────────

    /**
     * Test enqueueAssets includes isNetworkAdmin=true in network admin context.
     */
    public function test_enqueue_assets_includes_is_network_admin_true(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_localized_scripts;
        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;

        $adminPage = new BPIAdminPage();
        $adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        $data = $bpi_test_localized_scripts['bpi-admin']['data'];
        $this->assertTrue( $data['isNetworkAdmin'] );
        $this->assertArrayHasKey( 'networkActivateNonce', $data );
    }

    /**
     * Test enqueueAssets includes isNetworkAdmin=false on single site.
     */
    public function test_enqueue_assets_includes_is_network_admin_false(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_localized_scripts;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;

        $adminPage = new BPIAdminPage();
        $adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        $data = $bpi_test_localized_scripts['bpi-admin']['data'];
        $this->assertFalse( $data['isNetworkAdmin'] );
        $this->assertArrayNotHasKey( 'networkActivateNonce', $data );
    }

    // ── Preview: network_activate Field ──────────────────────

    /**
     * Test handlePreview includes network_activate=true in network admin context.
     */
    public function test_preview_includes_network_activate_in_network_admin(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        global $bpi_test_transients;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => true );
        $bpi_test_json_responses   = array();

        // Set up a queue with one plugin.
        $user_id = get_current_user_id();
        $bpi_test_transients[ 'bpi_queue_' . $user_id ] = array(
            'value' => array(
                array(
                    'slug'           => 'test-plugin',
                    'plugin_name'    => 'Test Plugin',
                    'plugin_version' => '1.0.0',
                    'plugin_author'  => 'Test Author',
                    'plugin_description' => 'A test plugin.',
                    'file_path'      => '',
                    'file_size'      => 1024,
                ),
            ),
            'expiration' => 0,
        );

        $_POST['_wpnonce'] = 'valid_nonce';

        $adminPage = new BPIAdminPage();
        $adminPage->handlePreview();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $response = end( $bpi_test_json_responses );
        $this->assertTrue( $response['success'] );

        $plugins = $response['data']['plugins'];
        $this->assertCount( 1, $plugins );
        $this->assertTrue( $plugins[0]['network_activate'] );
    }

    /**
     * Test handlePreview includes network_activate=false on single site.
     */
    public function test_preview_includes_network_activate_false_on_single_site(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        global $bpi_test_transients;

        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = true;
        $bpi_test_json_responses   = array();

        $user_id = get_current_user_id();
        $bpi_test_transients[ 'bpi_queue_' . $user_id ] = array(
            'value' => array(
                array(
                    'slug'           => 'test-plugin',
                    'plugin_name'    => 'Test Plugin',
                    'plugin_version' => '1.0.0',
                    'plugin_author'  => 'Test Author',
                    'plugin_description' => 'A test plugin.',
                    'file_path'      => '',
                    'file_size'      => 1024,
                ),
            ),
            'expiration' => 0,
        );

        $_POST['_wpnonce'] = 'valid_nonce';

        $adminPage = new BPIAdminPage();
        $adminPage->handlePreview();

        $response = end( $bpi_test_json_responses );
        $this->assertTrue( $response['success'] );

        $plugins = $response['data']['plugins'];
        $this->assertFalse( $plugins[0]['network_activate'] );
    }

    /**
     * Test handlePreview denies access when user lacks manage_network_plugins in network admin.
     */
    public function test_preview_denies_without_manage_network_plugins(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => false );
        $bpi_test_json_responses   = array();

        $_POST['_wpnonce'] = 'valid_nonce';

        $adminPage = new BPIAdminPage();
        $adminPage->handlePreview();

        $response = end( $bpi_test_json_responses );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'permission', $response['data']['message'] );
    }

    // ── Bulk Uploader: Capability Check ──────────────────────

    /**
     * Test bulk uploader checks manage_network_plugins in network admin context.
     */
    public function test_bulk_uploader_checks_network_capability(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = array(
            'install_plugins'        => true,
            'manage_network_plugins' => false,
        );
        $bpi_test_json_responses   = array();

        $_POST['_wpnonce'] = 'valid_nonce';

        $uploader = new BPIBulkUploader();
        $uploader->handleUpload();

        $response = end( $bpi_test_json_responses );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'permission', $response['data']['message'] );
    }

    /**
     * Test bulk uploader checks install_plugins on single site within multisite.
     */
    public function test_bulk_uploader_checks_install_plugins_on_single_site(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin;
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = false;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = array(
            'install_plugins'        => false,
            'manage_network_plugins' => true,
        );
        $bpi_test_json_responses   = array();

        $_POST['_wpnonce'] = 'valid_nonce';

        $uploader = new BPIBulkUploader();
        $uploader->handleUpload();

        $response = end( $bpi_test_json_responses );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'permission', $response['data']['message'] );
    }

    // ── Plugin Processor: Network Activation ─────────────────

    /**
     * Test plugin processor passes network_wide to activatePlugin when network_activate is set.
     */
    public function test_processor_network_activate_passes_network_wide(): void {
        global $bpi_test_active_plugins;
        $bpi_test_active_plugins = array();

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        // Create a testable subclass that tracks network_wide calls.
        $processor = new class( $rollback, $logger, $settings ) extends BPIPluginProcessor {
            public bool $last_network_wide = false;

            protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
                return true;
            }

            protected function isPluginActive( string $plugin_file ): bool {
                return false;
            }

            protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
                $this->last_network_wide = $network_wide;
                return null;
            }
        };

        $result = $processor->processPlugin( array(
            'slug'             => 'test-plugin',
            'action'           => 'install',
            'plugin_name'      => 'Test Plugin',
            'file_path'        => '/tmp/test.zip',
            'plugin_file'      => 'test-plugin/test-plugin.php',
            'plugin_version'   => '1.0.0',
            'installed_version' => '',
            'activate'         => true,
            'network_activate' => true,
        ) );

        $this->assertSame( 'success', $result['status'] );
        $this->assertTrue( $processor->last_network_wide );
    }

    /**
     * Test plugin processor does NOT pass network_wide when network_activate is not set.
     */
    public function test_processor_no_network_activate_passes_false(): void {
        global $bpi_test_active_plugins;
        $bpi_test_active_plugins = array();

        $rollback = new BPIRollbackManager();
        $logger   = new BPILogManager();
        $settings = new BPISettingsManager();

        $processor = new class( $rollback, $logger, $settings ) extends BPIPluginProcessor {
            public bool $last_network_wide = true; // Start true to verify it gets set to false.

            protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
                return true;
            }

            protected function isPluginActive( string $plugin_file ): bool {
                return false;
            }

            protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
                $this->last_network_wide = $network_wide;
                return null;
            }
        };

        $result = $processor->processPlugin( array(
            'slug'             => 'test-plugin',
            'action'           => 'install',
            'plugin_name'      => 'Test Plugin',
            'file_path'        => '/tmp/test.zip',
            'plugin_file'      => 'test-plugin/test-plugin.php',
            'plugin_version'   => '1.0.0',
            'installed_version' => '',
            'activate'         => true,
        ) );

        $this->assertSame( 'success', $result['status'] );
        $this->assertFalse( $processor->last_network_wide );
    }

    // ── Single Site Within Multisite ─────────────────────────

    /**
     * Test that on a single site within multisite, behavior is identical to standard WordPress.
     */
    public function test_single_site_within_multisite_uses_install_plugins(): void {
        global $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = false;
        $bpi_test_user_can         = array( 'install_plugins' => true );

        $adminPage = new BPIAdminPage();
        $this->assertSame( 'install_plugins', $adminPage->getRequiredCapability() );
        $this->assertFalse( $adminPage->isNetworkAdminContext() );
    }
}
