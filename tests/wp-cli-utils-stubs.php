<?php
/**
 * WP_CLI\Utils namespace stubs for unit testing.
 *
 * Separated into its own file because PHP requires namespace declarations
 * to be the first statement in a file (or after declare).
 *
 * @package BulkPluginInstaller
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
    /**
     * Stub for WP_CLI\Utils\format_items().
     *
     * @param string $format  Output format (table, csv, json, etc.).
     * @param array  $items   Items to display.
     * @param array  $fields  Fields to display.
     */
    function format_items( string $format, array $items, array $fields ): void { // NOSONAR
        global $bpi_test_cli_format_items_calls;
        $bpi_test_cli_format_items_calls[] = array(
            'format' => $format,
            'items'  => $items,
            'fields' => $fields,
        );
    }
}

if ( ! function_exists( 'WP_CLI\\Utils\\make_progress_bar' ) ) {
    /**
     * Stub for WP_CLI\Utils\make_progress_bar().
     *
     * @param string $message Progress bar label.
     * @param int    $count   Total number of items.
     * @return object Object with tick() and finish() methods.
     */
    function make_progress_bar( string $message, int $count ): object { // NOSONAR
        return new class( $message, $count ) {
            /** @var string */
            public string $message;
            /** @var int */
            public int $count;
            /** @var int */
            public int $ticks = 0;
            /** @var bool */
            public bool $finished = false;

            public function __construct( string $message, int $count ) {
                $this->message = $message;
                $this->count   = $count;
            }

            public function tick(): void {
                $this->ticks++;
            }

            public function finish(): void {
                $this->finished = true;
            }
        };
    }
}
