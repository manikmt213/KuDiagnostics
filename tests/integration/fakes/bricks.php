<?php
/**
 * Stand-in for Bricks (no copy on the development machine): the meta key constants from the Bricks Academy data model
 * and abilities under "bricks/" as the Academy describes them (start-here, get-page-elements, set-page-elements,
 * set-global-settings, execute-php). Inputs follow Bricks 2.4.5 as read on a live site: the page is postId; delete-post
 * takes postId too. Checks KuDiagnostics' side only. Loaded with KUDUY_FAKES=bricks.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

if ( defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
	return;
}

define( 'BRICKS_DB_PAGE_HEADER', '_bricks_page_header_2' );
define( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );
define( 'BRICKS_DB_PAGE_FOOTER', '_bricks_page_footer_2' );

function kuduy_fake_bricks_can() {
	return current_user_can( 'edit_posts' );
}

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'bricks',
			array(
				'label'       => 'Bricks',
				'description' => 'Stand-in Bricks abilities.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		$post = array(
			'type'       => 'object',
			'properties' => array(
				'postId' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'   => array( 'postId' ),
		);
		$reg  = static function ( string $name, array $schema, callable $run, ?bool $readonly, string $cap = 'edit_posts' ) {
			$args = array(
				'label'               => $name,
				'description'         => 'Stand-in ' . $name,
				'category'            => 'bricks',
				'input_schema'        => $schema,
				'execute_callback'    => $run,
				'permission_callback' => static fn() => current_user_can( $cap ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			);
			if ( null !== $readonly ) {
				$args['meta']['annotations'] = array( 'readonly' => $readonly );
			}
			wp_register_ability( 'bricks/' . $name, $args );
		};

		// No annotations: read by its name.
		$reg( 'start-here', array( 'type' => 'object' ), static fn() => array( 'next' => 'bricks/get-page-elements' ), null );
		$reg(
			'get-page-elements',
			$post,
			static fn( $input ) => array( 'elements' => (array) get_post_meta( (int) $input['postId'], BRICKS_DB_PAGE_CONTENT, true ) ),
			true
		);
		$set                             = $post;
		$set['properties']['elements']   = array( 'type' => 'array' );
		$set['required']                 = array( 'postId', 'elements' );
		$reg(
			'set-page-elements',
			$set,
			static function ( $input ) {
				foreach ( $input['elements'] as $element ) {
					if ( ! is_array( $element ) || empty( $element['id'] ) || empty( $element['name'] ) ) {
						return new WP_Error( 'invalid', 'Every element needs id and name.' );
					}
				}
				update_post_meta( (int) $input['postId'], BRICKS_DB_PAGE_CONTENT, $input['elements'] );
				return array( 'saved' => count( $input['elements'] ) );
			},
			false
		);
		$reg(
			'delete-post',
			$post,
			static function ( $input ) {
				update_option( 'kuduy_fake_bricks_deleted', (int) $input['postId'] );
				return array( 'deleted' => true );
			},
			null
		);
		$reg(
			'set-global-settings',
			array( 'type' => 'object' ),
			static function () {
				update_option( 'kuduy_fake_bricks_global', 1 );
				return array( 'success' => true );
			},
			false,
			'manage_options'
		);
		$reg(
			'execute-php',
			array( 'type' => 'object' ),
			static function () {
				update_option( 'kuduy_fake_bricks_php', 1 );
				return array( 'success' => true );
			},
			false,
			'manage_options'
		);
	}
);
