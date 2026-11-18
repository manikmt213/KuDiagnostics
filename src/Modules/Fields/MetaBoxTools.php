<?php
/**
 * Tools: kuduy-meta-box-boxes, kuduy-meta-box-get, kuduy-meta-box-update.
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
 * Meta Box (phase E2, D58; read on Meta Box 5.15.5). Meta boxes are registered in code with the free plugin (the
 * builder is the paid MB Builder), so their definitions are read from Meta Box's registry
 * (`rwmb_get_registry( 'meta_box' )`, `rwmb_get_object_fields()`). Values go through the abilities Meta Box registers
 * itself (src/Abilities.php): meta-box/get-field-value, update-field-value and delete-field-value, which accept only
 * fields Meta Box knows for that object, check the object's capability (edit_post, edit_term, edit_user) and
 * normalize values the way its own forms save them. Password fields are never returned. Undo puts back the stored
 * meta rows of every field the call changed.
 */
final class MetaBoxTools implements Tool, Snapshottable {

	/**
	 * Object types the free plugin's fields attach to.
	 */
	private const OBJECTS = array( 'post', 'term', 'user' );

	/**
	 * Constructor.
	 *
	 * @param string $mode boxes | get | update.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-meta-box-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'boxes' => 'Meta Box: meta boxes and fields',
			'get'   => 'Meta Box: field values',
			default => 'Meta Box: change field values',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'boxes' => 'Lists the meta boxes Meta Box has registered (in code; the free plugin has no builder) with where they show (post types, taxonomies, users) and their fields: id, name, type, required, clone/multiple, options. Filter by post_type or taxonomy. Read only.',
			'get'   => 'Reads the Meta Box field values of one object (object_type post, term or user + object_id): every Meta Box field registered for it, or the listed field ids. Values as Meta Box returns them (rwmb_get_value). Password fields are never shown.',
			default => 'Changes Meta Box field values of one object (object_type post, term or user + object_id): values as {"field_id": value}; null deletes the stored value. Saved through Meta Box\'s own update ability, which accepts only fields registered for that object and normalizes values as its forms do. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$object = array(
			'object_type' => array(
				'type'    => 'string',
				'enum'    => self::OBJECTS,
				'default' => 'post',
			),
			'object_id'   => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		return match ( $this->mode ) {
			'boxes' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'      => 'string',
						'maxLength' => 40,
					),
					'taxonomy'  => array(
						'type'      => 'string',
						'maxLength' => 40,
					),
				),
				'additionalProperties' => false,
			),
			'get'   => array(
				'type'                 => 'object',
				'properties'           => $object + array(
					'fields' => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
				),
				'required'             => array( 'object_id' ),
				'additionalProperties' => false,
			),
			default => array(
				'type'                 => 'object',
				'properties'           => $object + array(
					'values' => array(
						'type'          => 'object',
						'minProperties' => 1,
						'maxProperties' => 50,
					),
				),
				'required'             => array( 'object_id', 'values' ),
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
				'boxes'        => array( 'type' => 'array' ),
				'values'       => array( 'type' => 'object' ),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'update' !== $this->mode,
			'destructiveHint' => 'update' === $this->mode,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'boxes' === $this->mode ) {
			return 'edit_posts';
		}
		$id = (int) ( $input['object_id'] ?? 0 );
		return match ( (string) ( $input['object_type'] ?? 'post' ) ) {
			'term'  => array( 'edit_term', $id ),
			'user'  => array( 'edit_user', $id ),
			default => $id > 0 ? array( 'edit_post', $id ) : 'edit_posts',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! function_exists( 'rwmb_get_registry' ) || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return 'Meta Box is not active on this site.';
		}
		if ( 'boxes' !== $this->mode && ( ! function_exists( 'wp_get_ability' ) || null === self::ability( 'get-field-value' ) ) ) {
			return 'This Meta Box version does not register its field value abilities (Meta Box 5.11 or later with WordPress\' Abilities API).';
		}
		return true;
	}

	/**
	 * One of Meta Box's own abilities (the registry starts on first use).
	 *
	 * @param string $name Ability name without the meta-box/ prefix.
	 */
	private static function ability( string $name ): ?\WP_Ability {
		$ability = wp_get_ability( 'meta-box/' . $name );
		return $ability instanceof \WP_Ability ? $ability : null;
	}

	/**
	 * Fields Meta Box registers for an object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return array<string, array<string, mixed>>
	 * @throws ToolError When the object does not exist.
	 */
	private static function fields_of( string $type, int $id ): array {
		$exists = match ( $type ) {
			'term'  => get_term( $id ) instanceof \WP_Term,
			'user'  => get_userdata( $id ) instanceof \WP_User,
			default => get_post( $id ) instanceof \WP_Post,
		};
		if ( ! $exists ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No ' . $type . ' ' . $id . '.' );
		}
		return (array) rwmb_get_object_fields( $id, $type );
	}

	/**
	 * A field as the tools show it.
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @return array<string, mixed>
	 */
	private static function field( array $field ): array {
		$out = array(
			'id'       => (string) ( $field['id'] ?? '' ),
			'name'     => (string) ( $field['name'] ?? '' ),
			'type'     => (string) ( $field['type'] ?? '' ),
			'required' => ! empty( $field['required'] ),
			'clone'    => ! empty( $field['clone'] ),
			'multiple' => ! empty( $field['multiple'] ),
		);
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			$out['options'] = array_map( 'strval', $field['options'] );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'update' !== $this->mode ) {
			return null;
		}
		$type = (string) ( $input['object_type'] ?? 'post' );
		$id   = (int) $input['object_id'];
		$rows = array();
		foreach ( array_keys( (array) $input['values'] ) as $field ) {
			$rows[ (string) $field ] = get_metadata( $type, $id, (string) $field, false );
		}
		return array(
			'object_type' => 'meta-box-' . $type,
			'object_id'   => (string) $id,
			'payload'     => array(
				'type' => $type,
				'id'   => $id,
				'rows' => $rows,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$type = (string) $payload['type'];
		$id   = (int) $payload['id'];
		foreach ( (array) $payload['rows'] as $key => $values ) {
			delete_metadata( $type, $id, (string) $key );
			foreach ( (array) $values as $value ) {
				add_metadata( $type, $id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an object or field is unknown or Meta Box refuses.
	 */
	public function execute( array $input ): array {
		if ( 'boxes' === $this->mode ) {
			return array( 'boxes' => self::boxes( $input ) );
		}
		$type   = (string) ( $input['object_type'] ?? 'post' );
		$id     = (int) $input['object_id'];
		$fields = self::fields_of( $type, $id );
		if ( 'get' === $this->mode ) {
			$wanted = isset( $input['fields'] ) ? array_map( 'strval', (array) $input['fields'] ) : array_keys( $fields );
			$values = array();
			foreach ( $wanted as $key ) {
				if ( ! isset( $fields[ $key ] ) ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Meta Box has no field "' . $key . '" for this ' . $type . ' (see kuduy-meta-box-boxes).' );
				}
				if ( 'password' === ( $fields[ $key ]['type'] ?? '' ) ) {
					$values[ $key ] = '[hidden]';
					continue;
				}
				$values[ $key ] = self::run( 'get-field-value', $type, $id, $key )['value'] ?? null;
			}
			return array( 'values' => (object) $values );
		}
		foreach ( array_keys( (array) $input['values'] ) as $key ) {
			if ( ! isset( $fields[ (string) $key ] ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Meta Box has no field "' . $key . '" for this ' . $type . ' (see kuduy-meta-box-boxes). Nothing was changed.' );
			}
		}
		AuditLog::target( $type, (string) $id );
		$changed = array();
		foreach ( (array) $input['values'] as $key => $value ) {
			if ( null === $value ) {
				self::run( 'delete-field-value', $type, $id, (string) $key );
			} else {
				$result = self::run( 'update-field-value', $type, $id, (string) $key, $value );
				if ( empty( $result['success'] ) ) {
					throw ToolError::of( ToolError::OPERATION_FAILED, 'Meta Box did not save field ' . $key . '.' );
				}
			}
			$changed[] = (string) $key;
		}
		return array( 'changed' => $changed );
	}

	/**
	 * Run one of Meta Box's value abilities.
	 *
	 * @param string $name  Ability.
	 * @param string $type  Object type.
	 * @param int    $id    Object id.
	 * @param string $field Field id.
	 * @param mixed  $value Value (update only).
	 * @return array<string, mixed>
	 * @throws ToolError When Meta Box refuses.
	 */
	private static function run( string $name, string $type, int $id, string $field, mixed $value = null ): array {
		$ability = self::ability( $name );
		if ( null === $ability ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Meta Box\'s ' . $name . ' ability is not registered.' );
		}
		$input = array(
			'field_id'    => $field,
			'object_id'   => $id,
			'object_type' => $type,
		);
		if ( 'update-field-value' === $name ) {
			$input['value'] = $value;
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Meta Box refused field ' . $field . ': ' . $result->get_error_message() );
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Registered meta boxes.
	 *
	 * @param array<string, mixed> $input Filters.
	 * @return list<array<string, mixed>>
	 */
	private static function boxes( array $input ): array {
		$out = array();
		foreach ( (array) rwmb_get_registry( 'meta_box' )->all() as $box ) {
			$meta = isset( $box->meta_box ) ? (array) $box->meta_box : array();
			if ( ! $meta ) {
				continue;
			}
			$post_types = array_values( array_map( 'strval', (array) ( $meta['post_types'] ?? array() ) ) );
			$taxonomies = array_values( array_map( 'strval', (array) ( $meta['taxonomies'] ?? array() ) ) );
			if ( isset( $input['post_type'] ) && ! in_array( $input['post_type'], $post_types, true ) ) {
				continue;
			}
			if ( isset( $input['taxonomy'] ) && ! in_array( $input['taxonomy'], $taxonomies, true ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (string) ( $meta['id'] ?? '' ),
				'title'      => (string) ( $meta['title'] ?? '' ),
				'post_types' => $post_types,
				'taxonomies' => $taxonomies,
				'users'      => 'user' === ( $meta['type'] ?? '' ),
				'fields'     => array_map( array( self::class, 'field' ), array_values( (array) ( $meta['fields'] ?? array() ) ) ),
			);
		}//end foreach
		return $out;
	}
}
