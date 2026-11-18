<?php
/**
 * A stand-in for SEOPress PRO's redirections in tests (licensed copy not in Playground): the REST routes KuDiagnostics
 * calls, with the paths, parameters and response shapes read on SEOPress PRO 10.2.1 (src/Actions/Api/Redirections.php):
 * seopress_404 posts with the _seopress_redirections_* meta. Permission: manage_options (SEOPress uses its own
 * redirection capabilities, which administrators hold). Loaded with KUDUY_FAKES=seopress-pro; checks KuDiagnostics'
 * side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Squiz.Commenting -- test fake.

define( 'SEOPRESS_PRO_VERSION', '10.2.1-fake' );

add_action(
	'init',
	static function (): void {
		register_post_type( 'seopress_404', array( 'public' => false ) );
	}
);

/**
 * Redirection data as SEOPress's build_redirection_data() returns it.
 */
function kuduy_fake_seopress_row( WP_Post $post ): array {
	$id = $post->ID;
	return array(
		'id'           => $id,
		'origin'       => $post->post_title,
		'destination'  => get_post_meta( $id, '_seopress_redirections_value', true ),
		'enabled'      => 'yes' === get_post_meta( $id, '_seopress_redirections_enabled', true ),
		'type'         => (string) get_post_meta( $id, '_seopress_redirections_type', true ),
		'param'        => 'exact_match',
		'enabledRegex' => 'yes' === get_post_meta( $id, '_seopress_redirections_enabled_regex', true ),
		'count'        => (int) get_post_meta( $id, 'seopress_404_count', true ),
	);
}

function kuduy_fake_seopress_fields( int $id, array $p ): void {
	if ( isset( $p['destination'] ) ) {
		update_post_meta( $id, '_seopress_redirections_value', esc_url_raw( $p['destination'] ) );
	}
	if ( isset( $p['type'] ) && in_array( (string) $p['type'], array( '301', '302', '307', '410', '451' ), true ) ) {
		update_post_meta( $id, '_seopress_redirections_type', (string) $p['type'] );
	}
	foreach ( array(
		'enabled'      => '_seopress_redirections_enabled',
		'enabledRegex' => '_seopress_redirections_enabled_regex',
	) as $key => $meta ) {
		if ( isset( $p[ $key ] ) ) {
			if ( $p[ $key ] ) {
				update_post_meta( $id, $meta, 'yes' );
			} else {
				delete_post_meta( $id, $meta );
			}
		}
	}
}

function kuduy_fake_seopress_origin( string $origin, bool $regex ): string {
	$origin = trim( $origin );
	if ( $regex ) {
		return $origin;
	}
	$origin = (string) preg_replace( '~^' . preg_quote( home_url(), '~' ) . '~', '', $origin );
	return ltrim( $origin, '/' );
}

add_action(
	'rest_api_init',
	static function (): void {
		$can = static fn() => current_user_can( 'manage_options' );
		register_rest_route(
			'seopress/v1',
			'/redirections',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) {
						$q    = new WP_Query(
							array(
								'post_type'      => 'seopress_404',
								'post_status'    => 'publish',
								'posts_per_page' => (int) ( $r->get_param( 'per_page' ) ?: 20 ),
								'paged'          => (int) ( $r->get_param( 'page' ) ?: 1 ),
								's'              => (string) $r->get_param( 'search' ),
							)
						);
						return new WP_REST_Response(
							array(
								'data'       => array_map( 'kuduy_fake_seopress_row', $q->posts ),
								'total'      => $q->found_posts,
								'totalPages' => $q->max_num_pages,
							)
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) {
						$p = (array) $r->get_json_params();
						if ( empty( $p['origin'] ) ) {
							return new WP_Error( 'missing_parameters', 'Origin URL is required.', array( 'status' => 400 ) );
						}
						$id = wp_insert_post(
							array(
								'post_title'  => wp_slash( kuduy_fake_seopress_origin( (string) $p['origin'], ! empty( $p['enabledRegex'] ) ) ),
								'post_type'   => 'seopress_404',
								'post_status' => 'publish',
							)
						);
						$p += array(
							'enabled' => true,
							'type'    => '301',
						);
						kuduy_fake_seopress_fields( $id, $p );
						return new WP_REST_Response(
							array(
								'code' => 'created',
								'id'   => $id,
								'data' => kuduy_fake_seopress_row( get_post( $id ) ),
							),
							201
						);
					},
				),
			)
		);
		$one = static function ( WP_REST_Request $r ) {
			$post = get_post( (int) $r->get_param( 'id' ) );
			return $post && 'seopress_404' === $post->post_type ? $post : new WP_Error( 'not_found', 'Redirection not found.', array( 'status' => 404 ) );
		};
		register_rest_route(
			'seopress/v1',
			'/redirections/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) use ( $one ) {
						$post = $one( $r );
						return is_wp_error( $post ) ? $post : new WP_REST_Response( kuduy_fake_seopress_row( $post ) );
					},
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) use ( $one ) {
						$post = $one( $r );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						$p = (array) $r->get_json_params();
						if ( isset( $p['origin'] ) ) {
							$regex = isset( $p['enabledRegex'] ) ? ! empty( $p['enabledRegex'] ) : 'yes' === get_post_meta( $post->ID, '_seopress_redirections_enabled_regex', true );
							wp_update_post(
								array(
									'ID'         => $post->ID,
									'post_title' => wp_slash( kuduy_fake_seopress_origin( (string) $p['origin'], $regex ) ),
								)
							);
						}
						kuduy_fake_seopress_fields( $post->ID, $p );
						return new WP_REST_Response(
							array(
								'code' => 'updated',
								'data' => kuduy_fake_seopress_row( get_post( $post->ID ) ),
							)
						);
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) use ( $one ) {
						$post = $one( $r );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						wp_delete_post( $post->ID, true );
						return new WP_REST_Response(
							array(
								'code' => 'deleted',
								'id'   => $post->ID,
							)
						);
					},
				),
			)
		);
	}
);
