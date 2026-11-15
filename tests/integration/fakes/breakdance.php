<?php
/**
 * Stand-in for the abilities Breakdance 3 / Oxygen 6 register (no licensed copy on the development machine): the same
 * ability names, meta shape (annotations.readonly, mcp.public), permission (manage_options) and the two functions
 * KuDiagnostics calls for undo, as read in Breakdance 3.0.0-beta.4 and Oxygen 6.2.0 on a live site. It stores a page
 * as Breakdance does (meta "<prefix>data" with tree_json_string). This checks KuDiagnostics' side only, not the
 * builders. Loaded with KUDUY_FAKES=breakdance; KUDUY_FAKES=oxygen loads it in Oxygen mode.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

namespace Breakdance\BreakdanceOxygen\Strings {
	function __bdox( $key ) {
		$mode = BREAKDANCE_MODE;
		$map  = array(
			'plugin_name'  => 'oxygen' === $mode ? 'Oxygen' : 'Breakdance',
			'_meta_prefix' => 'oxygen' === $mode ? '_oxygen_' : '_breakdance_',
		);
		return $map[ $key ] ?? '';
	}
}

namespace Breakdance\Render {
	function generateCacheForPost( $post_id ) {
		update_post_meta( $post_id, 'kuduy_fake_bd_cache_built', (int) get_post_meta( $post_id, 'kuduy_fake_bd_cache_built', true ) + 1 );
	}
}

namespace {
	if ( ! defined( 'BREAKDANCE_MODE' ) ) {
		define( 'BREAKDANCE_MODE', 'breakdance' );
	}

	function kuduy_fake_bd_can() {
		return current_user_can( 'manage_options' );
	}

	function kuduy_fake_bd_tree( $post_id ) {
		$data = get_post_meta( $post_id, \Breakdance\BreakdanceOxygen\Strings\__bdox( '_meta_prefix' ) . 'data', true );
		$tree = is_array( $data ) ? json_decode( (string) ( $data['tree_json_string'] ?? '' ), true ) : null;
		return is_array( $tree ) ? $tree : array( 'root' => array( 'id' => 1, 'children' => array() ) );
	}

	add_action(
		'wp_abilities_api_categories_init',
		static function () {
			wp_register_ability_category(
				BREAKDANCE_MODE,
				array(
					'label'       => 'Builder',
					'description' => 'Stand-in builder abilities.',
				)
			);
		}
	);

	add_action(
		'wp_abilities_api_init',
		static function () {
			$ns   = BREAKDANCE_MODE;
			$meta = static fn( bool $readonly ): array => array(
				'mcp'          => array( 'public' => true ),
				'annotations'  => $readonly ? array( 'readonly' => true ) : array( 'readonly' => false ),
				'show_in_rest' => true,
			);
			$post = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'post_id' ),
			);
			$none = array(
				'type'                 => 'object',
				'additionalProperties' => false,
			);

			wp_register_ability(
				"$ns/get-instructions",
				array(
					'label'               => 'Get Instructions',
					'description'         => 'Returns guidance for working with the builder.',
					'category'            => $ns,
					'input_schema'        => $none,
					'execute_callback'    => static fn() => array( 'instructions' => 'Stand-in instructions.' ),
					'permission_callback' => 'kuduy_fake_bd_can',
					'meta'                => $meta( true ),
				)
			);
			wp_register_ability(
				"$ns/get-post-tree",
				array(
					'label'               => 'Get Post Tree',
					'description'         => 'Retrieves the element tree for a post.',
					'category'            => $ns,
					'input_schema'        => $post,
					'execute_callback'    => static fn( $input ) => array( 'tree' => kuduy_fake_bd_tree( (int) $input['post_id'] ) ),
					'permission_callback' => 'kuduy_fake_bd_can',
					'meta'                => $meta( true ),
				)
			);
			$edit                             = $post;
			$edit['properties']['operations'] = array(
				'type'     => 'array',
				'minItems' => 1,
				'items'    => array( 'type' => 'object' ),
			);
			$edit['required']                 = array( 'post_id', 'operations' );
			wp_register_ability(
				"$ns/edit-post",
				array(
					'label'               => 'Edit Post',
					'description'         => 'Applies element operations atomically.',
					'category'            => $ns,
					'input_schema'        => $edit,
					'execute_callback'    => static function ( $input ) {
						$post_id = (int) $input['post_id'];
						if ( ! get_post( $post_id ) ) {
							return new \WP_Error( 'not_found', 'Post not found.' );
						}
						$tree = kuduy_fake_bd_tree( $post_id );
						foreach ( $input['operations'] as $op ) {
							if ( 'insert' !== ( $op['op'] ?? '' ) ) {
								return new \WP_Error( 'bad_op', 'Unknown operation.' );
							}
							$tree['root']['children'][] = array(
								'id'   => count( $tree['root']['children'] ) + 2,
								'data' => array(
									'type'       => (string) ( $op['payload']['element_type'] ?? '' ),
									'properties' => (array) ( $op['payload']['properties'] ?? array() ),
								),
							);
						}
						update_post_meta( $post_id, \Breakdance\BreakdanceOxygen\Strings\__bdox( '_meta_prefix' ) . 'data', array( 'tree_json_string' => wp_slash( wp_json_encode( $tree ) ) ) );
						\Breakdance\Render\generateCacheForPost( $post_id );
						return array( 'success' => true, 'elements' => count( $tree['root']['children'] ) );
					},
					'permission_callback' => 'kuduy_fake_bd_can',
					'meta'                => $meta( false ),
				)
			);
			$create               = $none;
			$create['properties'] = array( 'title' => array( 'type' => 'string' ) );
			$create['required']   = array( 'title' );
			wp_register_ability(
				"$ns/create-post",
				array(
					'label'               => 'Create Post',
					'description'         => 'Creates a draft page for the builder.',
					'category'            => $ns,
					'input_schema'        => $create,
					'execute_callback'    => static fn( $input ) => array(
						'post_id' => wp_insert_post(
							array(
								'post_type'   => 'page',
								'post_status' => 'draft',
								'post_title'  => (string) $input['title'],
							)
						),
					),
					'permission_callback' => 'kuduy_fake_bd_can',
					'meta'                => $meta( false ),
				)
			);
			foreach ( array( 'set-global-settings', 'report-bug' ) as $slug ) {
				wp_register_ability(
					"$ns/$slug",
					array(
						'label'               => $slug,
						'description'         => 'Stand-in site-wide write.',
						'category'            => $ns,
						'input_schema'        => array( 'type' => 'object' ),
						'execute_callback'    => static function () use ( $slug ) {
							update_option( 'kuduy_fake_bd_ran_' . $slug, 1 );
							return array( 'success' => true );
						},
						'permission_callback' => 'kuduy_fake_bd_can',
						'meta'                => $meta( false ),
					)
				);
			}
		}
	);
}
