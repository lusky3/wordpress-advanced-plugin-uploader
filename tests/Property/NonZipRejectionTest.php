<?php
/**
 * Property test for non-ZIP file rejection.
 *
 * Feature: bulk-plugin-installer, Property 1: Non-ZIP file rejection
 *
 * **Validates: Requirements 1.4, 7.1, 7.2, 7.3**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIBulkUploader;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class NonZipRejectionTest extends TestCase {

    use TestTrait;

    private BPIBulkUploader $uploader;
    private string $tmpDir;

    protected function setUp(): void {
        $this->uploader = new BPIBulkUploader();
        $this->tmpDir = sys_get_temp_dir() . '/bpi_pbt_nz_' . uniqid();
        mkdir( $this->tmpDir, 0777, true );
    }

    protected function tearDown(): void {
        if ( ! is_dir( $this->tmpDir ) ) {
            return;
        }
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

    private function makeBytes( int $len, int $seed ): string {
        $v = $seed % 256;
        if ( $v === 0x50 ) {
            $v = 0x51;
        }
        $b = str_repeat( chr( $v ), $len );
        if ( $len > 4 ) {
            $b[1] = chr( ( $seed + 37 ) % 256 );
            $b[2] = chr( ( $seed + 73 ) % 256 );
            $b[3] = chr( ( $seed + 111 ) % 256 );
        }
        return $b;
    }

    /**
     * Property 1: Random non-ZIP byte sequences are rejected.
     *
     * Generates byte sequences of various lengths (1-4096) guaranteed
     * not to be valid ZIP files and verifies validateZip() rejects them.
     */
    public function test_random_non_zip_bytes_are_rejected(): void {
        $c = 0;
        $this
            ->forAll(
                Generator\choose( 1, 4096 ),
                Generator\choose( 0, 254 )
            )
            ->__invoke( function ( int $len, int $seed ) use ( &$c ): void {
                $c++;
                $bytes = $this->makeBytes( $len, $seed );
                $path = $this->tmpDir . '/rnd_' . $c . '.bin';
                file_put_contents( $path, $bytes );
                $result = $this->uploader->validateZip( $path );
                if ( file_exists( $path ) ) {
                    unlink( $path );
                }
                $this->assertInstanceOf( \WP_Error::class, $result,
                    'validateZip() must return WP_Error for non-ZIP byte sequences.' );
                $this->assertSame( 'invalid_zip', $result->get_error_code(),
                    'Error code must be "invalid_zip" for non-ZIP files.' );
                $this->assertNotEmpty( $result->get_error_message(),
                    'Error message must identify the file and reason for rejection.' );
            } );
    }

    /**
     * Property 1: Known non-ZIP content types are rejected.
     *
     * Tests common non-ZIP formats (text, HTML, JSON, image headers,
     * PDF, null bytes, PHP code) are all rejected.
     */
    public function test_known_non_zip_formats_are_rejected(): void {
        $formats = array(
            "This is plain text content with no ZIP structure whatsoever.",
            "<html><body><h1>Not a ZIP</h1></body></html>",
            '{"type": "json", "valid_zip": false}',
            "\x89PNG\r\n\x1a\n" . str_repeat( 'x', 64 ),
            "GIF89a" . str_repeat( 'x', 64 ),
            "%PDF-1.4 " . str_repeat( 'x', 64 ),
            str_repeat( "\x00", 128 ),
            str_repeat( "\xFF", 128 ),
            "<?php echo 'not a zip'; ?>",
        );
        $c = 0;
        $this
            ->forAll(
                Generator\choose( 0, count( $formats ) - 1 )
            )
            ->__invoke( function ( int $idx ) use ( $formats, &$c ): void {
                $c++;
                $path = $this->tmpDir . '/fmt_' . $c . '.bin';
                file_put_contents( $path, $formats[ $idx ] );
                $result = $this->uploader->validateZip( $path );
                if ( file_exists( $path ) ) {
                    unlink( $path );
                }
                $this->assertInstanceOf( \WP_Error::class, $result,
                    'validateZip() must return WP_Error for non-ZIP content.' );
                $this->assertContains( $result->get_error_code(),
                    array( 'invalid_zip', 'no_plugin_header' ),
                    'Error code must indicate the file is not a valid plugin ZIP.' );
                $this->assertNotEmpty( $result->get_error_message(),
                    'Error message must identify the reason for rejection.' );
            } );
    }

    /**
     * Property 1: Empty files are rejected.
     *
     * An empty file (0 bytes) is not a valid plugin ZIP and must be rejected
     * with either 'invalid_zip' or 'no_plugin_header'.
     *
     * Note: ZipArchive::open() may open empty files in create mode on some
     * platforms, causing a harmless warning on close. We suppress this
     * expected PHP warning to keep the test clean.
     */
    public function test_empty_files_are_rejected(): void {
        $c = 0;
        $this
            ->forAll(
                Generator\choose( 0, 10 )
            )
            ->__invoke( function ( int $seed ) use ( &$c ): void {
                $c++;
                $path = $this->tmpDir . '/empty_' . $seed . '_' . $c . '.bin';
                file_put_contents( $path, '' );
                $result = @$this->uploader->validateZip( $path );
                if ( file_exists( $path ) ) {
                    @unlink( $path );
                }
                $this->assertInstanceOf( \WP_Error::class, $result,
                    'validateZip() must return WP_Error for empty files.' );
                $this->assertContains( $result->get_error_code(),
                    array( 'invalid_zip', 'no_plugin_header' ),
                    'Empty files must be rejected as invalid ZIP or missing plugin header.' );
            } );
    }
}
