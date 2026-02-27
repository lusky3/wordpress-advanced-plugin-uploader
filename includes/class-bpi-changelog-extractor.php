<?php
/**
 * Changelog Extractor for Bulk Plugin Installer.
 *
 * Reads version metadata and changelog information from plugin ZIP archives
 * for display in the Preview Screen.
 *
 * @package BulkPluginInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BPIChangelogExtractor
 *
 * Extracts changelog data from plugin ZIP archives by parsing readme.txt
 * (WordPress format) or CHANGELOG.md (Keep a Changelog format).
 */
class BPIChangelogExtractor {

    /**
     * Extract changelog data from a plugin ZIP archive.
     *
     * Opens the ZIP, looks for readme.txt or CHANGELOG.md (including in
     * plugin subdirectories), and parses whichever is found. Prefers
     * readme.txt for WordPress plugins.
     *
     * @param string $zip_path Path to the ZIP archive.
     * @return array {
     *     Changelog data, or empty array if no changelog found.
     *
     *     @type array  $entries      Changelog entries.
     *     @type string $last_updated "Last Updated" date from readme.txt headers.
     *     @type string $tested_up_to "Tested up to" WP version from readme.txt headers.
     * }
     */
    public function extract( string $zip_path ): array {
        $contents = $this->readZipContents( $zip_path );
        if ( null === $contents ) {
            return array();
        }

        $content = $this->selectContent( $contents['readme'], $contents['changelog'] );

        if ( null === $content ) {
            return array();
        }

        $is_readme = ( $content === $contents['readme'] );
        $parsed    = $is_readme ? $this->parseReadme( $content ) : $this->parseChangelogMd( $content );

        return array(
            'entries'      => $parsed['entries'] ?? array(),
            'last_updated' => $is_readme ? ( $parsed['last_updated'] ?? '' ) : '',
            'tested_up_to' => $is_readme ? ( $parsed['tested_up_to'] ?? '' ) : '',
        );
    }

    /**
     * Read readme.txt and changelog.md contents from a ZIP archive.
     *
     * @param string $zip_path Path to the ZIP archive.
     * @return array{readme: string|null, changelog: string|null}|null Null if ZIP cannot be opened.
     */
    private function readZipContents( string $zip_path ): ?array {
        if ( ! file_exists( $zip_path ) ) {
            return null;
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            return null;
        }

        $readme_content    = null;
        $changelog_content = null;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name     = $zip->getNameIndex( $i );
            $basename = basename( $name );

            if ( 'readme.txt' === strtolower( $basename ) && null === $readme_content ) {
                $readme_content = $zip->getFromIndex( $i );
            } elseif ( 'changelog.md' === strtolower( $basename ) && null === $changelog_content ) {
                $changelog_content = $zip->getFromIndex( $i );
            }
        }

        $zip->close();

        return array( 'readme' => $readme_content, 'changelog' => $changelog_content );
    }

    /**
     * Select the best content source, preferring readme over changelog.
     *
     * @param string|false|null $readme_content    Content from readme.txt.
     * @param string|false|null $changelog_content Content from changelog.md.
     * @return string|null The selected content or null if none available.
     */
    private function selectContent( $readme_content, $changelog_content ): ?string {
        if ( null !== $readme_content && false !== $readme_content ) {
            return $readme_content;
        }
        if ( null !== $changelog_content && false !== $changelog_content ) {
            return $changelog_content;
        }
        return null;
    }

    /**
     * Parse a WordPress readme.txt file for changelog entries and headers.
     *
     * Looks for the `== Changelog ==` section and extracts version entries
     * in the format `= X.Y.Z =` or `= X.Y.Z - YYYY-MM-DD =`.
     * Also extracts `Last Updated` and `Tested up to` from the header section.
     *
     * @param string $content The readme.txt file content.
     * @return array {
     *     @type array  $entries      Array of changelog entries.
     *     @type string $last_updated "Last Updated" date.
     *     @type string $tested_up_to "Tested up to" WP version.
     * }
     */
    public function parseReadme( string $content ): array {
        $result = array(
            'entries'      => array(),
            'last_updated' => '',
            'tested_up_to' => '',
        );

        // Extract header metadata.
        if ( preg_match( '/^Tested up to:\s*(.+)$/mi', $content, $match ) ) {
            $result['tested_up_to'] = trim( $match[1] );
        }

        if ( preg_match( '/^Last Updated:\s*(.+)$/mi', $content, $match ) ) {
            $result['last_updated'] = trim( $match[1] );
        }

        // Find the Changelog section.
        if ( ! preg_match( '/^==\s*Changelog\s*==/mi', $content, $match, PREG_OFFSET_CAPTURE ) ) {
            return $result;
        }

        $changelog_start = $match[0][1] + strlen( $match[0][0] );

        // Find the next == Section == or end of content.
        $remaining = substr( $content, $changelog_start );
        if ( preg_match( '/^==\s*[^=]/m', $remaining, $next_section, PREG_OFFSET_CAPTURE ) ) {
            $remaining = substr( $remaining, 0, $next_section[0][1] );
        }

        $result['entries'] = $this->parseVersionEntries(
            explode( "\n", $remaining ),
            '/^=\s*(\d[\da-zA-Z.\-]*)\s*(?:-\s*(\d{4}-\d{2}-\d{2}))?\s*=$/'
        );

        return $result;
    }

    /**
     * Parse version entries from lines using a version header pattern.
     *
     * @param array  $lines          Lines to parse.
     * @param string $version_pattern Regex pattern for version headers.
     * @return array Array of changelog entries.
     */
    private function parseVersionEntries( array $lines, string $version_pattern ): array {
        $entries       = array();
        $current_entry = null;

        foreach ( $lines as $line ) {
            $line = rtrim( $line );

            if ( preg_match( $version_pattern, $line, $ver_match ) ) {
                if ( null !== $current_entry ) {
                    $entries[] = $current_entry;
                }
                $current_entry = array(
                    'version' => trim( $ver_match[1] ),
                    'date'    => isset( $ver_match[2] ) ? trim( $ver_match[2] ) : '',
                    'changes' => array(),
                );
                continue;
            }

            if ( null !== $current_entry && preg_match( '/^\s*[\*\-]\s+(.+)$/', $line, $bullet ) ) {
                $current_entry['changes'][] = trim( $bullet[1] );
            }
        }

        if ( null !== $current_entry ) {
            $entries[] = $current_entry;
        }

        return $entries;
    }

    /**
     * Parse a CHANGELOG.md file (Keep a Changelog format).
     *
     * Version entries start with `## [X.Y.Z]` or `## X.Y.Z`, optionally
     * followed by a date.
     *
     * @param string $content The CHANGELOG.md file content.
     * @return array {
     *     @type array $entries Array of changelog entries.
     * }
     */
    public function parseChangelogMd( string $content ): array {
        return array(
            'entries' => $this->parseVersionEntries(
                explode( "\n", $content ),
                '/^##\s+\[?(\d[\da-zA-Z.\-]*)\]?\s*(?:-\s*(\d{4}-\d{2}-\d{2}))?/'
            ),
        );
    }

    /**
     * Filter changelog entries between two versions.
     *
     * Returns entries where version is greater than $from and less than
     * or equal to $to, using PHP's version_compare().
     *
     * @param array  $changelog Array of changelog entries with 'version' keys.
     * @param string $from      Installed version (exclusive lower bound).
     * @param string $to        New version (inclusive upper bound).
     * @return array Filtered changelog entries.
     */
    public function getEntriesBetween( array $changelog, string $from, string $to ): array {
        return array_values(
            array_filter(
                $changelog,
                function ( array $entry ) use ( $from, $to ): bool {
                    $version = $entry['version'] ?? '';
                    if ( '' === $version ) {
                        return false;
                    }
                    // version > $from AND version <= $to
                    return version_compare( $version, $from, '>' )
                        && version_compare( $version, $to, '<=' );
                }
            )
        );
    }

    /**
     * Classify an update using semantic versioning.
     *
     * Compares old and new version strings and returns:
     * - 'major' if the major versions differ
     * - 'minor' if major is the same but minor differs
     * - 'patch' for all other cases (same major+minor, different patch, or default)
     *
     * @param string $old_ver The currently installed version.
     * @param string $new_ver The new version.
     * @return string 'major', 'minor', or 'patch'.
     */
    public function classifyUpdate( string $old_ver, string $new_ver ): string {
        $old_parts = $this->parseVersionParts( $old_ver );
        $new_parts = $this->parseVersionParts( $new_ver );

        if ( $old_parts['major'] !== $new_parts['major'] ) {
            return 'major';
        }

        if ( $old_parts['minor'] !== $new_parts['minor'] ) {
            return 'minor';
        }

        return 'patch';
    }

    /**
     * Parse a version string into major, minor, and patch integers.
     *
     * @param string $version Version string (e.g., '1.2.3').
     * @return array{major: int, minor: int, patch: int}
     */
    private function parseVersionParts( string $version ): array {
        $parts = explode( '.', $version );

        return array(
            'major' => (int) ( $parts[0] ?? 0 ),
            'minor' => (int) ( $parts[1] ?? 0 ),
            'patch' => (int) ( $parts[2] ?? 0 ),
        );
    }
}
