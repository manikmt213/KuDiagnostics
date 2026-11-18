<?php
/**
 * Tools: kuduy-acpt-groups, kuduy-acpt-group-create, kuduy-acpt-post-type-create, kuduy-acpt-taxonomy-create,
 * kuduy-acpt-values-get, kuduy-acpt-values-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Fields;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * ACPT (phase E4, D58; read on ACPT Lite 2.1.3, functions/_inc). Definitions through ACPT's public API: meta groups
 * with their boxes and fields through save_acpt_meta_group_definition() (public API level 1: validates the whole
 * definition first, creates only, then flush_acpt_caches() as the API asks), post types and taxonomies through
 * register_acpt_post_type() / register_acpt_taxonomy() (new keys only: ACPT's register functions also overwrite an
 * existing type, which this tool leaves to ACPT's screens). Field types and settings from
 * acpt_supported_field_types() / acpt_supported_field_settings(). Values through get_acpt_field() and
 * save_acpt_meta_field_value() for posts; undo puts the previous values back. Created definitions have no undo.
 */
final class AcptTools implements Tool, Snapshottable {

	/**
	 * Constructor.
	 *
	 * @param string $mode groups | group-create | post-type-create | taxonomy-create | values-get | values-update.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-acpt-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'ACPT: ' . str_replace( '-', ' ', $this->mode );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'groups'           => 'Lists ACPT meta groups with where they show, their boxes and fields (name, label, type, required), and the field types and settings this ACPT edition accepts.',
			'group-create'     => 'Creates an ACPT meta group through ACPT\'s public API: name, label, post_types it shows on, boxes [{"name":"details","label":"Details","fields":[{"name":"isbn","label":"ISBN","type":"Text","required":true}]}]. Field types and settings from kuduy-acpt-groups; select fields take choices [{"label","value"}]. ACPT checks the whole definition before anything is saved. Needs a site manager. A created group is removed in ACPT itself.',
			'post-type-create' => 'Registers a new custom post type in ACPT: post_name, singular_label, plural_label, icon (dashicon name without "dashicons-"), supports. Keys that exist are refused (change them in ACPT). Needs a site manager.',
			'taxonomy-create'  => 'Registers a new taxonomy in ACPT: slug, singular_label, plural_label, post_types. Keys that exist are refused (change them in ACPT). Needs a site manager.',
			'values-get'       => 'Reads ACPT field values of a post as {"box": {"field": value}}: the fields listed as {"box": ["field"]}, or every field of every group shown on the post.',
			default            => 'Changes ACPT field values of a post through save_acpt_meta_field_value(): values as {"box": {"field": value}}. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name = array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9_-]{1,64}$',
		);
		$text = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$list = array(
			'type'     => 'array',
			'maxItems' => 30,
			'items'    => $name,
		);
		return match ( $this->mode ) {
			'groups'           => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'group-create'     => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'       => $name,
					'label'      => $text,
					'post_types' => $list,
					'boxes'      => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items'    => array( 'type' => 'object' ),
					),
				),
				'required'             => array( 'name', 'label', 'post_types', 'boxes' ),
				'additionalProperties' => false,
			),
			'post-type-create' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_name'      => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,20}$',
					),
					'singular_label' => $text,
					'plural_label'   => $text,
					'icon'           => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]{1,60}$',
					),
					'supports'       => $list,
				),
				'required'             => array( 'post_name', 'singular_label', 'plural_label' ),
				'additionalProperties' => false,
			),
			'taxonomy-create'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'           => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,32}$',
					),
					'singular_label' => $text,
					'plural_label'   => $text,
					'post_types'     => $list,
				),
				'required'             => array( 'slug', 'singular_label', 'plural_label' ),
				'additionalProperties' => false,
			),
			'values-get'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'fields'  => array( 'type' => 'object' ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			default            => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'values'  => array(
						'type'          => 'object',
						'minProperties' => 1,
					),
				),
				'required'             => array( 'post_id', 'values' ),
				'additionalProperties' => false,
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'groups'       => array( 'type' => 'array' ),
				'field_types'  => array( 'type' => 'array' ),
				'settings'     => array( 'type' => 'array' ),
				'created'      => array( 'type' => 'object' ),
				'values'       => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		$read = in_array( $this->mode, array( 'groups', 'values-get' ), true );
		return array(
			'readOnlyHint'    => $read,
			'destructiveHint' => 'values-update' === $this->mode,
			'idempotentHint'  => $read || 'values-update' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'groups', 'values-get' => 'kuduy:read',
			'values-update'        => 'kuduy:write',
			default                => 'kuduy:admin',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		return match ( $this->mode ) {
			'groups'                    => 'edit_posts',
			'values-get', 'values-update' => isset( $input['post_id'] ) ? array( 'edit_post', (int) $input['post_id'] ) : 'edit_posts',
			default                     => 'manage_options',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! function_exists( 'save_acpt_meta_field_value' ) || ! function_exists( 'get_acpt_meta_group_objects' ) ) {
			return 'ACPT is not active on this site.';
		}
		if ( 'group-create' === $this->mode && ! function_exists( 'save_acpt_meta_group_definition' ) ) {
			return 'This ACPT version has no public API level 1 (save_acpt_meta_group_definition); create groups in ACPT itself.';
		}
		return true;
	}

	/**
	 * Groups as plain arrays.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function groups(): array {
		$out = array();
		foreach ( (array) get_acpt_meta_group_objects() as $group ) {
			$group = json_decode( (string) wp_json_encode( $group ), true );
			if ( ! is_array( $group ) ) {
				continue;
			}
			$boxes = array();
			foreach ( (array) ( $group['boxes'] ?? array() ) as $box ) {
				$fields = array();
				foreach ( (array) ( $box['fields'] ?? array() ) as $field ) {
					$fields[] = array(
						'name'     => (string) ( $field['name'] ?? '' ),
						'label'    => (string) ( $field['label'] ?? '' ),
						'type'     => (string) ( $field['type'] ?? '' ),
						'required' => ! empty( $field['isRequired'] ?? $field['required'] ?? false ),
					);
				}
				$boxes[] = array(
					'name'   => (string) ( $box['name'] ?? '' ),
					'label'  => (string) ( $box['label'] ?? '' ),
					'fields' => $fields,
				);
			}
			$out[] = array(
				'name'    => (string) ( $group['name'] ?? '' ),
				'label'   => (string) ( $group['label'] ?? '' ),
				'belongs' => array_values(
					array_map(
						static fn( $b ) => array(
							'belongs_to' => (string) ( $b['belongsTo'] ?? $b['belongs'] ?? '' ),
							'find'       => (string) ( $b['find'] ?? '' ),
						),
						(array) ( $group['belongs'] ?? array() )
					)
				),
				'boxes'   => $boxes,
			);
		}//end foreach
		return $out;
	}

	/**
	 * Box => field names of the groups shown on a post's type.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, list<string>>
	 */
	private static function fields_for( int $post_id ): array {
		$type = (string) get_post_type( $post_id );
		$out  = array();
		foreach ( self::groups() as $group ) {
			$shown = false;
			foreach ( $group['belongs'] as $belong ) {
				if ( $belong['find'] === $type ) {
					$shown = true;
				}
			}
			if ( ! $shown ) {
				continue;
			}
			foreach ( $group['boxes'] as $box ) {
				$out[ $box['name'] ] = array_merge( $out[ $box['name'] ] ?? array(), array_column( $box['fields'], 'name' ) );
			}
		}
		return $out;
	}

	/**
	 * One field value.
	 *
	 * @param int    $post_id Post id.
	 * @param string $box     Box name.
	 * @param string $field   Field name.
	 * @return mixed
	 */
	private static function value( int $post_id, string $box, string $field ): mixed {
		return get_acpt_field(
			array(
				'post_id'    => $post_id,
				'box_name'   => $box,
				'field_name' => $field,
			)
		);
	}

	/**
	 * Check box/field pairs against what ACPT shows on the post.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $pairs   Box => field => anything.
	 * @throws ToolError When a pair is not an ACPT field of the post.
	 */
	private static function check_pairs( int $post_id, array $pairs ): void {
		if ( ! get_post( $post_id ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No post ' . $post_id . '.' );
		}
		$known = self::fields_for( $post_id );
		foreach ( $pairs as $box => $fields ) {
			foreach ( array_keys( (array) $fields ) as $field ) {
				if ( ! in_array( (string) $field, $known[ (string) $box ] ?? array(), true ) ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'ACPT shows no field ' . $box . '/' . $field . ' on this post (see kuduy-acpt-groups). Nothing was changed.' );
				}
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'values-update' !== $this->mode ) {
			return null;
		}
		$id  = (int) $input['post_id'];
		$old = array();
		foreach ( (array) $input['values'] as $box => $fields ) {
			foreach ( array_keys( (array) $fields ) as $field ) {
				$old[ (string) $box ][ (string) $field ] = self::value( $id, (string) $box, (string) $field );
			}
		}
		return array(
			'object_type' => 'acpt_values',
			'object_id'   => (string) $id,
			'payload'     => array(
				'post_id' => $id,
				'values'  => $old,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When ACPT refuses.
	 */
	public function restore( array $payload ): void {
		self::save_values( (int) $payload['post_id'], (array) $payload['values'] );
	}

	/**
	 * Save values; null deletes.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $values  Box => field => value.
	 * @throws ToolError When ACPT refuses.
	 */
	private static function save_values( int $post_id, array $values ): void {
		foreach ( $values as $box => $fields ) {
			foreach ( (array) $fields as $field => $value ) {
				$args = array(
					'post_id'    => $post_id,
					'box_name'   => (string) $box,
					'field_name' => (string) $field,
				);
				if ( null === $value ) {
					delete_acpt_meta_field_value( $args );
					continue;
				}
				if ( ! save_acpt_meta_field_value( $args + array( 'value' => $value ) ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'ACPT did not save ' . $box . '/' . $field . ' (the value does not suit the field).' );
				}
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When ACPT refuses.
	 */
	public function execute( array $input ): array {
		switch ( $this->mode ) {
			case 'groups':
				return array(
					'groups'      => self::groups(),
					'field_types' => array_map( 'strval', acpt_supported_field_types() ),
					'settings'    => array_map( 'strval', acpt_supported_field_settings() ),
				);
			case 'group-create':
				$definition = array(
					'name'       => (string) $input['name'],
					'label'      => sanitize_text_field( (string) $input['label'] ),
					'belongs_to' => array_map( static fn( $t ) => array( 'post_type' => (string) $t ), (array) $input['post_types'] ),
					'boxes'      => map_deep( (array) $input['boxes'], static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v ),
				);
				AuditLog::target( 'acpt_group', (string) $input['name'] );
				$result = save_acpt_meta_group_definition( $definition );
				if ( is_wp_error( $result ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'ACPT refused the group: ' . $result->get_error_message() . ' ' . (string) wp_json_encode( $result->get_error_data() ) . ' Nothing was created.' );
				}
				flush_acpt_caches();
				return array( 'created' => (object) array( 'group' => (string) $input['name'] ) );
			case 'post-type-create':
			case 'taxonomy-create':
				$is_type = 'post-type-create' === $this->mode;
				$key     = (string) ( $is_type ? $input['post_name'] : $input['slug'] );
				if ( $is_type ? post_type_exists( $key ) : taxonomy_exists( $key ) ) {
					throw ToolError::of( ToolError::DUPLICATE_RESOURCE, ( $is_type ? 'A post type' : 'A taxonomy' ) . ' "' . $key . '" is already registered; change it in ACPT or the plugin that registers it. Nothing was created.' );
				}
				$args = array(
					'singular_label' => sanitize_text_field( (string) $input['singular_label'] ),
					'plural_label'   => sanitize_text_field( (string) $input['plural_label'] ),
				);
				AuditLog::target( $is_type ? 'acpt_post_type' : 'acpt_taxonomy', $key );
				if ( $is_type ) {
					$args += array(
						'post_name' => $key,
						'icon'      => (string) ( $input['icon'] ?? 'admin-post' ),
						'supports'  => array_values( (array) ( $input['supports'] ?? array( 'title', 'editor' ) ) ),
					);
					$ok    = register_acpt_post_type( $args );
				} else {
					$args += array(
						'slug'       => $key,
						'post_types' => array_values( (array) ( $input['post_types'] ?? array() ) ),
					);
					$ok    = register_acpt_taxonomy( $args );
				}
				if ( ! $ok ) {
					throw ToolError::of( ToolError::OPERATION_FAILED, 'ACPT did not register "' . $key . '".' );
				}
				return array( 'created' => (object) array( $is_type ? 'post_type' : 'taxonomy' => $key ) );
			case 'values-get':
				$id = (int) $input['post_id'];
				// {"box": ["field", …]} as given, or every field ACPT shows on the post.
				$lists  = isset( $input['fields'] ) ? (array) $input['fields'] : self::fields_for( $id );
				$wanted = array_map( static fn( $f ) => array_fill_keys( array_map( 'strval', (array) $f ), true ), $lists );
				self::check_pairs( $id, $wanted );
				$values = array();
				foreach ( $wanted as $box => $fields ) {
					foreach ( array_keys( (array) $fields ) as $field ) {
						$values[ (string) $box ][ (string) $field ] = self::value( $id, (string) $box, (string) $field );
					}
				}
				return array( 'values' => (object) $values );
			default:
				$id = (int) $input['post_id'];
				self::check_pairs( $id, (array) $input['values'] );
				AuditLog::target( 'post', (string) $id );
				self::save_values( $id, (array) $input['values'] );
				return array( 'values' => (object) (array) $input['values'] );
		}//end switch
	}
}
