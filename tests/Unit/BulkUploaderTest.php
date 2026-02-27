<?php
/**
 * Unit tests for the BPIBulkUploader class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIBulkUploader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the bulk uploader ZIP validation, header extraction,
 * path traversal detection, slug extraction, and upload handling.
 */
class BulkUploaderTest extends TestCase {

    /**
     * The uploader instance under test.
     *
     * @var BPIBulkUploader
     */
    private BPIBulkUploader $uploader;

    /**
     * Temporary directory for test fixtures.
     *
     * @var string
     */
    private const VERSION_100 = '1.0.0';
    private const MALICIOUS_CONTENT = 'malicious content';

    private string $tmpDir;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        global $bpi_test_options, $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_options        = array();
        $bpi_test_nonce_valid    = true;
        $bpi_test_user_can       = true;
        $bpi_test_json_responses = array();

        $this->uploader = new BPIBulkUploader();
        $this->tmpDir  = sys_get_temp_dir() . '/bpi_test_' . uniqid();
        if ( ! is_dir( $this->tmpDir ) ) {
            mkdir( $this->tmpDir, 0777, true );
        }
    }

    /**
     * Clean up temp files after each test.
     */
    protected function tearDown(): void {
        $this->recursiveRmdir( $this->tmpDir );
    }

    // ---------------------------------------------------------------
    // Helper: create a valid plugin ZIP
    // ---------------------------------------------------------------

    /**
     * Create a ZIP file containing a valid WordPress plugin.
     *
     * @param string $slug       Plugin slug (top-level directory name).
     * @param array  $headers    Optional header overrides.
     * @param array  $extra_files Optional extra files to add (path => content).
     * @return string Path to the created ZIP file.
     */
    private function createPluginZip(
        string $slug = 'my-plugin',
        array $headers = array(),
        array $extra_files = array()
    ): string {
        $defaults = array(
            'Plugin Name'       => 'My Plugin',
            'Version'           => self::VERSION_100,
            'Author'            => 'Test Author',
            'Description'       => 'A test plugin.',
            'Requires PHP'      => '7.4',
            'Requires at least' => '5.8',
        );
        $headers = array_merge( $defaults, $headers );

        $header_block = "<?php\n/**\n";
        foreach ( $headers as $key => $value ) {
            $header_block .= " * {$key}: {$value}\n";
        }
        $header_block .= " */\n";

        $zip_path = $this->tmpDir . '/' . $slug . '.zip';
        $zip      = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
        $zip->addFromString( $slug . '/' . $slug . '.php', $header_block );

        foreach ( $extra_files as $path => $content ) {
            $zip->addFromString( $slug . '/' . $path, $content );
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * Create a ZIP file with arbitrary entries (for path traversal tests).
     *
     * @param array $entries Associative array of entry_name => content.
     * @return string Path to the created ZIP file.
     */
    private function createZipWithEntries( array $entries ): string {
        $zip_path = $this->tmpDir . '/test_' . uniqid() . '.zip';
        $zip      = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

        foreach ( $entries as $name => $content ) {
            $zip->addFromString( $name, $content );
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $dir Directory path.
     */
    private function recursiveRmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }
        rmdir( $dir );
    }

    // ---------------------------------------------------------------
    // validateZip() tests
    // ---------------------------------------------------------------

    /**
     * Test that validateZip() rejects a non-ZIP file.
     */
    public function test_validate_zip_rejects_non_zip_file(): void {
        $file_path = $this->tmpDir . '/not-a-zip.zip';
        file_put_contents( $file_path, 'This is not a ZIP file at all.' );

        $result = $this->uploader->validateZip( $file_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_zip', $result->get_error_code() );
    }

    /**
     * Test that validateZip() rejects a ZIP without plugin headers.
     */
    public function test_validate_zip_rejects_zip_without_plugin_headers(): void {
        $zip_path = $this->createZipWithEntries( array(
            'my-plugin/readme.txt' => 'Just a readme, no PHP plugin header.',
            'my-plugin/style.css'  => 'body { color: red; }',
        ) );

        $result = $this->uploader->validateZip( $zip_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_plugin_header', $result->get_error_code() );
    }

    /**
     * Test that validateZip() rejects a ZIP with PHP files but no Plugin Name header.
     */
    public function test_validate_zip_rejects_php_without_plugin_name(): void {
        $zip_path = $this->createZipWithEntries( array(
            'my-plugin/my-plugin.php' => "<?php\n// No plugin header here\necho 'hello';\n",
        ) );

        $result = $this->uploader->validateZip( $zip_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_plugin_header', $result->get_error_code() );
    }

    /**
     * Test that validateZip() accepts a valid plugin ZIP.
     */
    public function test_validate_zip_accepts_valid_plugin_zip(): void {
        $zip_path = $this->createPluginZip();

        $result = $this->uploader->validateZip( $zip_path );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['valid'] );
    }

    /**
     * Test that validateZip() returns error for non-existent file.
     */
    public function test_validate_zip_rejects_nonexistent_file(): void {
        $result = $this->uploader->validateZip( '/nonexistent/path/file.zip' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'file_not_found', $result->get_error_code() );
    }

    // ---------------------------------------------------------------
    // checkPathTraversal() tests
    // ---------------------------------------------------------------

    /**
     * Test that checkPathTraversal() detects ../ sequences.
     */
    public function test_check_path_traversal_detects_dot_dot_slash(): void {
        $zip_path = $this->createZipWithEntries( array(
            'my-plugin/../../../etc/passwd' => self::MALICIOUS_CONTENT,
        ) );

        $this->assertTrue( $this->uploader->checkPathTraversal( $zip_path ) );
    }

    /**
     * Test that checkPathTraversal() detects absolute paths.
     */
    public function test_check_path_traversal_detects_absolute_paths(): void {
        $zip_path = $this->createZipWithEntries( array(
            '/etc/passwd' => self::MALICIOUS_CONTENT,
        ) );

        $this->assertTrue( $this->uploader->checkPathTraversal( $zip_path ) );
    }

    /**
     * Test that checkPathTraversal() detects Windows drive letter paths.
     */
    public function test_check_path_traversal_detects_windows_absolute_paths(): void {
        $zip_path = $this->createZipWithEntries( array(
            'C:\\Windows\\System32\\evil.dll' => self::MALICIOUS_CONTENT,
        ) );

        $this->assertTrue( $this->uploader->checkPathTraversal( $zip_path ) );
    }

    /**
     * Test that checkPathTraversal() accepts clean paths.
     */
    public function test_check_path_traversal_accepts_clean_paths(): void {
        $zip_path = $this->createPluginZip();

        $this->assertFalse( $this->uploader->checkPathTraversal( $zip_path ) );
    }

    /**
     * Test that checkPathTraversal() detects backslash traversal.
     */
    public function test_check_path_traversal_detects_backslash_traversal(): void {
        $zip_path = $this->createZipWithEntries( array(
            'my-plugin\\..\\..\\etc\\passwd' => self::MALICIOUS_CONTENT,
        ) );

        $this->assertTrue( $this->uploader->checkPathTraversal( $zip_path ) );
    }

    // ---------------------------------------------------------------
    // extractPluginHeaders() tests
    // ---------------------------------------------------------------

    /**
     * Test that extractPluginHeaders() reads all standard headers.
     */
    public function test_extract_plugin_headers_reads_all_headers(): void {
        $zip_path = $this->createPluginZip( 'test-plugin', array(
            'Plugin Name'       => 'Test Plugin',
            'Version'           => '2.5.1',
            'Author'            => 'Jane Doe',
            'Description'       => 'A wonderful test plugin.',
            'Requires PHP'      => '8.0',
            'Requires at least' => '6.0',
        ) );

        $headers = $this->uploader->extractPluginHeaders( $zip_path );

        $this->assertSame( 'Test Plugin', $headers['plugin_name'] );
        $this->assertSame( '2.5.1', $headers['version'] );
        $this->assertSame( 'Jane Doe', $headers['author'] );
        $this->assertSame( 'A wonderful test plugin.', $headers['description'] );
        $this->assertSame( '8.0', $headers['requires_php'] );
        $this->assertSame( '6.0', $headers['requires_wp'] );
    }

    /**
     * Test that extractPluginHeaders() returns empty values for invalid ZIP.
     */
    public function test_extract_plugin_headers_returns_empty_for_invalid_zip(): void {
        $file_path = $this->tmpDir . '/not-a-zip.txt';
        file_put_contents( $file_path, 'not a zip' );

        $headers = $this->uploader->extractPluginHeaders( $file_path );

        $this->assertSame( '', $headers['plugin_name'] );
        $this->assertSame( '', $headers['version'] );
    }

    /**
     * Test that extractPluginHeaders() handles partial headers.
     */
    public function test_extract_plugin_headers_handles_partial_headers(): void {
        // Create a minimal header block with only Plugin Name and Version.
        $content  = "<?php\n/**\n * Plugin Name: Partial Plugin\n * Version: 1.0.0\n */\n";
        $zip_path = $this->createZipWithEntries( array(
            'partial-plugin/partial-plugin.php' => $content,
        ) );

        $headers = $this->uploader->extractPluginHeaders( $zip_path );

        $this->assertSame( 'Partial Plugin', $headers['plugin_name'] );
        $this->assertSame( self::VERSION_100, $headers['version'] );
        $this->assertSame( '', $headers['requires_php'] );
        $this->assertSame( '', $headers['requires_wp'] );
    }

    // ---------------------------------------------------------------
    // getPluginSlug() tests
    // ---------------------------------------------------------------

    /**
     * Test that getPluginSlug() extracts slug from directory structure.
     */
    public function test_get_plugin_slug_from_directory(): void {
        $zip_path = $this->createPluginZip( 'awesome-plugin' );

        $slug = $this->uploader->getPluginSlug( $zip_path );

        $this->assertSame( 'awesome-plugin', $slug );
    }

    /**
     * Test that getPluginSlug() falls back to PHP filename without directory.
     */
    public function test_get_plugin_slug_fallback_to_filename(): void {
        $content  = "<?php\n/**\n * Plugin Name: Flat Plugin\n */\n";
        $zip_path = $this->createZipWithEntries( array(
            'flat-plugin.php' => $content,
        ) );

        $slug = $this->uploader->getPluginSlug( $zip_path );

        $this->assertSame( 'flat-plugin', $slug );
    }

    /**
     * Test that getPluginSlug() returns empty string for invalid ZIP.
     */
    public function test_get_plugin_slug_returns_empty_for_invalid_zip(): void {
        $file_path = $this->tmpDir . '/bad.txt';
        file_put_contents( $file_path, 'not a zip' );

        $slug = $this->uploader->getPluginSlug( $file_path );

        $this->assertSame( '', $slug );
    }

    // ---------------------------------------------------------------
    // File size enforcement tests
    // ---------------------------------------------------------------

    /**
     * Test that handleUpload() rejects files exceeding max file size.
     */
    public function test_handle_upload_rejects_oversized_file(): void {
        global $bpi_test_options, $bpi_test_json_responses;

        // Set max file size to 1 MB.
        $bpi_test_options['bpi_max_file_size'] = 1;

        // Create a valid plugin ZIP.
        $zip_path = $this->createPluginZip();

        // Simulate $_POST and $_FILES.
        $_POST['_wpnonce'] = 'test_nonce';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'my-plugin.zip',
            'size'     => 2 * 1024 * 1024, // 2 MB — exceeds 1 MB limit.
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $last = end( $bpi_test_json_responses );
        $this->assertFalse( $last['success'] );
        $this->assertStringContainsString( '1MB', $last['data']['message'] );

        // Clean up superglobals.
        unset( $_POST['_wpnonce'], $_FILES['plugin_zip'] );
    }

    /**
     * Test that handleUpload() accepts files within size limit.
     */
    public function test_handle_upload_accepts_file_within_size_limit(): void {
        global $bpi_test_options, $bpi_test_json_responses;

        // Set max file size to 10 MB.
        $bpi_test_options['bpi_max_file_size'] = 10;

        $zip_path = $this->createPluginZip();
        $actual_size = filesize( $zip_path );

        $_POST['_wpnonce'] = 'test_nonce';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'my-plugin.zip',
            'size'     => $actual_size,
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $this->assertNotEmpty( $bpi_test_json_responses );
        $last = end( $bpi_test_json_responses );
        $this->assertTrue( $last['success'] );
        $this->assertSame( 'my-plugin', $last['data']['slug'] );

        unset( $_POST['_wpnonce'], $_FILES['plugin_zip'] );
    }

    /**
     * Test that handleUpload() allows any size when max is 0 (server default).
     */
    public function test_handle_upload_allows_any_size_when_limit_is_zero(): void {
        global $bpi_test_options, $bpi_test_json_responses;

        $bpi_test_options['bpi_max_file_size'] = 0;

        $zip_path = $this->createPluginZip();

        $_POST['_wpnonce'] = 'test_nonce';
        $_FILES['plugin_zip'] = array(
            'tmp_name' => $zip_path,
            'name'     => 'my-plugin.zip',
            'size'     => 500 * 1024 * 1024, // 500 MB.
            'error'    => UPLOAD_ERR_OK,
        );

        $this->uploader->handleUpload();

        $last = end( $bpi_test_json_responses );
        $this->assertTrue( $last['success'] );

        unset( $_POST['_wpnonce'], $_FILES['plugin_zip'] );
    }

    // ---------------------------------------------------------------
    // handleUpload() security tests
    // ---------------------------------------------------------------

    /**
     * Test that handleUpload() rejects invalid nonce.
     */
    public function test_handle_upload_rejects_invalid_nonce(): void {
        global $bpi_test_nonce_valid, $bpi_test_json_responses;
        $bpi_test_nonce_valid = false;

        $_POST['_wpnonce'] = 'bad_nonce';

        $this->uploader->handleUpload();

        $last = end( $bpi_test_json_responses );
        $this->assertFalse( $last['success'] );
        $this->assertStringContainsString( 'Security verification failed', $last['data']['message'] );

        unset( $_POST['_wpnonce'] );
    }

    /**
     * Test that handleUpload() rejects users without install_plugins capability.
     */
    public function test_handle_upload_rejects_unauthorized_user(): void {
        global $bpi_test_user_can, $bpi_test_json_responses;
        $bpi_test_user_can = false;

        $_POST['_wpnonce'] = 'test_nonce';

        $this->uploader->handleUpload();

        $last = end( $bpi_test_json_responses );
        $this->assertFalse( $last['success'] );
        $this->assertStringContainsString( 'permission', $last['data']['message'] );

        unset( $_POST['_wpnonce'] );
    }

    /**
     * Test that handleUpload() rejects when no file is uploaded.
     */
    public function test_handle_upload_rejects_missing_file(): void {
        global $bpi_test_json_responses;

        $_POST['_wpnonce'] = 'test_nonce';
        // No $_FILES set.

        $this->uploader->handleUpload();

        $last = end( $bpi_test_json_responses );
        $this->assertFalse( $last['success'] );
        $this->assertStringContainsString( 'No file', $last['data']['message'] );

        unset( $_POST['_wpnonce'] );
    }

    // ---------------------------------------------------------------
    // validateZip() rejects path traversal
    // ---------------------------------------------------------------

    /**
     * Test that validateZip() rejects ZIPs with path traversal.
     */
    public function test_validate_zip_rejects_path_traversal(): void {
        $zip_path = $this->createZipWithEntries( array(
            'my-plugin/../../../etc/passwd' => 'evil',
            'my-plugin/my-plugin.php'       => "<?php\n/**\n * Plugin Name: Evil\n */\n",
        ) );

        $result = $this->uploader->validateZip( $zip_path );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'path_traversal', $result->get_error_code() );
    }
}
