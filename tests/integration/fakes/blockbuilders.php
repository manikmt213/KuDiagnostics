<?php
/**
 * Stand-in for Divi 5 and Etch block types (no copies on the development machine): registers block types named
 * "divi/…" and "etch/…" so the block-based builder tools see the builders as active. Page content in the tests is
 * written in WordPress block markup with those names. Checks KuDiagnostics' side only. Loaded with
 * KUDUY_FAKES=blockbuilders.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

add_action(
	'init',
	static function () {
		foreach ( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/text', 'etch/element', 'etch/text' ) as $name ) {
			if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				// Divi 5 modules declare their settings as nested objects in module.json (e.g. content.innerContent).
				register_block_type(
					$name,
					array(
						'render_callback' => static fn( $attrs, $content ) => $content,
						'attributes'      => str_starts_with( $name, 'divi/' ) ? array(
							'module'  => array( 'type' => 'object' ),
							'content' => array( 'type' => 'object' ),
						) : array(),
					)
				);
			}
		}
	}
);
