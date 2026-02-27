<?php
/**
 * Property test for update rollback round trip.
 *
 * Feature: bulk-plugin-installer, Property 10: Update rollback round trip
 *
 * **Validates: Requirements 5.1, 5.4, 5.5**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIRollbackManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class UpdateRollbackRoundTripTest extends TestCase {

    use TestTrait;

    private BPIRollbackManager $manager;
    private string $tmpDir;

    protected function setUp(): void {
        $this->manager = new BPIRollbackManager();
        $this->tmpDir  = sys_get_temp_dir() . '/bpi_pbt_rb_' . uniqid();
        mkdir( $this->tmpDir, 0777, true );
    }

    protected function tearDown(): void {
        $this->recursiveDelete( $this->tmpDir );

        // Clean up backup directories created by createBackup().
        $backupBase = WP_CONTENT_DIR . '/bpi-backups';
        if ( is_dir( $backupBase ) ) {
            $this->recursiveDelete( $backupBase );
        }
    }

    /**
     * Recursively delete a directory.
     */
    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $f ) {
            if ( $f->isDir() ) {

                rmdir( $f->getRealPath() );

            } else {

                unlink( $f->getRealPath() );

            }
        }
        rmdir( $path );
    }

    /**
     * Create a plugin directory with random files.
     *
     * @param string $dir       Directory path.
     * @param int    $fileCount Number of files to create.
     * @param int    $seed      Seed for deterministic content.
     * @return array Map of relative file paths to their contents.
     */
    private function createPluginDir( string $dir, int $fileCount, int $seed ): array {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        $files = array();
        for ( $i = 0; $i < $fileCount; $i++ ) {
            $fileName = 'file_' . $i . '.php';
            $content  = 'content_seed_' . $seed . '_file_' . $i . '_' . str_repeat( chr( ( $seed + $i ) % 26 + 65 ), ( $seed + $i ) % 200 + 10 );
            $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents( $filePath, $content );
            $files[ $fileName ] = $content;
        }

        return $files;
    }

    /**
     * Read all files from a directory into a map of relative path => content.
     *
     * @param string $dir Directory path.
     * @return array Map of relative file paths to their contents.
     */
    private function readDirContents( string $dir ): array {
        $files = array();
        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $it as $file ) {
            $relativePath = $it->getSubPathname();
            // Normalize directory separators for cross-platform comparison.
            $relativePath       = str_replace( '\\', '/', $relativePath );
            $files[ $relativePath ] = file_get_contents( $file->getPathname() );
        }

        ksort( $files );
        return $files;
    }

    /**
     * Property 10 (a): Update rollback round trip.
     *
     * For any plugin directory with random files:
     * - Create backup → modify the plugin directory (simulate update) → restore backup
     *   → verify directory matches pre-update state exactly (same files, same contents).
     * - After restore, cleanup backup → verify backup directory is gone.
     *
     * **Validates: Requirements 5.1, 5.4**
     */
    public function test_backup_restore_round_trip_preserves_directory(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 9999 )
            )
            ->then( function ( int $fileCount, int $seed ): void {
                $pluginDir = $this->tmpDir . '/plugin_' . $seed;

                // Create the original plugin directory.
                $this->createPluginDir( $pluginDir, $fileCount, $seed );
                $originalSnapshot = $this->readDirContents( $pluginDir );

                // Create backup (Requirement 5.4: backup before update).
                $backupPath = $this->manager->createBackup( $pluginDir );
                $this->assertIsString(
                    $backupPath,
                    'createBackup() must return a string path on success.'
                );
                $this->assertDirectoryExists(
                    $backupPath,
                    'Backup directory must exist after createBackup().'
                );

                // Simulate update: modify the plugin directory.
                // Add a new file.
                file_put_contents( $pluginDir . '/new_update_file.php', 'updated content ' . $seed );
                // Modify an existing file.
                $firstFile = $pluginDir . '/file_0.php';
                if ( file_exists( $firstFile ) ) {
                    file_put_contents( $firstFile, 'MODIFIED_BY_UPDATE_' . $seed );
                }

                // Restore backup (Requirement 5.1: restore on failure).
                $restoreResult = $this->manager->restoreBackup( $backupPath, $pluginDir );
                $this->assertTrue(
                    $restoreResult,
                    'restoreBackup() must return true on success.'
                );

                // Verify directory matches pre-update state exactly.
                $restoredSnapshot = $this->readDirContents( $pluginDir );
                $this->assertSame(
                    $originalSnapshot,
                    $restoredSnapshot,
                    'Restored directory must match the original pre-update state exactly.'
                );

                // Cleanup backup and verify it is gone.
                $this->manager->cleanupBackup( $backupPath );
                $this->assertDirectoryDoesNotExist(
                    $backupPath,
                    'Backup directory must be removed after cleanupBackup().'
                );

                // Clean up plugin dir for next iteration.
                $this->recursiveDelete( $pluginDir );
            } );
    }

    /**
     * Property 10 (b): New install failure cleanup.
     *
     * For any partially installed plugin directory:
     * - Create a directory with random files (simulating partial install)
     *   → call removePartialInstall() → verify directory is completely removed.
     *
     * **Validates: Requirements 5.5**
     */
    public function test_remove_partial_install_cleans_up_completely(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 9999 )
            )
            ->then( function ( int $fileCount, int $seed ): void {
                $pluginDir = $this->tmpDir . '/partial_' . $seed;

                // Create a directory simulating a partial install.
                $this->createPluginDir( $pluginDir, $fileCount, $seed );
                $this->assertDirectoryExists(
                    $pluginDir,
                    'Partial install directory must exist before cleanup.'
                );

                // Remove partial install.
                $this->manager->removePartialInstall( $pluginDir );

                // Verify directory is completely removed.
                $this->assertDirectoryDoesNotExist(
                    $pluginDir,
                    'Plugin directory must be completely removed after removePartialInstall().'
                );
            } );
    }

    /**
     * Property 10 (c): Round trip preserves file contents byte-for-byte.
     *
     * Generate random file names and random file contents, create a plugin
     * directory with them, backup → modify → restore → verify byte-for-byte match.
     *
     * **Validates: Requirements 5.1, 5.4**
     */
    public function test_round_trip_preserves_file_contents_byte_for_byte(): void {
        $this
            ->forAll(
                Generator\choose( 1, 8 ),
                Generator\choose( 10, 500 ),
                Generator\choose( 0, 9999 )
            )
            ->then( function ( int $fileCount, int $contentSize, int $seed ): void {
                $pluginDir = $this->tmpDir . '/bytematch_' . $seed;
                mkdir( $pluginDir, 0755, true );

                // Create files with varied binary-like content.
                $originalContents = array();
                for ( $i = 0; $i < $fileCount; $i++ ) {
                    $fileName = 'mod_' . $i . '.dat';
                    $content  = '';
                    for ( $j = 0; $j < $contentSize; $j++ ) {
                        $content .= chr( ( $seed + $i * 31 + $j * 7 ) % 256 );
                    }
                    file_put_contents( $pluginDir . DIRECTORY_SEPARATOR . $fileName, $content );
                    $originalContents[ $fileName ] = $content;
                }

                $originalSnapshot = $this->readDirContents( $pluginDir );

                // Backup.
                $backupPath = $this->manager->createBackup( $pluginDir );
                $this->assertIsString( $backupPath, 'Backup must succeed.' );

                // Simulate update: overwrite all files with different content.
                foreach ( $originalContents as $fileName => $content ) {
                    file_put_contents(
                        $pluginDir . DIRECTORY_SEPARATOR . $fileName,
                        'OVERWRITTEN_' . $seed . '_' . $fileName
                    );
                }

                // Restore.
                $result = $this->manager->restoreBackup( $backupPath, $pluginDir );
                $this->assertTrue( $result, 'Restore must succeed.' );

                // Verify byte-for-byte match.
                $restoredSnapshot = $this->readDirContents( $pluginDir );
                $this->assertSame(
                    $originalSnapshot,
                    $restoredSnapshot,
                    'Restored files must match original contents byte-for-byte.'
                );

                // Cleanup.
                $this->manager->cleanupBackup( $backupPath );
                $this->assertDirectoryDoesNotExist( $backupPath, 'Backup must be gone after cleanup.' );

                $this->recursiveDelete( $pluginDir );
            } );
    }
}
