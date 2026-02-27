<?php
/**
 * Property test for capability-gated access.
 *
 * Feature: bulk-plugin-installer, Property 3: Capability-gated access
 *
 * **Validates: Requirements 2.4, 7.4, 7.5, 17.4**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIBulkUploader;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class CapabilityGatedAccessTest extends TestCase {

    use TestTrait;

    private BPIBulkUploader $uploader;
    private string $tmpDir;

    protected function setUp(): void {
        $this->uploader = new BPIBulkUploader();
        $this->tmpDir   = sys_get_temp_dir() . '/bpi_pbt_cap_' . uniqid();
        mkdir( $this->tmpDir, 0777, true );

        // Reset global test state.
        global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses, $bpi_test_options;
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();
        $bpi_test_options        = array();
    }

    protected function tearDown(): void {
        // Clean up temp directory.
        if ( is_dir( $this->tmpDir ) ) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $it as $f ) {
                if ( $f->isDir() ) {

                    rmdir( $f->getRealPath() );

                } else {

                    unlink( $f->getRealPath() );

                }
            }
            rmdir( $this->tmpDir );
        }

        // Restore superglobals.
        $_POST  = array();
        $_FILES = array();

        // Reset capability stub to default.
        global $bpi_test_user_can;
        $bpi_test_user_can = true;
    }

    /**
     * Create a valid WordPress plugin ZIP file.
     *
     * @param string $zip_path Path for the ZIP file.
     * @param string $slug     Plugin slug.
     * @return bool True on success.
     */
    private function createPluginZip( string $zip_path, string $slug = 'test-plugin' ): bool {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $header = "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: 1.0.0\n * Author: Test\n * Description: A test plugin.\n */\n";
        $zip->addFromString( $slug . '/' . $slug . '.php', $header );
        $zip->close();
        return file_exists( $zip_path );
    }

    /**
     * Set up $_POST and $_FILES for handleUpload().
     *
     * @param string $file_path Path to the file.
     * @param int    $file_size Reported file size in bytes.
     * @param string $file_name File name.
     */
    private function setupUploadGlobals( string $file_path, int $file_size = 500, string $file_name = 'test.zip' ): void {
        $_POST['_wpnonce'] = 'test_nonce';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $file_path,
            'name'     => $file_name,
            'size'     => $file_size,
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'application/zip',
        );
    }

    /**
     * Property 3: Users without install_plugins capability are denied access.
     *
     * Generates random user IDs with install_plugins set to false,
     * then verifies handleUpload() denies access with a permission error.
     */
    public function test_users_without_install_plugins_are_denied(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 10000 ) // random user IDs
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $userId ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_user_can, $bpi_test_json_responses, $bpi_test_current_user_id;
                $bpi_test_json_responses = array();
                $bpi_test_current_user_id = $userId;

                // User lacks install_plugins capability.
                $bpi_test_user_can = false;

                $zip_path = $this->tmpDir . '/denied_' . $counter . '.zip';
                $this->createPluginZip( $zip_path );
                $this->setupUploadGlobals( $zip_path );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    'handleUpload() must produce a JSON response for user ' . $userId
                );

                $response = end( $bpi_test_json_responses );
                $this->assertFalse(
                    $response['success'],
                    'handleUpload() must deny access when user lacks install_plugins capability.'
                );

                $message = strtolower( $response['data']['message'] ?? '' );
                $this->assertStringContainsString(
                    'permission',
                    $message,
                    'Error message must mention "permission" when access is denied.'
                );
            } );
    }

    /**
     * Property 3: Users with install_plugins capability are allowed access.
     *
     * Generates random user IDs with install_plugins set to true,
     * then verifies handleUpload() proceeds (may succeed or fail for
     * other reasons, but NOT for permission).
     */
    public function test_users_with_install_plugins_proceed(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 10000 ) // random user IDs
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $userId ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_user_can, $bpi_test_json_responses, $bpi_test_current_user_id;
                $bpi_test_json_responses = array();
                $bpi_test_current_user_id = $userId;

                // User has install_plugins capability.
                $bpi_test_user_can = true;

                $zip_path = $this->tmpDir . '/allowed_' . $counter . '.zip';
                $this->createPluginZip( $zip_path );
                $this->setupUploadGlobals( $zip_path );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    'handleUpload() must produce a JSON response for user ' . $userId
                );

                $response = end( $bpi_test_json_responses );

                // If there's an error, it must NOT be a permission error.
                if ( ! $response['success'] ) {
                    $message = strtolower( $response['data']['message'] ?? '' );
                    $this->assertStringNotContainsString(
                        'permission',
                        $message,
                        'Users with install_plugins must not be denied for permission reasons.'
                    );
                }
            } );
    }

    /**
     * Property 3: Random capability states consistently gate access.
     *
     * Generates random user IDs with random boolean capability states
     * and verifies the access decision matches the capability value.
     */
    public function test_random_capability_states_gate_access(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 10000 ),  // random user IDs
                Generator\elements( true, false ) // random capability state
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $userId, bool $hasCapability ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_user_can, $bpi_test_json_responses, $bpi_test_current_user_id;
                $bpi_test_json_responses = array();
                $bpi_test_current_user_id = $userId;

                $bpi_test_user_can = $hasCapability;

                $zip_path = $this->tmpDir . '/random_' . $counter . '.zip';
                $this->createPluginZip( $zip_path );
                $this->setupUploadGlobals( $zip_path );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    'handleUpload() must produce a JSON response.'
                );

                $response = end( $bpi_test_json_responses );
                $message  = strtolower( $response['data']['message'] ?? '' );

                if ( ! $hasCapability ) {
                    // Without capability: must be denied with permission error.
                    $this->assertFalse(
                        $response['success'],
                        "User {$userId} without capability must be denied access."
                    );
                    $this->assertStringContainsString(
                        'permission',
                        $message,
                        "Denial message for user {$userId} must mention 'permission'."
                    );
                } else {
                    // With capability: must not be denied for permission reasons.
                    if ( ! $response['success'] ) {
                        $this->assertStringNotContainsString(
                            'permission',
                            $message,
                            "User {$userId} with capability must not be denied for permission."
                        );
                    }
                }
            } );
    }
}
