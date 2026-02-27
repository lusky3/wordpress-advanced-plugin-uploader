<?php
/**
 * Unit tests for the BPIProfileManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIProfileManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for profile CRUD, export/import, AJAX handlers, and rendering.
 */
class ProfileManagerTest extends TestCase {

    /**
     * @var BPIProfileManager
     */
    private BPIProfileManager $manager;

    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_hooks, $bpi_test_nonce_valid,
            $bpi_test_user_can, $bpi_test_json_responses;

        $bpi_test_options        = array();
        $bpi_test_hooks          = array();
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();

        $this->manager = new BPIProfileManager();
    }

    protected function tearDown(): void {
        global $bpi_test_options, $bpi_test_hooks, $bpi_test_json_responses;
        $bpi_test_options        = array();
        $bpi_test_hooks          = array();
        $bpi_test_json_responses = array();
        $_POST                   = array();
        $_REQUEST                = array();
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    /**
     * Build a sample plugins array.
     */
    private function samplePlugins( int $count = 2 ): array {
        $plugins = array();
        for ( $i = 1; $i <= $count; $i++ ) {
            $plugins[] = array(
                'slug'    => "plugin-{$i}",
                'name'    => "Plugin {$i}",
                'version' => "1.{$i}.0",
            );
        }
        return $plugins;
    }

    // ---------------------------------------------------------------
    // saveProfile() tests
    // ---------------------------------------------------------------

    public function test_save_profile_returns_id(): void {
        $id = $this->manager->saveProfile( 'My Profile', $this->samplePlugins() );
        $this->assertSame( 1, $id );
    }

    public function test_save_profile_auto_increments_id(): void {
        $id1 = $this->manager->saveProfile( 'First', $this->samplePlugins() );
        $id2 = $this->manager->saveProfile( 'Second', $this->samplePlugins() );
        $this->assertSame( 1, $id1 );
        $this->assertSame( 2, $id2 );
    }

    public function test_save_profile_stores_name_and_plugins(): void {
        $plugins = $this->samplePlugins( 3 );
        $id      = $this->manager->saveProfile( 'Test Profile', $plugins );
        $profile = $this->manager->getProfile( $id );

        $this->assertSame( 'Test Profile', $profile['name'] );
        $this->assertCount( 3, $profile['plugins'] );
        $this->assertSame( 'plugin-1', $profile['plugins'][0]['slug'] );
    }

    public function test_save_profile_sets_created_at(): void {
        $id      = $this->manager->saveProfile( 'Dated', $this->samplePlugins() );
        $profile = $this->manager->getProfile( $id );

        $this->assertArrayHasKey( 'created_at', $profile );
        $this->assertNotEmpty( $profile['created_at'] );
        // Should be a valid ISO 8601 date.
        $this->assertNotFalse( strtotime( $profile['created_at'] ) );
    }

    public function test_save_profile_with_empty_plugins(): void {
        $id      = $this->manager->saveProfile( 'Empty', array() );
        $profile = $this->manager->getProfile( $id );

        $this->assertSame( 'Empty', $profile['name'] );
        $this->assertCount( 0, $profile['plugins'] );
    }

    public function test_save_profile_id_increments_after_deletion(): void {
        $id1 = $this->manager->saveProfile( 'First', $this->samplePlugins() );
        $id2 = $this->manager->saveProfile( 'Second', $this->samplePlugins() );
        $id3 = $this->manager->saveProfile( 'Third', $this->samplePlugins() );
        $this->manager->deleteProfile( $id2 );
        $id4 = $this->manager->saveProfile( 'Fourth', $this->samplePlugins() );

        // Max remaining ID is 3, so next should be 4.
        $this->assertSame( 4, $id4 );
    }

    // ---------------------------------------------------------------
    // getProfile() tests
    // ---------------------------------------------------------------

    public function test_get_profile_returns_null_for_nonexistent(): void {
        $this->assertNull( $this->manager->getProfile( 999 ) );
    }

    public function test_get_profile_returns_correct_profile(): void {
        $this->manager->saveProfile( 'A', $this->samplePlugins( 1 ) );
        $this->manager->saveProfile( 'B', $this->samplePlugins( 2 ) );

        $profile = $this->manager->getProfile( 2 );
        $this->assertSame( 'B', $profile['name'] );
        $this->assertCount( 2, $profile['plugins'] );
    }

    // ---------------------------------------------------------------
    // getAllProfiles() tests
    // ---------------------------------------------------------------

    public function test_get_all_profiles_returns_empty_array_initially(): void {
        $this->assertSame( array(), $this->manager->getAllProfiles() );
    }

    public function test_get_all_profiles_returns_all_saved(): void {
        $this->manager->saveProfile( 'A', $this->samplePlugins() );
        $this->manager->saveProfile( 'B', $this->samplePlugins() );
        $this->manager->saveProfile( 'C', $this->samplePlugins() );

        $all = $this->manager->getAllProfiles();
        $this->assertCount( 3, $all );
    }

    public function test_get_all_profiles_handles_corrupt_option(): void {
        global $bpi_test_options;
        $bpi_test_options[ BPIProfileManager::OPTION_KEY ] = 'not-an-array';

        $this->assertSame( array(), $this->manager->getAllProfiles() );
    }

    // ---------------------------------------------------------------
    // deleteProfile() tests
    // ---------------------------------------------------------------

    public function test_delete_profile_removes_profile(): void {
        $id = $this->manager->saveProfile( 'ToDelete', $this->samplePlugins() );
        $this->assertTrue( $this->manager->deleteProfile( $id ) );
        $this->assertNull( $this->manager->getProfile( $id ) );
    }

    public function test_delete_profile_returns_false_for_nonexistent(): void {
        $this->assertFalse( $this->manager->deleteProfile( 999 ) );
    }

    public function test_delete_profile_preserves_other_profiles(): void {
        $id1 = $this->manager->saveProfile( 'Keep', $this->samplePlugins() );
        $id2 = $this->manager->saveProfile( 'Remove', $this->samplePlugins() );
        $id3 = $this->manager->saveProfile( 'AlsoKeep', $this->samplePlugins() );

        $this->manager->deleteProfile( $id2 );

        $this->assertNotNull( $this->manager->getProfile( $id1 ) );
        $this->assertNull( $this->manager->getProfile( $id2 ) );
        $this->assertNotNull( $this->manager->getProfile( $id3 ) );
        $this->assertCount( 2, $this->manager->getAllProfiles() );
    }

    public function test_delete_profile_reindexes_array(): void {
        $this->manager->saveProfile( 'A', $this->samplePlugins() );
        $id2 = $this->manager->saveProfile( 'B', $this->samplePlugins() );
        $this->manager->saveProfile( 'C', $this->samplePlugins() );

        $this->manager->deleteProfile( $id2 );

        $all = $this->manager->getAllProfiles();
        // Should be numerically indexed 0, 1.
        $this->assertArrayHasKey( 0, $all );
        $this->assertArrayHasKey( 1, $all );
        $this->assertArrayNotHasKey( 2, $all );
    }

    // ---------------------------------------------------------------
    // exportProfile() tests
    // ---------------------------------------------------------------

    public function test_export_profile_returns_json(): void {
        $plugins = $this->samplePlugins();
        $id      = $this->manager->saveProfile( 'Export Me', $plugins );
        $json    = $this->manager->exportProfile( $id );

        $this->assertNotEmpty( $json );
        $decoded = json_decode( $json, true );
        $this->assertSame( 'Export Me', $decoded['name'] );
        $this->assertCount( 2, $decoded['plugins'] );
        $this->assertSame( $id, $decoded['id'] );
    }

    public function test_export_profile_returns_empty_for_nonexistent(): void {
        $this->assertSame( '', $this->manager->exportProfile( 999 ) );
    }

    // ---------------------------------------------------------------
    // importProfile() tests
    // ---------------------------------------------------------------

    public function test_import_profile_creates_new_profile(): void {
        $json = json_encode( array(
            'name'    => 'Imported',
            'plugins' => $this->samplePlugins(),
        ) );

        $id = $this->manager->importProfile( $json );
        $this->assertIsInt( $id );
        $this->assertSame( 1, $id );

        $profile = $this->manager->getProfile( $id );
        $this->assertSame( 'Imported', $profile['name'] );
    }

    public function test_import_profile_rejects_invalid_json(): void {
        $result = $this->manager->importProfile( 'not json at all' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_json', $result->get_error_code() );
    }

    public function test_import_profile_rejects_missing_name(): void {
        $json   = json_encode( array( 'plugins' => array() ) );
        $result = $this->manager->importProfile( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_profile', $result->get_error_code() );
    }

    public function test_import_profile_rejects_empty_name(): void {
        $json   = json_encode( array( 'name' => '', 'plugins' => array() ) );
        $result = $this->manager->importProfile( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_profile', $result->get_error_code() );
    }

    public function test_import_profile_rejects_missing_plugins(): void {
        $json   = json_encode( array( 'name' => 'No Plugins' ) );
        $result = $this->manager->importProfile( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_profile', $result->get_error_code() );
    }

    public function test_import_profile_rejects_non_array_plugins(): void {
        $json   = json_encode( array( 'name' => 'Bad', 'plugins' => 'not-array' ) );
        $result = $this->manager->importProfile( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_profile', $result->get_error_code() );
    }

    public function test_import_profile_assigns_new_id(): void {
        // Import a profile that has an existing ID — it should get a new one.
        $json = json_encode( array(
            'id'      => 42,
            'name'    => 'With Old ID',
            'plugins' => $this->samplePlugins(),
        ) );

        $id = $this->manager->importProfile( $json );
        $this->assertSame( 1, $id );
    }

    public function test_export_import_round_trip(): void {
        $plugins = $this->samplePlugins( 3 );
        $id1     = $this->manager->saveProfile( 'Round Trip', $plugins );
        $json    = $this->manager->exportProfile( $id1 );

        $id2     = $this->manager->importProfile( $json );
        $this->assertIsInt( $id2 );

        $original = $this->manager->getProfile( $id1 );
        $imported = $this->manager->getProfile( $id2 );

        $this->assertSame( $original['name'], $imported['name'] );
        $this->assertSame( $original['plugins'], $imported['plugins'] );
    }

    // ---------------------------------------------------------------
    // registerAjaxHandlers() tests
    // ---------------------------------------------------------------

    public function test_register_ajax_handlers_registers_three_actions(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $this->manager->registerAjaxHandlers();

        $hooks = array_column(
            array_filter( $bpi_test_hooks, fn( $h ) => $h['type'] === 'action' ),
            'hook'
        );

        $this->assertContains( 'wp_ajax_bpi_save_profile', $hooks );
        $this->assertContains( 'wp_ajax_bpi_import_profile', $hooks );
        $this->assertContains( 'wp_ajax_bpi_export_profile', $hooks );
    }

    // ---------------------------------------------------------------
    // AJAX handler: save profile
    // ---------------------------------------------------------------

    public function test_ajax_save_profile_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce'] = 'bad';
        $_POST['name']     = 'Test';
        $_POST['plugins']  = array();

        $this->manager->handleAjaxSaveProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_save_profile_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'Test';
        $_POST['plugins']  = array();

        $this->manager->handleAjaxSaveProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_save_profile_rejects_empty_name(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = '';
        $_POST['plugins']  = array();

        $this->manager->handleAjaxSaveProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_ajax_save_profile_success(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'AJAX Profile';
        $_POST['plugins']  = $this->samplePlugins();

        $this->manager->handleAjaxSaveProfile();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 1, $bpi_test_json_responses[0]['data']['id'] );
    }

    public function test_ajax_save_profile_rejects_non_array_plugins(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';
        $_POST['name']     = 'Bad Plugins';
        $_POST['plugins']  = 'not-array';

        $this->manager->handleAjaxSaveProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    // ---------------------------------------------------------------
    // AJAX handler: import profile
    // ---------------------------------------------------------------

    public function test_ajax_import_profile_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce']     = 'bad';
        $_POST['profile_json'] = '{}';

        $this->manager->handleAjaxImportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_import_profile_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce']     = 'valid';
        $_POST['profile_json'] = '{}';

        $this->manager->handleAjaxImportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_import_profile_rejects_empty_json(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']     = 'valid';
        $_POST['profile_json'] = '';

        $this->manager->handleAjaxImportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_ajax_import_profile_rejects_invalid_json(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce']     = 'valid';
        $_POST['profile_json'] = 'not json';

        $this->manager->handleAjaxImportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_ajax_import_profile_success(): void {
        global $bpi_test_json_responses;

        $json = json_encode( array(
            'name'    => 'Imported Via AJAX',
            'plugins' => $this->samplePlugins(),
        ) );

        $_POST['_wpnonce']     = 'valid';
        $_POST['profile_json'] = $json;

        $this->manager->handleAjaxImportProfile();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 1, $bpi_test_json_responses[0]['data']['id'] );
    }

    // ---------------------------------------------------------------
    // AJAX handler: export profile
    // ---------------------------------------------------------------

    public function test_ajax_export_profile_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_REQUEST['_wpnonce']   = 'bad';
        $_REQUEST['profile_id'] = 1;

        $this->manager->handleAjaxExportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_export_profile_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_REQUEST['_wpnonce']   = 'valid';
        $_REQUEST['profile_id'] = 1;

        $this->manager->handleAjaxExportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_ajax_export_profile_rejects_missing_id(): void {
        global $bpi_test_json_responses;

        $_REQUEST['_wpnonce'] = 'valid';

        $this->manager->handleAjaxExportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_ajax_export_profile_returns_404_for_nonexistent(): void {
        global $bpi_test_json_responses;

        $_REQUEST['_wpnonce']   = 'valid';
        $_REQUEST['profile_id'] = 999;

        $this->manager->handleAjaxExportProfile();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_ajax_export_profile_success(): void {
        global $bpi_test_json_responses;

        $id = $this->manager->saveProfile( 'Export AJAX', $this->samplePlugins() );

        $_REQUEST['_wpnonce']   = 'valid';
        $_REQUEST['profile_id'] = $id;

        $this->manager->handleAjaxExportProfile();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $decoded = json_decode( $bpi_test_json_responses[0]['data']['json'], true );
        $this->assertSame( 'Export AJAX', $decoded['name'] );
    }

    // ---------------------------------------------------------------
    // renderProfilesList() tests
    // ---------------------------------------------------------------

    public function test_render_profiles_list_shows_empty_message(): void {
        ob_start();
        $this->manager->renderProfilesList();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'No profiles saved yet.', $html );
    }

    public function test_render_profiles_list_shows_profiles_table(): void {
        $this->manager->saveProfile( 'Site Setup', $this->samplePlugins( 3 ) );
        $this->manager->saveProfile( 'Dev Tools', $this->samplePlugins( 1 ) );

        ob_start();
        $this->manager->renderProfilesList();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Site Setup', $html );
        $this->assertStringContainsString( 'Dev Tools', $html );
        $this->assertStringContainsString( '3', $html ); // plugin count
        $this->assertStringContainsString( '1', $html ); // plugin count
        $this->assertStringContainsString( 'Delete', $html );
        $this->assertStringContainsString( 'bpi-delete-profile', $html );
    }

    public function test_render_profiles_list_has_aria_labels(): void {
        $this->manager->saveProfile( 'Accessible', $this->samplePlugins() );

        ob_start();
        $this->manager->renderProfilesList();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'aria-label', $html );
        $this->assertStringContainsString( 'Delete profile: Accessible', $html );
    }
}
