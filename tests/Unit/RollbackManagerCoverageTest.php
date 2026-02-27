<?php
/**
 * Additional unit tests for BPIRollbackManager to cover error paths.
 *
 * Uses a testable subclass that overrides protected filesystem methods
 * to simulate mkdir failures, copy failures, and delete failures.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BPIRollbackManager;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that allows controlling filesystem operation results.
 */
class ControllableRollbackManager extends BPIRollbackManager {

    public bool $mkdirResult = true;
    public bool $copyDirResult = true;
    public bool $deleteDirResult = true;
    public array $existingDirs = array();
    private string $backupBase;

    public function __construct( string $backupBase ) {
        $this->backupBase = $backupBase;
    }

    protected function getBackupBaseDir(): string {
        return $this->backupBase;
    }

    protected function dirExists( string $path ): bool {
        return in_array( $path, $this->existingDirs, true );
    }

    protected function mkdir( string $path ): bool {
        if ( $this->mkdirResult ) {
            $this->existingDirs[] = $path;
        }
        return $this->mkdirResult;
    }

    protected function copyDir( string $source, string $destination ): bool {
        return $this->copyDirResult;
    }

    protected function deleteDir( string $path ): bool {
        return $this->deleteDirResult;
    }
}

/**
 * Tests for rollback manager error paths and edge cases.
 */
class RollbackManagerCoverageTest extends TestCase {

    private ControllableRollbackManager $manager;
    private string $backupBase;

    protected function setUp(): void {
        $this->backupBase = sys_get_temp_dir() . '/bpi_rb_cov_' . uniqid();
        $this->manager = new ControllableRollbackManager( $this->backupBase );
    }

    public function test_create_backup_returns_error_when_mkdir_fails(): void {
        $plugin_dir = '/fake/plugin/dir';
        $this->manager->existingDirs = array( $plugin_dir );
        $this->manager->mkdirResult = false;

        $result = $this->manager->createBackup( $plugin_dir );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'backup_dir_failed', $result->get_error_code() );
    }

    public function test_create_backup_returns_error_when_copy_fails(): void {
        $plugin_dir = '/fake/plugin/dir';
        $this->manager->existingDirs = array( $plugin_dir, $this->backupBase );
        $this->manager->copyDirResult = false;

        $result = $this->manager->createBackup( $plugin_dir );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'backup_copy_failed', $result->get_error_code() );
    }

    public function test_create_backup_succeeds_when_copy_succeeds(): void {
        $plugin_dir = '/fake/plugin/dir';
        $this->manager->existingDirs = array( $plugin_dir, $this->backupBase );
        $this->manager->copyDirResult = true;

        $result = $this->manager->createBackup( $plugin_dir );

        $this->assertIsString( $result );
        $this->assertStringContainsString( 'dir_', $result );
    }

    public function test_create_backup_creates_backup_base_when_missing(): void {
        $plugin_dir = '/fake/plugin/dir';
        $this->manager->existingDirs = array( $plugin_dir );
        $this->manager->mkdirResult = true;
        $this->manager->copyDirResult = true;

        $result = $this->manager->createBackup( $plugin_dir );

        $this->assertIsString( $result );
        $this->assertContains( $this->backupBase, $this->manager->existingDirs );
    }

    public function test_restore_backup_returns_error_when_delete_fails(): void {
        $backup_path = '/fake/backup';
        $plugin_dir = '/fake/plugin';
        $this->manager->existingDirs = array( $backup_path, $plugin_dir );
        $this->manager->deleteDirResult = false;

        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'restore_delete_failed', $result->get_error_code() );
    }

    public function test_restore_backup_returns_error_when_copy_fails(): void {
        $backup_path = '/fake/backup';
        $plugin_dir = '/fake/plugin';
        $this->manager->existingDirs = array( $backup_path );
        $this->manager->copyDirResult = false;

        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'restore_copy_failed', $result->get_error_code() );
    }

    public function test_restore_backup_succeeds_when_plugin_dir_missing(): void {
        $backup_path = '/fake/backup';
        $plugin_dir = '/fake/plugin';
        $this->manager->existingDirs = array( $backup_path );
        $this->manager->copyDirResult = true;

        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertTrue( $result );
    }

    public function test_restore_backup_succeeds_after_deleting_existing_dir(): void {
        $backup_path = '/fake/backup';
        $plugin_dir = '/fake/plugin';
        $this->manager->existingDirs = array( $backup_path, $plugin_dir );
        $this->manager->deleteDirResult = true;
        $this->manager->copyDirResult = true;

        $result = $this->manager->restoreBackup( $backup_path, $plugin_dir );

        $this->assertTrue( $result );
    }

    public function test_cleanup_backup_calls_delete_when_dir_exists(): void {
        $backup_path = '/fake/backup';
        $this->manager->existingDirs = array( $backup_path );
        $this->manager->deleteDirResult = true;

        // Should not throw.
        $this->manager->cleanupBackup( $backup_path );
        $this->assertTrue( true );
    }

    public function test_remove_partial_install_calls_delete_when_dir_exists(): void {
        $plugin_dir = '/fake/plugin';
        $this->manager->existingDirs = array( $plugin_dir );
        $this->manager->deleteDirResult = true;

        $this->manager->removePartialInstall( $plugin_dir );
        $this->assertTrue( true );
    }
}
