<?php
/**
 * Unit tests for BPIAdminPage preview AJAX handler.
 *
 * @package BulkPluginInstaller
 */

use PHPUnit\Framework\TestCase;

/**
 * Class AdminPagePreviewTest
 *
 * Tests for the handlePreview() AJAX handler on BPIAdminPage.
 */
class AdminPagePreviewTest extends TestCase {

    private const VERSION_100 = '1.0.0';
    private const PLUGIN_NAME = 'My Plugin';
    private const AUTHOR_NAME = 'Test Author';

    private BPIAdminPage $adminPage;

    protected function setUp(): void {
        parent::setUp();

        global $bpi_test_hooks, $bpi_test_nonce_valid, $bpi_test_user_can;
        global $bpi_test_json_responses, $bpi_test_transients, $bpi_test_installed_plugins;
        global $bpi_test_localized_scripts;

        $bpi_test_hooks              = array();
        $bpi_test_nonce_valid        = true;
        $bpi_test_user_can           = true;
        $bpi_test_json_responses     = array();
        $bpi_test_transients         = array();
        $bpi_test_installed_plugins  = array();
        $bpi_test_localized_scripts  = array();

        $_POST = array();

        $this->adminPage = new BPIAdminPage();
    }

    protected function tearDown(): void {
        $_POST = array();
        parent::tearDown();
    }

    /**
     * Test that registerHooks registers the bpi_preview AJAX action.
     */
    public function test_register_hooks_adds_preview_ajax_action(): void {
        $this->adminPage->registerHooks();

        global $bpi_test_hooks;

        $preview_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'action' === $hook['type'] && 'wp_ajax_bpi_preview' === $hook['hook'];
        } );

        $this->assertNotEmpty( $preview_hooks, 'wp_ajax_bpi_preview action should be registered' );
    }

    /**
     * Test that handlePreview rejects invalid nonce.
     */
    public function test_handle_preview_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce'] = 'bad_nonce';

        $this->adminPage->handlePreview();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
        $this->assertStringContainsString( 'Security verification', $bpi_test_json_responses[0]['data']['message'] );
    }

    /**
     * Test that handlePreview rejects users without install_plugins capability.
     */
    public function test_handle_preview_rejects_without_capability(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
        $this->assertStringContainsString( 'permission', $bpi_test_json_responses[0]['data']['message'] );
    }

    /**
     * Test that handlePreview returns error when queue is empty.
     */
    public function test_handle_preview_returns_error_for_empty_queue(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 400, $bpi_test_json_responses[0]['status'] );
        $this->assertStringContainsString( 'No plugins', $bpi_test_json_responses[0]['data']['message'] );
    }

    /**
     * Test that handlePreview returns preview data for a new install plugin.
     */
    public function test_handle_preview_returns_new_install_data(): void {
        global $bpi_test_json_responses, $bpi_test_transients;

        // Seed the queue with a plugin.
        $bpi_test_transients['bpi_queue_1'] = array(
            'value' => array(
                array(
                    'slug'               => 'my-plugin',
                    'file_path'          => '/tmp/my-plugin.zip',
                    'file_name'          => 'my-plugin.zip',
                    'file_size'          => 12345,
                    'plugin_name'        => self::PLUGIN_NAME,
                    'plugin_version'     => self::VERSION_100,
                    'plugin_author'      => self::AUTHOR_NAME,
                    'plugin_description' => 'A test plugin.',
                    'requires_php'       => '',
                    'requires_wp'        => '',
                ),
            ),
            'expiration' => 3600,
        );

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertCount( 1, $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        $data = $bpi_test_json_responses[0]['data'];
        $this->assertArrayHasKey( 'plugins', $data );
        $this->assertCount( 1, $data['plugins'] );

        $plugin = $data['plugins'][0];
        $this->assertSame( 'my-plugin', $plugin['slug'] );
        $this->assertSame( self::PLUGIN_NAME, $plugin['plugin_name'] );
        $this->assertSame( self::VERSION_100, $plugin['plugin_version'] );
        $this->assertSame( self::AUTHOR_NAME, $plugin['plugin_author'] );
        $this->assertSame( 'install', $plugin['action'] );
        $this->assertSame( 'New Install', $plugin['action_label'] );
        $this->assertNull( $plugin['installed_version'] );
        $this->assertTrue( $plugin['compatible'] );
        $this->assertTrue( $plugin['checked'] );
    }

    /**
     * Test that handlePreview labels updates correctly with installed version.
     */
    public function test_handle_preview_labels_update_with_installed_version(): void {
        global $bpi_test_json_responses, $bpi_test_transients, $bpi_test_installed_plugins;

        // Simulate an installed plugin.
        $bpi_test_installed_plugins = array(
            'my-plugin/my-plugin.php' => array(
                'Name'    => self::PLUGIN_NAME,
                'Version' => self::VERSION_100,
            ),
        );

        // Seed the queue with an update.
        $bpi_test_transients['bpi_queue_1'] = array(
            'value' => array(
                array(
                    'slug'               => 'my-plugin',
                    'file_path'          => '/tmp/my-plugin.zip',
                    'file_name'          => 'my-plugin.zip',
                    'file_size'          => 12345,
                    'plugin_name'        => self::PLUGIN_NAME,
                    'plugin_version'     => '2.0.0',
                    'plugin_author'      => self::AUTHOR_NAME,
                    'plugin_description' => 'A test plugin.',
                    'requires_php'       => '',
                    'requires_wp'        => '',
                ),
            ),
            'expiration' => 3600,
        );

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        $plugin = $bpi_test_json_responses[0]['data']['plugins'][0];
        $this->assertSame( 'update', $plugin['action'] );
        $this->assertSame( 'Update', $plugin['action_label'] );
        $this->assertSame( self::VERSION_100, $plugin['installed_version'] );
        $this->assertSame( 'major', $plugin['update_type'] );
    }

    /**
     * Test that incompatible plugins are unchecked by default.
     */
    public function test_handle_preview_unchecks_incompatible_plugins(): void {
        global $bpi_test_json_responses, $bpi_test_transients;

        // Seed the queue with a plugin requiring a higher PHP version.
        $bpi_test_transients['bpi_queue_1'] = array(
            'value' => array(
                array(
                    'slug'               => 'strict-plugin',
                    'file_path'          => '/tmp/strict-plugin.zip',
                    'file_name'          => 'strict-plugin.zip',
                    'file_size'          => 5000,
                    'plugin_name'        => 'Strict Plugin',
                    'plugin_version'     => self::VERSION_100,
                    'plugin_author'      => 'Author',
                    'plugin_description' => 'Needs PHP 99.',
                    'requires_php'       => '99.0.0',
                    'requires_wp'        => '',
                ),
            ),
            'expiration' => 3600,
        );

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        $plugin = $bpi_test_json_responses[0]['data']['plugins'][0];
        $this->assertFalse( $plugin['compatible'] );
        $this->assertFalse( $plugin['checked'] );
        $this->assertNotEmpty( $plugin['compatibility_issues'] );
    }

    /**
     * Test that enqueueAssets includes previewNonce in localized data.
     */
    public function test_enqueue_assets_includes_preview_nonce(): void {
        global $bpi_test_localized_scripts;

        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_localized_scripts );
        $data = $bpi_test_localized_scripts['bpi-admin']['data'];
        $this->assertArrayHasKey( 'previewNonce', $data );
        $this->assertSame( 'nonce_bpi_preview', $data['previewNonce'] );
    }

    /**
     * Test that PREVIEW_NONCE_ACTION constant is defined.
     */
    public function test_preview_nonce_action_constant(): void {
        $this->assertSame( 'bpi_preview', BPIAdminPage::PREVIEW_NONCE_ACTION );
    }

    /**
     * Test that handlePreview returns multiple plugins with correct data.
     */
    public function test_handle_preview_returns_multiple_plugins(): void {
        global $bpi_test_json_responses, $bpi_test_transients, $bpi_test_installed_plugins;

        $bpi_test_installed_plugins = array(
            'existing-plugin/existing-plugin.php' => array(
                'Name'    => 'Existing Plugin',
                'Version' => '1.2.0',
            ),
        );

        $bpi_test_transients['bpi_queue_1'] = array(
            'value' => array(
                array(
                    'slug'               => 'new-plugin',
                    'file_path'          => '/tmp/new-plugin.zip',
                    'file_name'          => 'new-plugin.zip',
                    'file_size'          => 1000,
                    'plugin_name'        => 'New Plugin',
                    'plugin_version'     => self::VERSION_100,
                    'plugin_author'      => 'Author A',
                    'plugin_description' => 'Brand new.',
                    'requires_php'       => '',
                    'requires_wp'        => '',
                ),
                array(
                    'slug'               => 'existing-plugin',
                    'file_path'          => '/tmp/existing-plugin.zip',
                    'file_name'          => 'existing-plugin.zip',
                    'file_size'          => 2000,
                    'plugin_name'        => 'Existing Plugin',
                    'plugin_version'     => '1.3.0',
                    'plugin_author'      => 'Author B',
                    'plugin_description' => 'An update.',
                    'requires_php'       => '',
                    'requires_wp'        => '',
                ),
            ),
            'expiration' => 3600,
        );

        $_POST['_wpnonce'] = 'nonce_bpi_preview';

        $this->adminPage->handlePreview();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );

        $plugins = $bpi_test_json_responses[0]['data']['plugins'];
        $this->assertCount( 2, $plugins );

        // First is new install.
        $this->assertSame( 'install', $plugins[0]['action'] );
        $this->assertSame( 'New Install', $plugins[0]['action_label'] );

        // Second is update.
        $this->assertSame( 'update', $plugins[1]['action'] );
        $this->assertSame( 'Update', $plugins[1]['action_label'] );
        $this->assertSame( '1.2.0', $plugins[1]['installed_version'] );
        $this->assertSame( 'minor', $plugins[1]['update_type'] );
    }
}
