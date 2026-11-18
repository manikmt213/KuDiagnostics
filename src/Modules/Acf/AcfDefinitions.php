<?php
/**
 * Tools: kuduy-acf-field-group-save, kuduy-acf-field-group-trash, kuduy-acf-post-type-save, kuduy-acf-taxonomy-save.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Acf;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * ACF definitions (phase E1, D58) through ACF's own import path (read on ACF 6.8.11: includes/post-types/
 * class-acf-field-group.php import_post(), and src/AI/Abilities, whose create-field-group and create-post-type
 * abilities call the same functions). acf_import_field_group() saves a group with exactly the fields it is given and
 * deletes the others, so this tool starts from the group as ACF exports it and applies the requested changes, never
 * a partial list. A field is not removed while stored values point at it. Groups registered in PHP, and local JSON
 * groups not yet synced into the database, are refused: ACF itself does not edit them. ACF post types and taxonomies
 * (ACF 6.1+) go through acf_import_post_type() / acf_import_taxonomy(); a key that something else already registers
 * is refused. Changes snapshot first and are undone by importing the saved export; created items have no undo
 * (trash them).
 */
final class AcfDefinitions implements Tool, Snapshottable {

	/**
	 * Field properties every type takes.
	 */
	private const COMMON = array( 'label', 'name', 'instructions', 'required', 'wrapper' );

	/**
	 * Field settings this tool never writes.
	 */
	private const NEVER = array( 'key', 'ID', 'parent', 'type', 'sub_fields', 'layouts', 'conditional_logic', 'prefix', '_name', '_valid', 'menu_order' );

	/**
	 * Constructor.
	 *
	 * @param string $mode field-group-save | field-group-trash | post-type-save | taxonomy-save.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-acf-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'field-group-save'  => 'ACF: save a field group',
			'field-group-trash' => 'ACF: trash a field group',
			'post-type-save'    => 'ACF: save a post type',
			default             => 'ACF: save a taxonomy',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'field-group-save'  => 'Creates an ACF field group (no key; give title, location and add operations) or changes one (key from kuduy-acf-field-groups): title, description, active, position, location rules [[{"param":"post_type","operator":"==","value":"page"}]] (OR of AND groups), and field operations in order: add {"op":"add","type":"text","properties":{"label":"Subtitle","name":"subtitle"},"index":0}, update {"op":"update","field":"subtitle","properties":{"required":true}}, remove {"op":"remove","field":"subtitle"}, move {"op":"move","field":"subtitle","index":2}. Fields by key or name; properties as ACF names them for the type (label, name, instructions, required, choices, default_value, placeholder, …; see kuduy-acf-status for types). Saved through ACF\'s own import; fields not mentioned stay. A field is not removed while stored values use it. Groups registered in PHP or unsynced local JSON are refused. Snapshot first; undo with kuduy-snapshot-restore.',
			'field-group-trash' => 'Moves an ACF field group to the trash (ACF\'s own trash: the group stops showing and its values stay in the database). Undo with kuduy-snapshot-restore.',
			'post-type-save'    => 'Creates or changes a post type that ACF registers (ACF 6.1+, ACF > Post Types): post_type key, singular and plural labels, description, public, hierarchical, supports, show_in_rest, has_archive, menu_icon (dashicon name), taxonomies, active. A key another plugin or theme registers is refused. Saved through ACF\'s own import; changes are undone with kuduy-snapshot-restore.',
			default             => 'Creates or changes a taxonomy that ACF registers (ACF 6.1+, ACF > Taxonomies): taxonomy key, singular and plural labels, description, post_types, public, hierarchical, show_in_rest, active. A key another plugin or theme registers is refused. Saved through ACF\'s own import; changes are undone with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$bool  = array( 'type' => 'boolean' );
		$text  = array(
			'type'      => 'string',
			'maxLength' => 500,
		);
		$key   = array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9_-]{1,32}$',
		);
		$names = array(
			'type'     => 'array',
			'maxItems' => 50,
			'items'    => $key,
		);
		return match ( $this->mode ) {
			'field-group-save'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'key'         => array(
						'type'    => 'string',
						'pattern' => '^group_[A-Za-z0-9_]+$',
					),
					'title'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'description' => $text,
					'active'      => $bool,
					'position'    => array(
						'type' => 'string',
						'enum' => array( 'normal', 'side', 'acf_after_title' ),
					),
					'location'    => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items'    => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 20,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'param'    => array(
										'type'      => 'string',
										'maxLength' => 60,
									),
									'operator' => array(
										'type' => 'string',
										'enum' => array( '==', '!=' ),
									),
									'value'    => array(
										'type'      => 'string',
										'maxLength' => 200,
									),
								),
								'required'             => array( 'param', 'operator', 'value' ),
								'additionalProperties' => false,
							),
						),
					),
					'operations'  => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'op'         => array(
									'type' => 'string',
									'enum' => array( 'add', 'update', 'remove', 'move' ),
								),
								'field'      => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 100,
								),
								'type'       => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 60,
								),
								'properties' => array( 'type' => 'object' ),
								'index'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
							),
							'required'             => array( 'op' ),
							'additionalProperties' => false,
						),
					),
				),
				'additionalProperties' => false,
			),
			'field-group-trash' => array(
				'type'                 => 'object',
				'properties'           => array(
					'key' => array(
						'type'    => 'string',
						'pattern' => '^group_[A-Za-z0-9_]+$',
					),
				),
				'required'             => array( 'key' ),
				'additionalProperties' => false,
			),
			'post-type-save'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type'    => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,20}$',
					),
					'singular'     => $text,
					'plural'       => $text,
					'description'  => $text,
					'public'       => $bool,
					'hierarchical' => $bool,
					'supports'     => $names,
					'show_in_rest' => $bool,
					'has_archive'  => $bool,
					'menu_icon'    => array(
						'type'    => 'string',
						'pattern' => '^dashicons-[a-z0-9-]+$',
					),
					'taxonomies'   => $names,
					'active'       => $bool,
				),
				'required'             => array( 'post_type' ),
				'additionalProperties' => false,
			),
			default             => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy'     => $key,
					'singular'     => $text,
					'plural'       => $text,
					'description'  => $text,
					'post_types'   => $names,
					'public'       => $bool,
					'hierarchical' => $bool,
					'show_in_rest' => $bool,
					'active'       => $bool,
				),
				'required'             => array( 'taxonomy' ),
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
				'created'      => array( 'type' => 'boolean' ),
				'field_group'  => array( 'type' => 'object' ),
				'item'         => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'created' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// ACF's own screens check its "capability" setting (manage_options by default).
		$cap = function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'capability' ) : '';
		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$ok = Acf::available();
		if ( true !== $ok ) {
			return $ok;
		}
		if ( in_array( $this->mode, array( 'field-group-save', 'field-group-trash' ), true ) ) {
			return function_exists( 'acf_import_field_group' ) && function_exists( 'acf_trash_field_group' ) ? true : 'This ACF version has no field group import (ACF 6.1 or later is needed).';
		}
		list( $fn, $setting ) = 'post-type-save' === $this->mode ? array( 'acf_import_post_type', 'enable_post_types' ) : array( 'acf_import_taxonomy', 'enable_taxonomies' );
		if ( ! function_exists( $fn ) ) {
			return 'This ACF version does not register post types and taxonomies (ACF 6.1 or later is needed).';
		}
		return false === acf_get_setting( $setting ) ? 'ACF\'s post types and taxonomies are switched off on this site (ACF setting ' . $setting . ').' : true;
	}

	/**
	 * A field group stored in the database, as ACF exports it, with its fields.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such group or ACF does not edit it.
	 */
	private static function group( string $key ): array {
		self::fresh();
		$group = acf_get_field_group( $key );
		if ( ! is_array( $group ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'ACF has no field group ' . $key . ' (see kuduy-acf-field-groups).' );
		}
		if ( empty( $group['ID'] ) ) {
			$where = 'json' === ( $group['local'] ?? '' ) ? 'a local JSON file that is not synced into the database (sync it in ACF > Field Groups first)' : 'PHP code';
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'ACF field group ' . $key . ' comes from ' . $where . '; ACF does not edit it on its screens, so neither does this tool. Nothing was changed.' );
		}
		$group['fields'] = acf_get_fields( $group );
		return acf_prepare_field_group_for_export( $group );
	}

	/**
	 * Drop ACF's request caches of field groups and fields (its "fields" and "field-groups" stores and the "acf"
	 * object cache group), so a read or an import sees the database: within one PHP process a change and its undo
	 * would otherwise import against the fields ACF loaded first, and stale field ids are not saved.
	 */
	private static function fresh(): void {
		foreach ( array( 'fields', 'field-groups' ) as $name ) {
			$store = acf_get_store( $name );
			if ( is_object( $store ) && method_exists( $store, 'reset' ) ) {
				$store->reset();
			}
		}
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'acf' );
		}
	}

	/**
	 * How many stored values point at a field (ACF keeps "_name" => field key next to each value).
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function values_of( array $field ): int {
		global $wpdb;
		$meta  = '_' . (string) $field['name'];
		$key   = (string) $field['key'];
		$count = 0;
		foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ) as $table ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s AND meta_value = %s", $meta, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- read-only count of ACF's reference rows; table names from $wpdb.
		}
		return $count;
	}

	/**
	 * Properties a field type takes.
	 *
	 * @param string $type Field type.
	 * @return list<string>
	 * @throws ToolError When ACF has no such type.
	 */
	private static function properties_of( string $type ): array {
		$object = acf_get_field_type( $type );
		if ( ! is_object( $object ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'ACF has no field type "' . $type . '" on this site (see kuduy-acf-status).' );
		}
		$defaults = isset( $object->defaults ) && is_array( $object->defaults ) ? array_keys( $object->defaults ) : array();
		return array_values( array_diff( array_unique( array_merge( self::COMMON, $defaults, array( 'default_value' ) ) ), self::NEVER ) );
	}

	/**
	 * Check and clean field properties.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @param string               $where      Operation label.
	 * @return array<string, mixed>
	 * @throws ToolError When a property does not belong to the type or has a bad value.
	 */
	private static function clean( string $type, array $properties, string $where ): array {
		$allowed = self::properties_of( $type );
		$out     = array();
		foreach ( $properties as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': a ' . $type . ' field does not take ' . $name . ' (it takes ' . implode( ', ', $allowed ) . '). Nothing was changed.' );
			}
			if ( 'name' === $name && ! preg_match( '/^[a-z0-9_]{1,64}$/', (string) $value ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': name takes lower-case letters, digits and _. Nothing was changed.' );
			}
			if ( is_array( $value ) ) {
				$value = map_deep( $value, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
			} elseif ( is_string( $value ) ) {
				$value = 'instructions' === $name ? wp_kses_post( $value ) : sanitize_text_field( $value );
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': ' . $name . ' has the wrong kind of value. Nothing was changed.' );
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * Location rules, checked against the rule types ACF knows.
	 *
	 * @param array<int, mixed> $location Rules.
	 * @return list<list<array{param:string, operator:string, value:string}>>
	 * @throws ToolError When a rule type is unknown.
	 */
	private static function location( array $location ): array {
		$known = array();
		foreach ( (array) acf_get_location_rule_types() as $types ) {
			$known = array_merge( $known, array_keys( (array) $types ) );
		}
		$out = array();
		foreach ( $location as $group ) {
			$rules = array();
			foreach ( (array) $group as $rule ) {
				$param = (string) $rule['param'];
				if ( ! in_array( $param, $known, true ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'ACF has no location rule "' . $param . '" (it has ' . implode( ', ', $known ) . '). Nothing was changed.' );
				}
				$rules[] = array(
					'param'    => $param,
					'operator' => (string) $rule['operator'],
					'value'    => sanitize_text_field( (string) $rule['value'] ),
				);
			}
			$out[] = $rules;
		}
		return $out;
	}

	/**
	 * Position of a top-level field by key or name.
	 *
	 * @param list<array<string, mixed>> $fields Fields.
	 * @param string                     $ref    Key or name.
	 * @param string                     $where  Operation label.
	 * @throws ToolError When there is no such field.
	 */
	private static function find( array $fields, string $ref, string $where ): int {
		foreach ( $fields as $n => $field ) {
			if ( ( $field['key'] ?? '' ) === $ref || ( $field['name'] ?? '' ) === $ref ) {
				return (int) $n;
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $where . ': the group has no field "' . $ref . '" at that point. Nothing was changed.' );
	}

	/**
	 * The group after the requested changes (nothing saved).
	 *
	 * @param array<string, mixed> $group Exported group (empty for a new one).
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When a change is refused.
	 */
	private static function changed( array $group, array $input ): array {
		foreach ( array( 'title', 'description', 'position' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$group[ $key ] = 'description' === $key ? sanitize_textarea_field( (string) $input[ $key ] ) : sanitize_text_field( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['active'] ) ) {
			$group['active'] = (bool) $input['active'];
		}
		if ( isset( $input['location'] ) ) {
			$group['location'] = self::location( (array) $input['location'] );
		}
		$fields = array_values( (array) ( $group['fields'] ?? array() ) );
		foreach ( array_values( (array) ( $input['operations'] ?? array() ) ) as $n => $op ) {
			$where = 'Operation ' . ( $n + 1 ) . ' (' . $op['op'] . ')';
			if ( 'add' === $op['op'] ) {
				$type       = (string) ( $op['type'] ?? '' );
				$properties = self::clean( $type, (array) ( $op['properties'] ?? array() ), $where );
				if ( empty( $properties['label'] ) ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, $where . ': a new field needs properties.label. Nothing was changed.' );
				}
				$name = (string) ( $properties['name'] ?? acf_slugify( (string) $properties['label'], '_' ) );
				foreach ( $fields as $other ) {
					if ( ( $other['name'] ?? '' ) === $name ) {
						throw ToolError::of( ToolError::DUPLICATE_RESOURCE, $where . ': the group already has a field named "' . $name . '". Nothing was changed.' );
					}
				}
				$field = array_merge(
					$properties,
					array(
						'key'  => 'field_' . str_replace( '.', '', uniqid( '', true ) ),
						'type' => $type,
						'name' => $name,
					)
				);
				array_splice( $fields, min( (int) ( $op['index'] ?? count( $fields ) ), count( $fields ) ), 0, array( $field ) );
				continue;
			}//end if
			$at = self::find( $fields, (string) ( $op['field'] ?? '' ), $where );
			if ( 'update' === $op['op'] ) {
				$fields[ $at ] = array_merge( $fields[ $at ], self::clean( (string) $fields[ $at ]['type'], (array) ( $op['properties'] ?? array() ), $where ) );
				continue;
			}
			$field = $fields[ $at ];
			array_splice( $fields, $at, 1 );
			if ( 'move' === $op['op'] ) {
				array_splice( $fields, min( (int) ( $op['index'] ?? 0 ), count( $fields ) ), 0, array( $field ) );
				continue;
			}
			$held = isset( $field['name'], $field['key'] ) ? self::values_of( $field ) : 0;
			if ( $held > 0 ) {
				throw ToolError::of( ToolError::CONFLICT, $where . ': ' . $held . ' stored ' . ( 1 === $held ? 'value uses' : 'values use' ) . ' field ' . $field['name'] . '; ACF would leave them without a field. Remove it in ACF itself if that is wanted. Nothing was changed.' );
			}
		}//end foreach
		$group['fields'] = $fields;
		return $group;
	}

	/**
	 * An ACF post type or taxonomy item by its key.
	 *
	 * @param string $kind   acf-post-type | acf-taxonomy.
	 * @param string $field  post_type | taxonomy.
	 * @param string $value  Key.
	 * @return array<string, mixed>|null
	 */
	private static function item( string $kind, string $field, string $value ): ?array {
		foreach ( (array) acf_get_internal_post_type_posts( $kind ) as $item ) {
			if ( ( $item[ $field ] ?? '' ) === $value ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'field-group-save' === $this->mode || 'field-group-trash' === $this->mode ) {
			if ( empty( $input['key'] ) ) {
				return null;
			}
			return array(
				'object_type' => 'acf-field-group',
				'object_id'   => (string) $input['key'],
				'payload'     => array(
					'group' => self::group( (string) $input['key'] ),
					'id'    => (int) acf_get_field_group( (string) $input['key'] )['ID'],
				),
			);
		}
		list( $kind, $field, $value ) = 'post-type-save' === $this->mode
			? array( 'acf-post-type', 'post_type', (string) $input['post_type'] )
			: array( 'acf-taxonomy', 'taxonomy', (string) $input['taxonomy'] );
		$item                         = self::item( $kind, $field, $value );
		return null === $item ? null : array(
			'object_type' => $kind,
			'object_id'   => $value,
			'payload'     => array( 'item' => $item ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the item cannot be put back.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['group'] ) ) {
			$group = (array) $payload['group'];
			// The group's post id is kept in the snapshot: ACF's getters skip trashed groups, and importing without the
			// id would create a second group with the same key.
			$id = (int) ( $payload['id'] ?? 0 );
			if ( $id && 'acf-field-group' !== get_post_type( $id ) ) {
				$id = 0;
			}
			if ( $id && 'trash' === get_post_status( $id ) ) {
				acf_untrash_field_group( $id );
			}
			$group['ID'] = $id;
			self::fresh();
			acf_import_field_group( $group );
			self::fresh();
			return;
		}
		$item = (array) ( $payload['item'] ?? array() );
		if ( isset( $item['post_type'] ) && ! isset( $item['taxonomy'] ) ) {
			acf_import_post_type( $item );
		} else {
			acf_import_taxonomy( $item );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a change is refused.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'field-group-save'  => $this->save_group( $input ),
			'field-group-trash' => $this->trash_group( $input ),
			default             => $this->save_item( $input ),
		};
	}

	/**
	 * Create or change a field group.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When a change is refused or ACF does not save.
	 */
	private function save_group( array $input ): array {
		$created = empty( $input['key'] );
		if ( $created ) {
			if ( empty( $input['title'] ) || empty( $input['location'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new field group needs title and location. Nothing was created.' );
			}
			$group = array(
				'key'    => 'group_' . str_replace( '.', '', uniqid( '', true ) ),
				'fields' => array(),
			);
		} else {
			$group = self::group( (string) $input['key'] );
			// The export leaves out the post id; without it ACF's import creates a second group with the same key.
			$group['ID'] = (int) acf_get_field_group( (string) $input['key'] )['ID'];
		}
		$group = self::changed( $group, $input );
		AuditLog::target( 'acf-field-group', (string) $group['key'] );
		self::fresh();
		$saved = acf_import_field_group( $group );
		self::fresh();
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'ACF did not save the field group.' );
		}
		return array(
			'created'     => $created,
			'field_group' => Acf::definition_group( (string) $saved['key'] ),
		);
	}

	/**
	 * Trash a field group.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When ACF does not trash it.
	 */
	private function trash_group( array $input ): array {
		$group = self::group( (string) $input['key'] );
		$id    = (int) acf_get_field_group( (string) $input['key'] )['ID'];
		AuditLog::target( 'acf-field-group', (string) $group['key'] );
		if ( ! acf_trash_field_group( $id ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'ACF did not trash the field group.' );
		}
		return array(
			'created'     => false,
			'field_group' => array(
				'key'    => (string) $group['key'],
				'title'  => (string) $group['title'],
				'status' => 'trash',
			),
		);
	}

	/**
	 * Create or change an ACF post type or taxonomy.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the key belongs to something else or ACF does not save.
	 */
	private function save_item( array $input ): array {
		$is_type = 'post-type-save' === $this->mode;
		$kind    = $is_type ? 'acf-post-type' : 'acf-taxonomy';
		$field   = $is_type ? 'post_type' : 'taxonomy';
		$value   = (string) $input[ $field ];
		$item    = self::item( $kind, $field, $value );
		$created = null === $item;
		if ( $created ) {
			if ( $is_type ? post_type_exists( $value ) : taxonomy_exists( $value ) ) {
				throw ToolError::of( ToolError::DUPLICATE_RESOURCE, ( $is_type ? 'A post type' : 'A taxonomy' ) . ' "' . $value . '" is already registered by WordPress, a plugin or the theme; ACF would clash with it. Nothing was created.' );
			}
			if ( empty( $input['singular'] ) || empty( $input['plural'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new ' . ( $is_type ? 'post type' : 'taxonomy' ) . ' needs singular and plural labels. Nothing was created.' );
			}
			$item = array(
				'key'    => uniqid( $is_type ? 'post_type_' : 'taxonomy_' ),
				$field   => $value,
				'labels' => (array) ( acf_get_internal_post_type_instance( $kind )->get_settings_array()['labels'] ?? array() ),
				'active' => 1,
			);
		}
		if ( isset( $input['plural'] ) ) {
			$item['title']          = sanitize_text_field( (string) $input['plural'] );
			$item['labels']['name'] = $item['title'];
		}
		if ( isset( $input['singular'] ) ) {
			$item['labels']['singular_name'] = sanitize_text_field( (string) $input['singular'] );
		}
		if ( isset( $input['description'] ) ) {
			$item['description'] = sanitize_text_field( (string) $input['description'] );
		}
		foreach ( array( 'public', 'hierarchical', 'show_in_rest', 'has_archive', 'active' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$item[ $flag ] = $input[ $flag ] ? 1 : 0;
			}
		}
		foreach ( array( 'supports', 'taxonomies', 'post_types' ) as $list ) {
			if ( isset( $input[ $list ] ) ) {
				$item[ 'post_types' === $list ? 'object_type' : $list ] = array_values( array_map( 'sanitize_key', (array) $input[ $list ] ) );
			}
		}
		if ( isset( $input['menu_icon'] ) ) {
			$item['menu_icon'] = array(
				'type'  => 'dashicons',
				'value' => sanitize_key( (string) $input['menu_icon'] ),
			);
		}
		AuditLog::target( $kind, $value );
		$saved = $is_type ? acf_import_post_type( $item ) : acf_import_taxonomy( $item );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'ACF did not save the ' . ( $is_type ? 'post type' : 'taxonomy' ) . '.' );
		}
		return array(
			'created' => $created,
			'item'    => array(
				'key'      => (string) $saved['key'],
				$field     => (string) $saved[ $field ],
				'singular' => (string) ( $saved['labels']['singular_name'] ?? '' ),
				'plural'   => (string) ( $saved['labels']['name'] ?? $saved['title'] ?? '' ),
				'active'   => ! empty( $saved['active'] ),
			),
		);
	}
}
