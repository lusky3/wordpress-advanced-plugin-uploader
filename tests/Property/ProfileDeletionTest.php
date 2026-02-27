<?php
/**
 * Property test for profile deletion.
 *
 * Feature: bulk-plugin-installer, Property 21: Profile deletion
 *
 * **Validates: Requirements 14.6**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIProfileManager;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

class ProfileDeletionTest extends TestCase {

    use TestTrait;

    private BPIProfileManager $manager;

    protected function setUp(): void {
        global $bpi_test_options;
        $bpi_test_options = array();

        $this->manager = new BPIProfileManager();
    }

    /**
     * Create profiles and return their IDs and data.
     *
     * @return array{ids: int[], data: array}
     */
    private function createProfiles( int $profileCount, int $seed ): array {
        $profileIds  = array();
        $profileData = array();
        for ( $i = 0; $i < $profileCount; $i++ ) {
            $name    = 'Profile ' . ( $i + 1 ) . ' Seed' . $seed;
            $plugins = array(
                array(
                    'slug'    => 'plugin-' . $i,
                    'name'    => 'Plugin ' . $i,
                    'version' => '1.' . $i . '.0',
                ),
            );
            $id = $this->manager->saveProfile( $name, $plugins );
            $this->assertGreaterThan( 0, $id );
            $profileIds[]       = $id;
            $profileData[ $id ] = array( 'name' => $name, 'plugins' => $plugins );
        }
        return array( 'ids' => $profileIds, 'data' => $profileData );
    }

    /**
     * Split profile IDs into delete and keep sets.
     *
     * @return array{delete: int[], keep: int[]}
     */
    private function splitDeleteKeep( array $profileIds, int $seed ): array {
        $toDelete = array();
        $toKeep   = array();
        foreach ( $profileIds as $idx => $id ) {
            if ( ( $seed + $idx ) % 3 !== 0 ) {
                $toDelete[] = $id;
            } else {
                $toKeep[] = $id;
            }
        }
        if ( empty( $toDelete ) && ! empty( $profileIds ) ) {
            $toDelete[] = array_pop( $toKeep );
        }
        return array( 'delete' => $toDelete, 'keep' => $toKeep );
    }

    /**
     * Assert that kept profiles are intact.
     */
    private function assertKeptProfilesIntact( array $toKeep, array $profileData ): void {
        foreach ( $toKeep as $id ) {
            $profile = $this->manager->getProfile( $id );
            $this->assertNotNull( $profile, "Non-deleted profile $id must still be retrievable." );
            $this->assertSame( $id, $profile['id'], "Profile $id must retain its ID." );
            $this->assertSame(
                $profileData[ $id ]['name'],
                $profile['name'],
                "Profile $id must retain its name."
            );
            $this->assertSame(
                $profileData[ $id ]['plugins'],
                $profile['plugins'],
                "Profile $id must retain its plugin data."
            );
        }
    }

    /**
     * Property 21: Profile deletion.
     *
     * **Validates: Requirements 14.6**
     */
    public function test_deleted_profiles_are_removed_and_not_retrievable(): void {
        $this
            ->forAll(
                Generator\choose( 1, 10 ),
                Generator\choose( 0, 9999 )
            )
            ->then( function ( int $profileCount, int $seed ): void {
                global $bpi_test_options;
                $bpi_test_options = array();

                $created = $this->createProfiles( $profileCount, $seed );
                $split   = $this->splitDeleteKeep( $created['ids'], $seed );

                // Delete the selected profiles.
                foreach ( $split['delete'] as $id ) {
                    $this->assertTrue(
                        $this->manager->deleteProfile( $id ),
                        "deleteProfile($id) must return true for existing profile."
                    );
                }

                // Verify deleted profiles return null.
                foreach ( $split['delete'] as $id ) {
                    $this->assertNull(
                        $this->manager->getProfile( $id ),
                        "Deleted profile $id must not be retrievable via getProfile()."
                    );
                }

                // Verify deleted profiles are not in getAllProfiles().
                $allProfiles  = $this->manager->getAllProfiles();
                $remainingIds = array_map( fn( $p ) => $p['id'], $allProfiles );

                foreach ( $split['delete'] as $id ) {
                    $this->assertNotContains( $id, $remainingIds,
                        "Deleted profile $id must not appear in getAllProfiles()." );
                }

                // Verify non-deleted profiles are intact.
                $this->assertKeptProfilesIntact( $split['keep'], $created['data'] );

                // Verify count.
                $this->assertCount(
                    count( $split['keep'] ),
                    $allProfiles,
                    'getAllProfiles() count must equal total profiles minus deleted count.'
                );

                // Verify re-delete returns false.
                foreach ( $split['delete'] as $id ) {
                    $this->assertFalse(
                        $this->manager->deleteProfile( $id ),
                        "deleteProfile($id) must return false for already-deleted profile."
                    );
                }
            } );
    }
}
