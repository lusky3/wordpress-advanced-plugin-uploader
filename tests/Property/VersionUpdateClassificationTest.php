<?php
/**
 * Property test for version update classification.
 *
 * Feature: bulk-plugin-installer, Property 24: Version update classification
 *
 * **Validates: Requirements 16.5**
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Property;

use BPIChangelogExtractor;
use Eris\TestTrait;
use Eris\Generator;
use PHPUnit\Framework\TestCase;

/**
 * Property 24: Version update classification.
 *
 * For any pair of semantic version strings (old, new), the
 * Changelog_Extractor should correctly classify the update as "major"
 * (different major version), "minor" (same major, different minor),
 * or "patch" (same major and minor).
 *
 * **Validates: Requirements 16.5**
 */
class VersionUpdateClassificationTest extends TestCase {

    use TestTrait;

    private BPIChangelogExtractor $extractor;

    protected function setUp(): void {
        $this->extractor = new BPIChangelogExtractor();
    }

    /**
     * Property 24: classifyUpdate returns "major" when major versions differ,
     * "minor" when major is same but minor differs, "patch" otherwise.
     *
     * **Validates: Requirements 16.5**
     */
    public function test_classify_update_returns_correct_category(): void {
        $this
            ->forAll(
                Generator\choose( 0, 50 ), // old major
                Generator\choose( 0, 50 ), // old minor
                Generator\choose( 0, 50 ), // old patch
                Generator\choose( 0, 50 ), // new major
                Generator\choose( 0, 50 ), // new minor
                Generator\choose( 0, 50 )  // new patch
            )
            ->then( function ( int $oldMajor, int $oldMinor, int $oldPatch, int $newMajor, int $newMinor, int $newPatch ): void {
                $old_ver = "{$oldMajor}.{$oldMinor}.{$oldPatch}";
                $new_ver = "{$newMajor}.{$newMinor}.{$newPatch}";

                $result = $this->extractor->classifyUpdate( $old_ver, $new_ver );

                // Result must be one of the three valid categories.
                $this->assertContains(
                    $result,
                    array( 'major', 'minor', 'patch' ),
                    "classifyUpdate('$old_ver', '$new_ver') returned '$result', expected one of major/minor/patch."
                );

                if ( $oldMajor !== $newMajor ) {
                    $this->assertSame(
                        'major',
                        $result,
                        "Expected 'major' for '$old_ver' → '$new_ver' (different major versions)."
                    );
                } elseif ( $oldMinor !== $newMinor ) {
                    $this->assertSame(
                        'minor',
                        $result,
                        "Expected 'minor' for '$old_ver' → '$new_ver' (same major, different minor)."
                    );
                } else {
                    $this->assertSame(
                        'patch',
                        $result,
                        "Expected 'patch' for '$old_ver' → '$new_ver' (same major and minor)."
                    );
                }
            } );
    }
}
