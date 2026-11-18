<?php
/**
 * A stand-in for WP Rocket in tests (licensed copy not in Playground): its abilities and purge functions with the
 * names, inputs and results read on WP Rocket 3.24 (inc/Engine/Abilities, Preload/Abilities, inc/functions/files.php):
 * wp-rocket/get-options (allowlisted options only), wp-rocket/set-option (allowlist, "update" adds to lists, "replace"
 * overwrites; returns previous_value / new_value), wp-rocket/get-cache-health, wp-rocket/get-cache-status; every
 * ability checks rocket_manage_options, which administrators get. rocket_clean_domain / rocket_clean_post /
 * rocket_clean_files record their calls. Loaded with KUDUY_FAKES=wp-rocket; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Squiz.Commenting, WordPress.NamingConventions -- test fake.

define( 'WP_ROCKET_VERSION', '3.24-fake' );

const KUDUY_FAKE_ROCKET_ALLOWED = array( 'cache_logged_user', 'minify_css', 'minify_js', 'delay_js', 'lazyload', 'manual_preload', 'cache_reject_uri', 'exclude_css' );

function kuduy_fake_rocket_options(): array {
	$saved = get_option( 'wp_rocket_settings', array() );
	return ( is_array( $saved ) ? $saved : array() ) + array(
		'cache_logged_user' => 0,
		'minify_css'        => 1,
		'minify_js'         => 0,
		'delay_js'          => 0,
		'lazyload'          => 1,
		'manual_preload'    => 1,
		'cache_reject_uri'  => array( '/cart/' ),
		'exclude_css'       => array(),
		'secret_key'        => 'never-shown',
		'consumer_key'      => 'never-shown',
	);
}

function kuduy_fake_rocket_log( string $call ): void {
	$log   = (array) get_option( 'kuduy_fake_rocket_log', array() );
	$log[] = $call;
	update_option( 'kuduy_fake_rocket_log', $log, false );
}

function rocket_clean_domain( $lang = '', $filesystem = null ) {
	kuduy_fake_rocket_log( 'domain' );
	return true;
}

function rocket_clean_post( $post_id, $post = null ) {
	kuduy_fake_rocket_log( 'post:' . (int) ( is_object( $post_id ) ? $post_id->ID : $post_id ) );
	return true;
}

function rocket_clean_files( $urls, $filesystem = null, $run_actions = true ) {
	kuduy_fake_rocket_log( 'files:' . implode( ',', (array) $urls ) );
}

add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['rocket_manage_options'] = true;
		}
		return $allcaps;
	}
);

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'wp-rocket',
			array(
				'label'       => 'WP Rocket',
				'description' => 'Stand-in WP Rocket abilities.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		$can  = static fn() => current_user_can( 'rocket_manage_options' );
		$meta = array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
		wp_register_ability(
			'wp-rocket/get-options',
			array(
				'label'               => 'Get options',
				'description'         => 'Allowlisted WP Rocket options.',
				'category'            => 'wp-rocket',
				'execute_callback'    => static fn() => array_intersect_key( kuduy_fake_rocket_options(), array_flip( KUDUY_FAKE_ROCKET_ALLOWED ) ),
				'permission_callback' => $can,
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			'wp-rocket/set-option',
			array(
				'label'               => 'Set option',
				'description'         => 'Sets one allowlisted option.',
				'category'            => 'wp-rocket',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'option_name'  => array(
							'type' => 'string',
							'enum' => KUDUY_FAKE_ROCKET_ALLOWED,
						),
						'option_value' => array(
							'anyOf' => array(
								array( 'type' => 'string' ),
								array( 'type' => 'boolean' ),
								array( 'type' => 'integer' ),
								array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
						'update_mode'  => array(
							'type' => 'string',
							'enum' => array( 'update', 'replace' ),
						),
					),
					'required'   => array( 'option_name', 'option_value' ),
				),
				'execute_callback'    => static function ( $input ) {
					$name    = $input['option_name'];
					$options = kuduy_fake_rocket_options();
					$prev    = $options[ $name ];
					$value   = $input['option_value'];
					if ( is_array( $prev ) ) {
						$value = array_values( array_map( 'sanitize_text_field', (array) $value ) );
						if ( 'replace' !== ( $input['update_mode'] ?? 'update' ) ) {
							$value = array_values( array_unique( array_merge( $prev, $value ) ) );
						}
					} else {
						$value = (int) (bool) $value;
					}
					$options[ $name ] = $value;
					update_option( 'wp_rocket_settings', $options );
					return array(
						'success'        => true,
						'previous_value' => $prev,
						'new_value'      => $value,
					);
				},
				'permission_callback' => $can,
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			'wp-rocket/get-cache-health',
			array(
				'label'               => 'Cache health',
				'description'         => 'Preload queue counts.',
				'category'            => 'wp-rocket',
				'execute_callback'    => static fn() => array(
					'counts' => array(
						'pending'     => 2,
						'in-progress' => 0,
						'completed'   => 10,
						'failed'      => 0,
					),
				),
				'permission_callback' => $can,
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			'wp-rocket/get-cache-status',
			array(
				'label'               => 'Cache status',
				'description'         => 'Cache state of one page.',
				'category'            => 'wp-rocket',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'      => array( 'type' => 'string' ),
						'post_id'  => array( 'type' => 'integer' ),
						'term_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static fn( $input ) => array(
					'resolved_url' => isset( $input['post_id'] ) ? get_permalink( (int) $input['post_id'] ) : (string) ( $input['url'] ?? '' ),
					'tracked'      => true,
					'status'       => 'completed',
				),
				'permission_callback' => $can,
				'meta'                => $meta,
			)
		);
	}
);
