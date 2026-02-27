<?php
/**
 * Property test for file size limit enforcement.
 *
 * Feature: bulk-plugin-installer, Property 13: File size limit enforcement
 *
 * **Validates: Requirements 8.1**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIBulkUploader;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class FileSizeLimitTest extends TestCase {

    use TestTrait;

    private const UPLOAD_RESPONSE_MSG = 'handleUpload() must produce a JSON response.';

    private BPIBulkUploader $uploader;
    private string $tmpDir;

    protected function setUp(): void {
        $this->uploader = new BPIBulkUploader();
        $this->tmpDir   = sys_get_temp_dir() . '/bpi_pbt_fs_' . uniqid();
        mkdir( $this->tmpDir, 0777, true );

        // Reset global test state for AJAX stubs.
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
    }

    /**
     * Create a valid WordPress plugin ZIP file of approximately the given size.
     *
     * @param string $zip_path    Path for the ZIP file.
     * @param int    $target_size Approximate target file size in bytes.
     * @param string $slug        Plugin slug.
     * @return bool True on success.
     */
    private function createPluginZip( string $zip_path, int $target_size, string $slug = 'test-plugin' ): bool {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $header = "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: 1.0.0\n * Author: Test\n * Description: A test plugin.\n */\n";
        $zip->addFromString( $slug . '/' . $slug . '.php', $header );

        // Add padding to reach target size. ZIP overhead is ~200 bytes,
        // so we pad the content to get close to the desired file size.
        $current_overhead = 250;
        if ( $target_size > $current_overhead ) {
            $padding_size = $target_size - $current_overhead;
            // Use incompressible random-ish data to keep ZIP size close to target.
            $padding = str_repeat( 'X', $padding_size );
            $zip->addFromString( $slug . '/padding.dat', $padding );
        }

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
    private function setupUploadGlobals( string $file_path, int $file_size, string $file_name = 'test.zip' ): void {
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
     * Property 13: Files exceeding the configured max size are rejected.
     *
     * Generates random file sizes (1-100 MB) and random limits (1-50 MB)
     * where the file size exceeds the limit, then verifies handleUpload()
     * rejects with an error mentioning the size limit.
     */
    public function test_files_exceeding_limit_are_rejected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 50 ),   // max_size_mb limit
                Generator\choose( 1, 50 )     // extra MB above limit
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $limit_mb, int $extra_mb ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_json_responses, $bpi_test_options;
                $bpi_test_json_responses = array();

                // Set the file size limit.
                $bpi_test_options['bpi_max_file_size'] = $limit_mb;

                $file_size_bytes = ( $limit_mb + $extra_mb ) * 1024 * 1024 + 1;

                // Create a small valid ZIP but report a large size via $_FILES.
                $zip_path = $this->tmpDir . '/oversized_' . $counter . '.zip';
                $this->createPluginZip( $zip_path, 500, 'oversized-plugin' );

                $this->setupUploadGlobals( $zip_path, $file_size_bytes, 'oversized-plugin.zip' );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    self::UPLOAD_RESPONSE_MSG
                );

                $response = end( $bpi_test_json_responses );
                $this->assertFalse(
                    $response['success'],
                    "File of {$file_size_bytes} bytes must be rejected when limit is {$limit_mb}MB."
                );

                $message = $response['data']['message'] ?? '';
                $this->assertStringContainsString(
                    (string) $limit_mb,
                    $message,
                    "Error message must mention the configured limit of {$limit_mb}MB."
                );
            } );
    }

    /**
     * Property 13: Files within the configured max size are accepted.
     *
     * Generates random limits (1-50 MB) and file sizes that are at or below
     * the limit, with valid plugin ZIPs, and verifies handleUpload() accepts them.
     */
    public function test_files_within_limit_are_accepted(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 50 ),   // max_size_mb limit
                Generator\choose( 0, 100 )    // percentage of limit (0-100%)
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $limit_mb, int $pct ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_json_responses, $bpi_test_options;
                $bpi_test_json_responses = array();

                // Set the file size limit.
                $bpi_test_options['bpi_max_file_size'] = $limit_mb;

                $max_bytes   = $limit_mb * 1024 * 1024;
                $file_size   = (int) ( $max_bytes * $pct / 100 );

                // Create a valid plugin ZIP.
                $zip_path = $this->tmpDir . '/within_' . $counter . '.zip';
                $this->createPluginZip( $zip_path, 500, 'within-plugin' );

                $this->setupUploadGlobals( $zip_path, $file_size, 'within-plugin.zip' );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    self::UPLOAD_RESPONSE_MSG
                );

                $response = end( $bpi_test_json_responses );
                $this->assertTrue(
                    $response['success'],
                    "File of {$file_size} bytes must be accepted when limit is {$limit_mb}MB ({$pct}% of limit)."
                );
            } );
    }

    /**
     * Property 13: When limit is 0, any file size is accepted.
     *
     * Generates random file sizes and verifies that when bpi_max_file_size
     * is 0 (unlimited), all files pass the size check.
     */
    public function test_zero_limit_accepts_any_size(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 500 * 1024 * 1024 ) // 1 byte to 500MB
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $file_size ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_json_responses, $bpi_test_options;
                $bpi_test_json_responses = array();

                // Set limit to 0 (unlimited).
                $bpi_test_options['bpi_max_file_size'] = 0;

                // Create a valid plugin ZIP.
                $zip_path = $this->tmpDir . '/unlimited_' . $counter . '.zip';
                $this->createPluginZip( $zip_path, 500, 'unlimited-plugin' );

                $this->setupUploadGlobals( $zip_path, $file_size, 'unlimited-plugin.zip' );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    self::UPLOAD_RESPONSE_MSG
                );

                $response = end( $bpi_test_json_responses );
                $this->assertTrue(
                    $response['success'],
                    "File of {$file_size} bytes must be accepted when limit is 0 (unlimited)."
                );
            } );
    }

    /**
     * Property 13: Boundary - file exactly at the limit is accepted.
     *
     * Generates random limits and verifies that a file whose size is exactly
     * equal to the limit passes validation.
     */
    public function test_file_exactly_at_limit_is_accepted(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 50 ) // max_size_mb limit
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $limit_mb ) use ( &$counter ): void {
                $counter++;
                global $bpi_test_json_responses, $bpi_test_options;
                $bpi_test_json_responses = array();

                $bpi_test_options['bpi_max_file_size'] = $limit_mb;

                $exact_bytes = $limit_mb * 1024 * 1024;

                $zip_path = $this->tmpDir . '/exact_' . $counter . '.zip';
                $this->createPluginZip( $zip_path, 500, 'exact-plugin' );

                $this->setupUploadGlobals( $zip_path, $exact_bytes, 'exact-plugin.zip' );

                $this->uploader->handleUpload();

                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertNotEmpty(
                    $bpi_test_json_responses,
                    self::UPLOAD_RESPONSE_MSG
                );

                $response = end( $bpi_test_json_responses );
                $this->assertTrue(
                    $response['success'],
                    "File of exactly {$exact_bytes} bytes must be accepted when limit is {$limit_mb}MB."
                );
            } );
    }
}
