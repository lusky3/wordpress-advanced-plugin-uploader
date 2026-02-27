<?php
/**
 * Unit tests for the BPIRollbackManager class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIRollbackManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for plugin backup, restore, cleanup, and partial install removal.
 *
 * Uses real temporary directories via sys_get_temp_dir() to exercise
 * actual filesystem operations.
 */
class RollbackManagerTest extends TestCase {

    /**
     * The rollback manager instance under test.
     *
     * @var BPIRollbackManager
     */
    private BPIRollbackManager $manager;

    /**
     * Temporary directory root for this test run.
     *
     * @var string
     */
    private string $tmpDir;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bpi_rollback_test_' . uniqid();
        mkdir( $this->tmpDir, 0755, true );

        $this->manager = new BPIRollbackManager();
    }

    /**
     * Tear down test fixtures.
     */
    protected function tearDown(): void {
        $this->recursiveDelete( $this->tmpDir );

        // Clean up backup directories created by createBackup().
        $backupBase = WP_CONTENT_DIR . '/bpi-backups';
        if ( is_dir( $backupBase ) ) {
            $this->recursiveDelete( $backupBase );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a fake plugin directory with sample files.
     *
     * @param string $path    Directory path.
     * @param array  $files   Associative array of relative path => content.
     */
    private function createPluginDir( string $path, array $files = array() ): void {
        if ( empty( $files ) ) {
            $files = array(
                'my-plugin.php' => "<?php\n// Plugin Name: My Plugin\n// Version: 1.0.0\n",
                'readme.txt'    => "=== My Plugin ===\nStable tag: 1.0.0\n",
            );
        }

        mkdir( $path, 0755, true );

        foreach ( $files as $relative => $content ) {
            $full = $path . DIRECTORY_SEPARATOR . $relative;
            $dir  = dirname( $full );
            if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            file_put_contents( $full, $content );
        }
    }

    /**
     * Read all files in a directory into an associative array.
     *
     * @param string $path Directory path.
     * @return array Relative path => content.
     */
    private function readDirContents( string $path ): array {
        $result   = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            $relative            = str_replace( $path . DIRECTORY_SEPARATOR, '', $file->getPathname() );
            $relative            = str_replace( '\\', '/', $relative );
            $result[ $relative ] = file_get_contents( $file->getPathname() );
        }

        ksort( $result );
        return $result;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path Directory path.
     */
    private function recursiveDelete( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getPathname() );
            } else {
                unlink( $item->getPathname() );
            }
        }

        rmdir( $path );
    }

    // ------------------------------------------------------------------
    // createBackup() tests
    // ------------------------------------------------------------------

    /**
     * Test that createBackup creates a copy of the plugin directory.
     */
    public function test_create_backup_copies_plugin_directory(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'my-plugin';
        $files      = array(
            'my-plugin.php'       => "<?php\n// Plugin Name: My Plugin\n",
            'includes/helper.php' => "<?php\nfunction helper() {}\n",
        );
        $this->createPluginDir( $plugin_dir, $files );

        $backup_path = $this->manager->createBackup( $plugin_dir );

        $this->assertIsString( $backup_path, 'createBackup should return a string path' );
        $this->assertDirectoryExists( $backup_path );

        $original_contents = $this->readDirContents( $plugin_dir );
        $backup_contents   = $this->readDirContents( $backup_path );

        $this->assertSame( $original_contents, $backup_contents, 'Backup should be an exact copy' );
    }

    /**
     * Test that createBackup returns WP_Error when source doesn't exist.
     */
    public function test_create_backup_returns_error_when_source_missing(): void {
        $result = $this->manager->createBackup( $this->tmpDir . '/nonexistent-plugin' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'backup_source_missing', $result->get_error_code() );
    }

    /**
     * Test that createBackup preserves nested directory structure.
     */
    public function test_create_backup_preserves_nested_structure(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'complex-plugin';
        $files      = array(
            'plugin.php'                  => '<?php // main file',
            'includes/class-a.php'        => '<?php class A {}',
            'includes/sub/class-b.php'    => '<?php class B {}',
            'assets/css/style.css'        => 'body { margin: 0; }',
            'assets/js/script.js'         => 'console.log("hi");',
        );
        $this->createPluginDir( $plugin_dir, $files );

        $backup_path = $this->manager->createBackup( $plugin_dir );

        $this->assertIsString( $backup_path );
        $this->assertSame(
            $this->readDirContents( $plugin_dir ),
            $this->readDirContents( $backup_path )
        );
    }

    // ------------------------------------------------------------------
    // restoreBackup() tests
    // ------------------------------------------------------------------

    /**
     * Test that restoreBackup restores files from backup.
     */
    public function test_restore_backup_restores_files(): void {
        // Create original plugin v1.
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'my-plugin';
        $v1_files   = array(
            'my-plugin.php' => "<?php\n// Version: 1.0.0\n",
            'readme.txt'    => 'v1 readme',
        );
        $this->createPluginDir( $plugin_dir, $v1_files );

        // Back it up.
        $backup_path = $this->manager->createBackup( $plugin_dir );
        $this->assertIsString( $backup_path );

        // Simulate an update by replacing plugin contents.
        file_put_contents( $plugin_dir . DIRECTORY_SEPARATOR . 'my-plugin.php', "<?php\n// Version: 2.0.0\n" );
        file_put_contents( $plugin_dir . DIRECTORY_SEPARATOR . 'readme.txt', 'v2 readme' );

        // Restore from backup.
        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertTrue( $result );
        $this->assertSame( $v1_files, $this->readDirContents( $plugin_dir ) );
    }

    /**
     * Test that restoreBackup returns WP_Error when backup doesn't exist.
     */
    public function test_restore_backup_returns_error_when_backup_missing(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'my-plugin';
        $this->createPluginDir( $plugin_dir );

        $result = $this->manager->restoreBackup( $this->tmpDir . '/no-such-backup', $plugin_dir );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'restore_backup_missing', $result->get_error_code() );
    }

    /**
     * Test that restoreBackup works when plugin directory doesn't exist yet.
     */
    public function test_restore_backup_creates_plugin_dir_if_missing(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'my-plugin';
        $files      = array( 'plugin.php' => '<?php // test' );
        $this->createPluginDir( $plugin_dir, $files );

        $backup_path = $this->manager->createBackup( $plugin_dir );
        $this->assertIsString( $backup_path );

        // Remove the plugin directory entirely.
        $this->recursiveDelete( $plugin_dir );
        $this->assertDirectoryDoesNotExist( $plugin_dir );

        // Restore should recreate it.
        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertTrue( $result );
        $this->assertDirectoryExists( $plugin_dir );
        $this->assertSame( $files, $this->readDirContents( $plugin_dir ) );
    }

    // ------------------------------------------------------------------
    // cleanupBackup() tests
    // ------------------------------------------------------------------

    /**
     * Test that cleanupBackup removes the backup directory.
     */
    public function test_cleanup_backup_removes_directory(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'my-plugin';
        $this->createPluginDir( $plugin_dir );

        $backup_path = $this->manager->createBackup( $plugin_dir );
        $this->assertIsString( $backup_path );
        $this->assertDirectoryExists( $backup_path );

        $this->manager->cleanupBackup( $backup_path );

        $this->assertDirectoryDoesNotExist( $backup_path );
    }

    /**
     * Test that cleanupBackup does nothing when path doesn't exist.
     */
    public function test_cleanup_backup_does_nothing_for_nonexistent_path(): void {
        // Should not throw or error.
        $this->manager->cleanupBackup( $this->tmpDir . '/nonexistent-backup' );
        $this->assertTrue( true, 'cleanupBackup should not throw for missing path' );
    }

    // ------------------------------------------------------------------
    // removePartialInstall() tests
    // ------------------------------------------------------------------

    /**
     * Test that removePartialInstall removes the plugin directory.
     */
    public function test_remove_partial_install_removes_directory(): void {
        $plugin_dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'partial-plugin';
        $this->createPluginDir( $plugin_dir, array(
            'plugin.php'   => '<?php // partial',
            'includes/a.php' => '<?php // partial file',
        ) );

        $this->assertDirectoryExists( $plugin_dir );

        $this->manager->removePartialInstall( $plugin_dir );

        $this->assertDirectoryDoesNotExist( $plugin_dir );
    }

    /**
     * Test that removePartialInstall does nothing when directory doesn't exist.
     */
    public function test_remove_partial_install_does_nothing_when_missing(): void {
        $this->manager->removePartialInstall( $this->tmpDir . '/nonexistent-plugin' );
        $this->assertTrue( true, 'removePartialInstall should not throw for missing dir' );
    }
}
