<?php
/**
 * A stand-in for Kadence Shop Kit in tests: its path constant and the kt_woo_extras option as saved by older
 * versions (JSON), read on Kadence Shop Kit 3.0.10. Loaded with KUDUY_FAKES=shop-kit; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Squiz.Commenting -- test fake.

define( 'KADENCE_WOO_EXTRAS_PATH', __DIR__ . '/' );
add_filter( 'pre_option_kt_woo_extras', static fn() => wp_json_encode( array( 'variation_swatches' => true, 'product_quickview' => 1, 'kt_reviews' => false ) ) );
