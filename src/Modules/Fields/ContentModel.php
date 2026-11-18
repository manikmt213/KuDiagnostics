<?php
/**
 * Tool: kuduy-content-model.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Fields;

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- "meta_key" is a key of the rows this tool returns, not a query argument.

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Modules\Types\ContentTypes;

/**
 * The site's content model across plugins (phase E6, D58): every post type and taxonomy WordPress has registered,
 * with the definition that registers it where one of the supported plugins keeps it (WordPress core, KuDiagnostics'
 * own content types, ACF post types and taxonomies, Pods; otherwise "plugin or theme"), and per post type the custom
 * fields ACF, Meta Box, Pods and ACPT attach to it, by meta key. Clashes are reported: a post type or taxonomy
 * defined by two of these sources, and one meta key used by fields from two sources on the same post type (each
 * plugin would read and write the other's value). Read only; every plugin is read through its own API.
 */
final class ContentModel implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-content-model';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'Content model across plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Shows the site\'s content model in one place: each post type and taxonomy with where it is defined (WordPress core, KuDiagnostics, ACF, Pods, or another plugin or theme), and the custom fields ACF, Meta Box, Pods and ACPT attach to each post type (meta key, label, type, source). Lists clashes: a post type or taxonomy defined twice, and one meta key used by fields from two plugins on the same post type. Filter with post_type. Read only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_types' => array( 'type' => 'array' ),
				'taxonomies' => array( 'type' => 'array' ),
				'clashes'    => array( 'type' => 'array' ),
				'sources'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'post_types', 'taxonomies', 'clashes', 'sources' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Definitions per source: kind (post_type | taxonomy) => key => list of sources.
	 *
	 * @return array{post_type: array<string, list<string>>, taxonomy: array<string, list<string>>}
	 */
	private static function definitions(): array {
		$defs   = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);
		$stored = ContentTypes::stored();
		foreach ( array_keys( $stored['post_types'] ) as $key ) {
			$defs['post_type'][ (string) $key ][] = 'kuduy-mcp';
		}
		foreach ( array_keys( $stored['taxonomies'] ) as $key ) {
			$defs['taxonomy'][ (string) $key ][] = 'kuduy-mcp';
		}
		if ( function_exists( 'acf_get_internal_post_type_posts' ) ) {
			foreach ( array(
				'acf-post-type' => array( 'post_type', 'post_type' ),
				'acf-taxonomy'  => array( 'taxonomy', 'taxonomy' ),
			) as $kind => $map ) {
				foreach ( (array) acf_get_internal_post_type_posts( $kind ) as $item ) {
					if ( ! empty( $item[ $map[1] ] ) ) {
						$defs[ $map[0] ][ (string) $item[ $map[1] ] ][] = 'acf';
					}
				}
			}
		}
		if ( function_exists( 'pods_api' ) ) {
			foreach ( (array) pods_api()->load_pods( array() ) as $pod ) {
				$type = (string) $pod->get_arg( 'type' );
				if ( in_array( $type, array( 'post_type', 'taxonomy' ), true ) && '' === (string) $pod->get_arg( 'object' ) && empty( $pod->get_arg( 'extend' ) ) ) {
					$defs[ $type ][ (string) $pod->get_arg( 'name' ) ][] = 'pods';
				}
			}
		}
		return $defs;
	}

	/**
	 * Fields per post type: post type => list of field rows.
	 *
	 * @param list<string> $post_types Post types.
	 * @return array<string, list<array<string, string>>>
	 */
	private static function fields( array $post_types ): array {
		$out = array_fill_keys( $post_types, array() );
		foreach ( $post_types as $type ) {
			if ( function_exists( 'acf_get_field_groups' ) ) {
				foreach ( (array) acf_get_field_groups( array( 'post_type' => $type ) ) as $group ) {
					foreach ( (array) acf_get_fields( $group ) as $field ) {
						$out[ $type ][] = array(
							'meta_key' => (string) $field['name'],
							'label'    => (string) $field['label'],
							'type'     => (string) $field['type'],
							'source'   => 'acf',
							'group'    => (string) $group['title'],
						);
					}
				}
			}
			if ( function_exists( 'rwmb_get_object_fields' ) ) {
				foreach ( (array) rwmb_get_object_fields( $type, 'post' ) as $id => $field ) {
					$out[ $type ][] = array(
						'meta_key' => (string) $id,
						'label'    => (string) ( $field['name'] ?? '' ),
						'type'     => (string) ( $field['type'] ?? '' ),
						'source'   => 'meta-box',
						'group'    => '',
					);
				}
			}
		}//end foreach
		if ( function_exists( 'pods_api' ) ) {
			foreach ( (array) pods_api()->load_pods( array() ) as $pod ) {
				$name = (string) $pod->get_arg( 'name' );
				if ( 'post_type' !== (string) $pod->get_arg( 'type' ) || ! isset( $out[ $name ] ) || 'meta' !== (string) $pod->get_arg( 'storage' ) ) {
					continue;
				}
				$fields = pods_api()->load_fields(
					array(
						'pod_id'       => (int) $pod->get_arg( 'id' ),
						'bypass_cache' => true,
					)
				);
				foreach ( (array) $fields as $field ) {
					$out[ $name ][] = array(
						'meta_key' => (string) $field->get_arg( 'name' ),
						'label'    => (string) $field->get_label(),
						'type'     => (string) $field->get_arg( 'type' ),
						'source'   => 'pods',
						'group'    => '',
					);
				}
			}//end foreach
		}//end if
		if ( function_exists( 'get_acpt_meta_group_objects' ) ) {
			foreach ( (array) get_acpt_meta_group_objects() as $group ) {
				$group = json_decode( (string) wp_json_encode( $group ), true );
				foreach ( (array) ( $group['belongs'] ?? array() ) as $belong ) {
					$find = (string) ( $belong['find'] ?? '' );
					if ( ! isset( $out[ $find ] ) ) {
						continue;
					}
					foreach ( (array) ( $group['boxes'] ?? array() ) as $box ) {
						foreach ( (array) ( $box['fields'] ?? array() ) as $field ) {
							// ACPT stores a field's value under its db_name, "{box}_{field}" (MetaFieldModel::getDbName()).
							$out[ $find ][] = array(
								'meta_key' => (string) ( $field['db_name'] ?? ( ( $box['name'] ?? '' ) . '_' . ( $field['name'] ?? '' ) ) ),
								'label'    => (string) ( $field['label'] ?? '' ),
								'type'     => (string) ( $field['type'] ?? '' ),
								'source'   => 'acpt',
								'group'    => (string) ( $group['label'] ?? '' ),
							);
						}
					}
				}
			}//end foreach
		}//end if
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		$defs    = self::definitions();
		$types   = get_post_types( array(), 'objects' );
		$wanted  = isset( $input['post_type'] ) ? array( (string) $input['post_type'] ) : array_keys( $types );
		$wanted  = array_values( array_filter( $wanted, static fn( $t ) => isset( $types[ $t ] ) && ( $types[ $t ]->show_ui || $types[ $t ]->public ) ) );
		$fields  = self::fields( $wanted );
		$clashes = array();
		$rows    = array();
		foreach ( $wanted as $key ) {
			$object  = $types[ $key ];
			$sources = $defs['post_type'][ $key ] ?? array();
			if ( count( array_unique( $sources ) ) > 1 ) {
				$clashes[] = array(
					'kind'    => 'post_type',
					'key'     => $key,
					'sources' => array_values( array_unique( $sources ) ),
				);
			}
			$by_key = array();
			foreach ( $fields[ $key ] as $field ) {
				$by_key[ $field['meta_key'] ][] = $field['source'];
			}
			foreach ( $by_key as $meta => $from ) {
				if ( count( array_unique( $from ) ) > 1 ) {
					$clashes[] = array(
						'kind'      => 'meta_key',
						'post_type' => $key,
						'key'       => (string) $meta,
						'sources'   => array_values( array_unique( $from ) ),
					);
				}
			}
			$rows[] = array(
				'post_type'  => $key,
				'label'      => (string) $object->label,
				'defined_by' => $object->_builtin ? 'core' : ( $sources ? implode( ', ', array_unique( $sources ) ) : 'plugin or theme' ),
				'taxonomies' => array_values( get_object_taxonomies( $key ) ),
				'fields'     => $fields[ $key ],
			);
		}//end foreach
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $key => $tax ) {
			$sources = $defs['taxonomy'][ $key ] ?? array();
			if ( count( array_unique( $sources ) ) > 1 ) {
				$clashes[] = array(
					'kind'    => 'taxonomy',
					'key'     => (string) $key,
					'sources' => array_values( array_unique( $sources ) ),
				);
			}
			$taxonomies[] = array(
				'taxonomy'   => (string) $key,
				'label'      => (string) $tax->label,
				'defined_by' => $tax->_builtin ? 'core' : ( $sources ? implode( ', ', array_unique( $sources ) ) : 'plugin or theme' ),
				'post_types' => array_values( (array) $tax->object_type ),
			);
		}
		$present = array_keys(
			array_filter(
				array(
					'acf'      => function_exists( 'acf_get_field_groups' ),
					'meta-box' => function_exists( 'rwmb_get_object_fields' ),
					'pods'     => function_exists( 'pods_api' ),
					'acpt'     => function_exists( 'get_acpt_meta_group_objects' ),
				)
			)
		);
		return array(
			'post_types' => $rows,
			'taxonomies' => $taxonomies,
			'clashes'    => $clashes,
			'sources'    => $present,
		);
	}
}
