<?php
/**
 * Additional unit tests for BPIBulkUploader to cover handleUpload success path.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIBulkUploader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for bulk uploader coverage gaps.
 */
class BulkUploaderCoverageTest extends TestCase {

    private BPIBulkUploader $uploader;
    private string $tempDir;

    protected function setUp(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses,
               $bpi_test_options, $bpi_test_is_multisite, $bpi_test_is_network_admin;

        $bpi_test_nonce_valid     = true;
        $bpi_test_user_can        = true;
        $bpi_test_json_responses  = array();
        $bpi_test_options         = array( 'bpi_max_file_size' => 0 );
        $bpi_test_is_multisite    = false;
        $bpi_test_is_network_admin = false;

        $this->tempDir = sys_get_temp_dir() . '/bpi_upload_cov_' . uniqid();
        mkdir( $this->tempDir, 0755, true );

        $this->uploader = new BPIBulkUploader();

        unset( $_POST['_wpnonce'], $_FILES['plugin_zip'] );
    }

    protected function tearDown(): void {
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_is_multisite, $bpi_test_is_network_admin;
        $bpi_test_nonce_valid      = true;
        $bpi_test_user_can         = true;
        $bpi_test_is_multisite     = false;
        $bpi_test_is_network_admin = false;
        unset( $_POST['_wpnonce'], $_FILES['plugin_zip'] );
        $this->recursiveDelete( $this->tempDir );
    }

    public function test_handle_upload_success_returns_plugin_data(): void {
        global $bpi_test_json_responses;

        $zip_path = $this->createValidPluginZip( 'upload-test-plugin' );

        $_POST['_wpnonce'] = 'valid';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'upload-test-plugin.zip',
            'size'     => filesize( $zip_path ),
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 'upload-test-plugin', $bpi_test_json_responses[0]['data']['slug'] );
        $this->assertSame( 'upload-test-plugin.zip', $bpi_test_json_responses[0]['data']['file_name'] );
        $this->assertArrayHasKey( 'headers', $bpi_test_json_responses[0]['data'] );
    }

    public function test_handle_upload_rejects_invalid_zip(): void {
        global $bpi_test_json_responses;

        $bad_file = $this->tempDir . '/bad.zip';
        file_put_contents( $bad_file, 'not a zip' );

        $_POST['_wpnonce'] = 'valid';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $bad_file,
            'name'     => 'bad.zip',
            'size'     => filesize( $bad_file ),
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_handle_upload_rejects_no_file(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'valid';

        $this->uploader->handleUpload();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
    }

    public function test_handle_upload_multisite_capability_check(): void {
        global $bpi_test_json_responses, $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => false, 'install_plugins' => true );

        $_POST['_wpnonce'] = 'valid';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => '/tmp/test.zip',
            'name'     => 'test.zip',
            'size'     => 100,
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertFalse( $bpi_test_json_responses[0]['success'] );
        $this->assertSame( 403, $bpi_test_json_responses[0]['status'] );
    }

    public function test_handle_upload_multisite_success_with_network_cap(): void {
        global $bpi_test_json_responses, $bpi_test_is_multisite, $bpi_test_is_network_admin, $bpi_test_user_can;

        $bpi_test_is_multisite     = true;
        $bpi_test_is_network_admin = true;
        $bpi_test_user_can         = array( 'manage_network_plugins' => true, 'install_plugins' => true );

        $zip_path = $this->createValidPluginZip( 'ms-upload-plugin' );

        $_POST['_wpnonce'] = 'valid';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'ms-upload-plugin.zip',
            'size'     => filesize( $zip_path ),
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertTrue( $bpi_test_json_responses[0]['success'] );
    }

    private function createValidPluginZip( string $slug ): string {
        $zip_path = $this->tempDir . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE );
        $zip->addFromString(
            $slug . '/' . $slug . '.php',
            "<?php\n/**\n * Plugin Name: " . ucfirst( $slug ) . "\n * Version: 1.0.0\n * Author: Test\n * Description: Test plugin\n */"
        );
        $zip->close();
        return $zip_path;
    }

    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $path );
    }
}
