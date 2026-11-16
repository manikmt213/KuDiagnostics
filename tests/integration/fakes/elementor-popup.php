<?php
/**
 * Stand-in for Elementor Pro's popup document (no Pro copy on the development machine): a "popup" document type whose
 * display settings are two control stacks, triggers and timing, saved with save_display_settings_data() - the shape of
 * ElementorPro\Modules\Popup\Document and its DisplaySettings\Triggers / Timing as read on Pro 4.0.4 on a live site
 * (2026-11-16), with a subset of their controls. Checks KuDiagnostics' side only. Loaded with KUDUY_FAKES=elementor-popup.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	return;
}

add_action(
	'elementor/documents/register',
	static function ( $documents_manager ) {
		require_once __DIR__ . '/elementor-popup-classes.php';
		$documents_manager->register_document_type( 'popup', \KuduyFake\Popup\Document::class );
	}
);
