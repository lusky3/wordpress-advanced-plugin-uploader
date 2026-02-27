<?php
/**
 * Property test for path traversal detection.
 *
 * Feature: bulk-plugin-installer, Property 12: Path traversal detection
 *
 * **Validates: Requirements 7.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIBulkUploader;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class PathTraversalDetectionTest extends TestCase {

    use TestTrait;

    private const ZIP_CREATE_FAIL_MSG = 'Failed to create test ZIP.';

    private BPIBulkUploader $uploader;
    private string $tmpDir;

    protected function setUp(): void {
        $this->uploader = new BPIBulkUploader();
        $this->tmpDir   = sys_get_temp_dir() . '/bpi_pbt_pt_' . uniqid();
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

    /**
     * Create a ZIP archive with the given entry names.
     *
     * @param string   $zip_path    Path for the ZIP file.
     * @param string[] $entry_names File entry names to add.
     * @return bool True on success.
     */
    private function createZipWithEntries( string $zip_path, array $entry_names ): bool {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            return false;
        }
        foreach ( $entry_names as $name ) {
            $zip->addFromString( $name, '<?php // placeholder' );
        }
        $zip->close();
        return true;
    }

    /**
     * Property 12: ZIP entries with ../ traversal sequences are detected.
     *
     * Generates ZIP archives containing file entries with ../ at various
     * depths and verifies checkPathTraversal() returns true.
     */
    public function test_dot_dot_slash_traversal_detected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 5 ),
                Generator\elements( 'payload.php', 'evil.txt', 'config.ini', 'shell.php' )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $depth, string $filename ) use ( &$counter ): void {
                $counter++;
                $traversal = str_repeat( '../', $depth );
                $entry     = $traversal . $filename;
                $zip_path  = $this->tmpDir . '/dotdot_' . $counter . '.zip';

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, array( $entry ) ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertTrue(
                    $result,
                    "checkPathTraversal() must detect '../' traversal in entry: {$entry}"
                );
            } );
    }

    /**
     * Property 12: ZIP entries with ..\\ backslash traversal are detected.
     *
     * Generates ZIP archives containing file entries with ..\\ (Windows-style)
     * traversal and verifies checkPathTraversal() returns true.
     */
    public function test_backslash_traversal_detected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\choose( 1, 5 ),
                Generator\elements( 'payload.php', 'evil.txt', 'config.ini', 'shell.php' )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( int $depth, string $filename ) use ( &$counter ): void {
                $counter++;
                $traversal = str_repeat( '..\\', $depth );
                $entry     = $traversal . $filename;
                $zip_path  = $this->tmpDir . '/backslash_' . $counter . '.zip';

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, array( $entry ) ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertTrue(
                    $result,
                    "checkPathTraversal() must detect '..\\\' traversal in entry: {$entry}"
                );
            } );
    }

    /**
     * Property 12: ZIP entries with absolute Unix paths are detected.
     *
     * Generates ZIP archives containing file entries starting with /
     * and verifies checkPathTraversal() returns true.
     */
    public function test_absolute_unix_paths_detected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\elements(
                    '/etc/passwd',
                    '/tmp/evil.php',
                    '/var/www/html/backdoor.php',
                    '/usr/local/bin/exploit',
                    '/home/user/shell.php'
                )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( string $entry ) use ( &$counter ): void {
                $counter++;
                $zip_path = $this->tmpDir . '/absunix_' . $counter . '.zip';

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, array( $entry ) ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertTrue(
                    $result,
                    "checkPathTraversal() must detect absolute Unix path: {$entry}"
                );
            } );
    }

    /**
     * Property 12: ZIP entries with absolute Windows paths are detected.
     *
     * Generates ZIP archives containing file entries starting with drive
     * letters (e.g. C:\, D:/) and verifies checkPathTraversal() returns true.
     */
    public function test_absolute_windows_paths_detected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\elements( 'C', 'D', 'E', 'Z' ),
                Generator\elements( '\\', '/' ),
                Generator\elements( 'Windows\\System32\\evil.dll', 'Users\\Admin\\backdoor.php', 'temp\\shell.php' )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( string $drive, string $sep, string $path ) use ( &$counter ): void {
                $counter++;
                $entry    = $drive . ':' . $sep . $path;
                $zip_path = $this->tmpDir . '/abswin_' . $counter . '.zip';

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, array( $entry ) ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertTrue(
                    $result,
                    "checkPathTraversal() must detect absolute Windows path: {$entry}"
                );
            } );
    }

    /**
     * Property 12: Mixed traversal patterns within a single ZIP are detected.
     *
     * Generates ZIPs containing a clean entry alongside a malicious entry
     * and verifies the archive is still flagged.
     */
    public function test_mixed_clean_and_traversal_entries_detected(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\elements(
                    '../../etc/passwd',
                    '..\\..\\Windows\\System32\\config',
                    '/tmp/evil.php',
                    'C:\\evil.php',
                    'subdir/../../../escape.php'
                )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( string $malicious ) use ( &$counter ): void {
                $counter++;
                $zip_path = $this->tmpDir . '/mixed_' . $counter . '.zip';

                $entries = array(
                    'my-plugin/my-plugin.php',
                    'my-plugin/readme.txt',
                    $malicious,
                );

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, $entries ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertTrue(
                    $result,
                    "checkPathTraversal() must detect traversal even with clean entries present. Malicious: {$malicious}"
                );
            } );
    }

    /**
     * Property 12: Clean paths return false (no false positives).
     *
     * Generates ZIP archives with legitimate plugin directory structures
     * and verifies checkPathTraversal() returns false.
     */
    public function test_clean_paths_return_false(): void {
        $counter = 0;
        $this
            ->forAll(
                Generator\elements(
                    'my-plugin',
                    'awesome-seo',
                    'contact-form',
                    'woo-extension',
                    'security-scanner'
                )
            )
            ->withMaxSize( 150 )
            ->__invoke( function ( string $slug ) use ( &$counter ): void {
                $counter++;
                $zip_path = $this->tmpDir . '/clean_' . $counter . '.zip';

                $entries = array(
                    $slug . '/' . $slug . '.php',
                    $slug . '/readme.txt',
                    $slug . '/includes/class-main.php',
                    $slug . '/assets/style.css',
                );

                $this->assertTrue(
                    $this->createZipWithEntries( $zip_path, $entries ),
                    self::ZIP_CREATE_FAIL_MSG
                );

                $result = $this->uploader->checkPathTraversal( $zip_path );
                if ( file_exists( $zip_path ) ) {
                    unlink( $zip_path );
                }

                $this->assertFalse(
                    $result,
                    "checkPathTraversal() must return false for clean plugin paths (slug: {$slug})"
                );
            } );
    }
}
