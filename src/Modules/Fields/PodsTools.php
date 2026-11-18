<?php
/**
 * Tools: kuduy-pods-list, kuduy-pods-get, kuduy-pods-save, kuduy-pods-field-save, kuduy-pods-item-get,
 * kuduy-pods-item-save.
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
 * Pods (phase E3, D58; read on Pods 3.3.9.2). Definitions through Pods' own API: pods_api()->load_pods() /
 * load_pod(), add_pod() (create a post type, taxonomy or Advanced Content Type, or extend an existing post type,
 * taxonomy, users, media or comments, as the Add New Pod screen does), save_pod() and save_field(); field types from
 * PodsForm::field_types_list(). Items through pods( $pod, $id ): field( $name, null, true ) to read, save() and add()
 * to write, which run Pods' own validation and storage (meta or its own table). Definitions need Pods' admin
 * capability; items the object's own capability (posts, terms, users) or Pods' pods_edit_{pod} for Advanced Content
 * Types. Password fields are never returned. Changes snapshot first; created pods, fields and items have no undo.
 */
final class PodsTools implements Tool, Snapshottable {

	/**
	 * Pod types that can be created.
	 */
	private const CREATE = array( 'post_type', 'taxonomy', 'pod' );

	/**
	 * Pod types that extend something that exists.
	 */
	private const EXTEND = array( 'post_type', 'taxonomy', 'user', 'media', 'comment' );

	/**
	 * Field settings this tool sets.
	 */
	private const FIELD_KEYS = array( 'label', 'type', 'required', 'description', 'default_value', 'pick_object', 'pick_val', 'pick_custom', 'pick_format_type', 'pick_format_single', 'pick_format_multi', 'pick_limit', 'boolean_yes_label', 'boolean_no_label', 'number_decimals', 'text_max_length' );

	/**
	 * Constructor.
	 *
	 * @param string $mode list | get | save | field-save | item-get | item-save.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-pods-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'Pods: ' . str_replace( '-', ' ', $this->mode );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'       => 'Lists the Pods on this site: name, label, type (post_type, taxonomy, pod = Advanced Content Type, user, media, comment, settings), storage (meta or table), whether it extends an existing type, and its number of fields.',
			'get'        => 'One Pod with its fields: name, label, type, required, description, default value and, for relationships, what they point at (pick_object, pick_val).',
			'save'       => 'Creates a Pod (as Pods\' Add New screen: create a post_type, taxonomy or pod = Advanced Content Type with name and labels; or extend an existing post_type, taxonomy, user, media or comment by name) or changes the labels of one. Saved through Pods\' own API. Needs a site manager. A label change is undone with kuduy-snapshot-restore; a created Pod is removed in Pods itself.',
			'field-save' => 'Adds a field to a Pod or changes one: name, label, type (text, paragraph, number, email, website, phone, date, datetime, boolean, pick (relationship), file, wysiwyg, …; see kuduy-pods-get for existing ones), required, description, default_value, and for relationships pick_object / pick_val. Saved through Pods\' own save_field(). Needs a site manager. Changes are undone with kuduy-snapshot-restore; a created field is removed in Pods itself.',
			'item-get'   => 'Reads one item of a Pod (a post, term, user or Advanced Content Type row by id): every field\'s stored value, or the listed ones. Password fields are never shown.',
			default      => 'Changes an item of a Pod (id) or adds one (no id): values as {"field": value}, saved through Pods\' own save(), which validates them as Pods\' forms do. Changes are undone with kuduy-snapshot-restore; a new item is removed in Pods or WordPress itself.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name = array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9_]{1,50}$',
		);
		$text = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		return match ( $this->mode ) {
			'list'       => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'get'        => array(
				'type'                 => 'object',
				'properties'           => array( 'pod' => $name ),
				'required'             => array( 'pod' ),
				'additionalProperties' => false,
			),
			'save'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'pod'      => $name,
					'action'   => array(
						'type' => 'string',
						'enum' => array( 'create', 'extend', 'update' ),
					),
					'type'     => array(
						'type' => 'string',
						'enum' => array_values( array_unique( array_merge( self::CREATE, self::EXTEND ) ) ),
					),
					'singular' => $text,
					'plural'   => $text,
					'storage'  => array(
						'type' => 'string',
						'enum' => array( 'meta', 'table' ),
					),
				),
				'required'             => array( 'pod', 'action' ),
				'additionalProperties' => false,
			),
			'field-save' => array(
				'type'                 => 'object',
				'properties'           => array(
					'pod'      => $name,
					'field'    => $name,
					'settings' => array( 'type' => 'object' ),
				),
				'required'             => array( 'pod', 'field', 'settings' ),
				'additionalProperties' => false,
			),
			'item-get'   => array(
				'type'                 => 'object',
				'properties'           => array(
					'pod'    => $name,
					'id'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'fields' => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => $name,
					),
				),
				'required'             => array( 'pod', 'id' ),
				'additionalProperties' => false,
			),
			default      => array(
				'type'                 => 'object',
				'properties'           => array(
					'pod'    => $name,
					'id'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'values' => array(
						'type'          => 'object',
						'minProperties' => 1,
						'maxProperties' => 50,
					),
				),
				'required'             => array( 'pod', 'values' ),
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
				'pods'         => array( 'type' => 'array' ),
				'pod'          => array( 'type' => 'object' ),
				'field'        => array( 'type' => 'object' ),
				'id'           => array( 'type' => 'integer' ),
				'values'       => array( 'type' => 'object' ),
				'created'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		$read = in_array( $this->mode, array( 'list', 'get', 'item-get' ), true );
		return array(
			'readOnlyHint'    => $read,
			'destructiveHint' => ! $read,
			'idempotentHint'  => $read,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'save', 'field-save' => 'kuduy:admin',
			'item-save'          => 'kuduy:write',
			default              => 'kuduy:read',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * The item's own capability is checked in execute() once the Pod's type is known.
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return in_array( $this->mode, array( 'save', 'field-save' ), true ) ? 'manage_options' : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return function_exists( 'pods_api' ) && function_exists( 'pods' ) && class_exists( 'PodsForm' ) ? true : 'Pods is not active on this site.';
	}

	/**
	 * A Pod by name.
	 *
	 * @param string $name Pod name.
	 * @throws ToolError When there is no such Pod.
	 */
	private static function pod( string $name ): object {
		$pod = pods_api()->load_pod(
			array(
				'name'         => $name,
				'bypass_cache' => true,
			),
			false
		);
		if ( ! is_object( $pod ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Pods has no pod "' . $name . '" (see kuduy-pods-list).' );
		}
		return $pod;
	}

	/**
	 * Fields of a Pod, read past Pods' caches (a Pod object keeps the field list it loaded first; an empty list is
	 * never reloaded).
	 *
	 * @param object $pod Pod.
	 * @return list<object>
	 */
	private static function fields( object $pod ): array {
		$fields = pods_api()->load_fields(
			array(
				'pod_id'       => (int) $pod->get_arg( 'id' ),
				'bypass_cache' => true,
			)
		);
		return array_values( array_filter( (array) $fields, 'is_object' ) );
	}

	/**
	 * One field of a Pod by name, read past Pods' caches.
	 *
	 * @param object $pod  Pod.
	 * @param string $name Field name.
	 */
	private static function field( object $pod, string $name ): ?object {
		foreach ( self::fields( $pod ) as $field ) {
			if ( (string) $field->get_arg( 'name' ) === $name ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * A Pod as the tools show it.
	 *
	 * @param object $pod    Pod.
	 * @param bool   $fields With fields.
	 * @return array<string, mixed>
	 */
	private static function show( object $pod, bool $fields ): array {
		$list = self::fields( $pod );
		$out  = array(
			'name'    => (string) $pod->get_arg( 'name' ),
			'label'   => (string) $pod->get_label(),
			'type'    => (string) $pod->get_arg( 'type' ),
			'storage' => (string) $pod->get_arg( 'storage' ),
			'extends' => '' !== (string) $pod->get_arg( 'object' ) || in_array( (string) $pod->get_arg( 'type' ), array( 'user', 'media', 'comment' ), true ),
			'count'   => count( $list ),
		);
		if ( $fields ) {
			$out['fields'] = array();
			foreach ( $list as $field ) {
				$row = array(
					'name'     => (string) $field->get_arg( 'name' ),
					'label'    => (string) $field->get_label(),
					'type'     => (string) $field->get_arg( 'type' ),
					'required' => ! empty( $field->get_arg( 'required' ) ),
				);
				foreach ( array( 'description', 'default_value', 'pick_object', 'pick_val' ) as $key ) {
					$value = $field->get_arg( $key );
					if ( null !== $value && '' !== $value ) {
						$row[ $key ] = is_scalar( $value ) ? (string) $value : $value;
					}
				}
				$out['fields'][] = $row;
			}
		}
		return $out;
	}

	/**
	 * Whether the current user may change items of the Pod (Pods' own rules).
	 *
	 * @param object $pod Pod.
	 * @param int    $id  Item id (0 = new).
	 */
	private static function can_edit_item( object $pod, int $id ): bool {
		$type = (string) $pod->get_arg( 'type' );
		$name = (string) $pod->get_arg( 'name' );
		return match ( $type ) {
			'post_type', 'media' => $id > 0 ? current_user_can( 'edit_post', $id ) : current_user_can( (string) ( get_post_type_object( 'media' === $type ? 'attachment' : $name )->cap->create_posts ?? 'edit_posts' ) ),
			'taxonomy'           => $id > 0 ? current_user_can( 'edit_term', $id ) : current_user_can( (string) ( get_taxonomy( $name )->cap->manage_terms ?? 'manage_categories' ) ),
			'user'               => $id > 0 ? current_user_can( 'edit_user', $id ) : current_user_can( 'create_users' ),
			'comment'            => current_user_can( 'moderate_comments' ),
			default              => pods_is_admin( array( 'pods', 'pods_content', 'pods_edit_' . $name, ( $id > 0 ? 'pods_edit_others_' : 'pods_add_' ) . $name ) ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'save' === $this->mode && 'update' === ( $input['action'] ?? '' ) ) {
			$pod = self::pod( (string) $input['pod'] );
			return array(
				'object_type' => 'pods_pod',
				'object_id'   => (string) $input['pod'],
				'payload'     => array(
					'pod'    => (string) $input['pod'],
					'label'  => (string) $pod->get_arg( 'label' ),
					'single' => (string) $pod->get_arg( 'label_singular' ),
				),
			);
		}
		if ( 'field-save' === $this->mode ) {
			$field = self::field( self::pod( (string) $input['pod'] ), (string) $input['field'] );
			if ( ! is_object( $field ) ) {
				return null;
			}
			$old = array();
			foreach ( self::FIELD_KEYS as $key ) {
				$value = $field->get_arg( $key );
				if ( null !== $value ) {
					$old[ $key ] = $value;
				}
			}
			return array(
				'object_type' => 'pods_field',
				'object_id'   => $input['pod'] . '.' . $input['field'],
				'payload'     => array(
					'pod'      => (string) $input['pod'],
					'field'    => (string) $input['field'],
					'settings' => $old,
				),
			);
		}//end if
		if ( 'item-save' === $this->mode && ! empty( $input['id'] ) ) {
			$item = pods( (string) $input['pod'], (int) $input['id'] );
			$old  = array();
			foreach ( array_keys( (array) $input['values'] ) as $name ) {
				$old[ (string) $name ] = $item->field( (string) $name, null, true );
			}
			return array(
				'object_type' => 'pods_item',
				'object_id'   => $input['pod'] . '.' . $input['id'],
				'payload'     => array(
					'pod'    => (string) $input['pod'],
					'id'     => (int) $input['id'],
					'values' => $old,
				),
			);
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When Pods refuses.
	 */
	public function restore( array $payload ): void {
		self::guarded( fn() => $this->put_back( $payload ) );
	}

	/**
	 * The work of restore().
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When Pods refuses.
	 */
	private function put_back( array $payload ): void {
		if ( isset( $payload['values'] ) ) {
			pods( (string) $payload['pod'], (int) $payload['id'] )->save( (array) $payload['values'] );
			return;
		}
		if ( isset( $payload['settings'] ) ) {
			$pod    = self::pod( (string) $payload['pod'] );
			$field  = self::field( $pod, (string) $payload['field'] );
			$params = array_merge(
				(array) $payload['settings'],
				array(
					'pod_id' => (int) $pod->get_arg( 'id' ),
					'pod'    => (string) $payload['pod'],
					'name'   => (string) $payload['field'],
				)
			);
			if ( null !== $field ) {
				$params['id'] = (int) $field->get_arg( 'id' );
			}
			self::check( pods_api()->save_field( $params ) );
			return;
		}
		self::relabel( self::pod( (string) $payload['pod'] ), (string) $payload['label'], (string) $payload['single'] );
	}

	/**
	 * Change a Pod's labels the way Pods' edit screen saves a Pod: every setting it has, with the labels replaced (a
	 * partial save_pod() call crashed PHP in Playground with Pods 3.3.9.2).
	 *
	 * @param object      $pod      Pod.
	 * @param string|null $plural   Plural label (null = keep).
	 * @param string|null $singular Singular label (null = keep).
	 * @throws ToolError When Pods refuses.
	 */
	private static function relabel( object $pod, ?string $plural, ?string $singular ): void {
		$params = array();
		foreach ( (array) $pod->get_args() as $key => $value ) {
			if ( is_scalar( $value ) || null === $value || is_array( $value ) ) {
				$params[ $key ] = $value;
			}
		}
		unset( $params['fields'], $params['groups'], $params['object_fields'] );
		$params['id']   = (int) $pod->get_arg( 'id' );
		$params['name'] = (string) $pod->get_arg( 'name' );
		if ( null !== $plural ) {
			$params['label'] = sanitize_text_field( $plural );
		}
		if ( null !== $singular ) {
			$params['label_singular'] = sanitize_text_field( $singular );
		}
		self::check( pods_api()->save_pod( $params ) );
	}

	/**
	 * Turn a Pods error into a ToolError.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 * @throws ToolError When the result is an error.
	 */
	private static function check( mixed $result ): mixed {
		// Pods caches pods and fields (objects and transients); after a save the next read must see the change.
		pods_api()->cache_flush_pods( null, false );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Pods refused: ' . wp_strip_all_tags( $result->get_error_message() ) . ' Nothing was changed.' );
		}
		if ( false === $result ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Pods did not save. Nothing was changed.' );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the Pod, field or item is refused.
	 */
	public function execute( array $input ): array {
		return self::guarded( fn() => $this->run( $input ) );
	}

	/**
	 * Run Pods calls with Pods' errors as exceptions. pods_error() otherwise calls exit when display_errors is on,
	 * or sends its own JSON reply and ends the request during REST calls (read on 3.3.9.2, includes/general.php);
	 * either would cut the tool call off.
	 *
	 * @param callable $work Work.
	 * @return mixed
	 * @throws ToolError When Pods refuses.
	 */
	private static function guarded( callable $work ): mixed {
		$mode = static fn() => 'exception';
		add_filter( 'pods_error_mode', $mode, PHP_INT_MAX );
		try {
			return $work();
		} catch ( ToolError $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Pods refused: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was changed.' );
		} finally {
			remove_filter( 'pods_error_mode', $mode, PHP_INT_MAX );
		}
	}

	/**
	 * The work of execute().
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the Pod, field or item is refused.
	 */
	private function run( array $input ): array {
		if ( 'list' === $this->mode ) {
			return array( 'pods' => array_values( array_map( static fn( $p ) => self::show( $p, false ), (array) pods_api()->load_pods( array() ) ) ) );
		}
		if ( 'get' === $this->mode ) {
			return array( 'pod' => self::show( self::pod( (string) $input['pod'] ), true ) );
		}
		if ( in_array( $this->mode, array( 'save', 'field-save' ), true ) && ! pods_is_admin( array( 'pods' ) ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not change Pods definitions (Pods\' "pods" capability). Nothing was changed.' );
		}
		if ( 'save' === $this->mode ) {
			return $this->save_pod( $input );
		}
		if ( 'field-save' === $this->mode ) {
			return $this->save_field( $input );
		}
		$pod  = self::pod( (string) $input['pod'] );
		$id   = (int) ( $input['id'] ?? 0 );
		$item = pods( (string) $input['pod'], $id > 0 ? $id : null );
		if ( $id > 0 && ! $item->exists() ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Pod "' . $input['pod'] . '" has no item ' . $id . '.' );
		}
		// Reading an item's raw field values needs the same right as editing it (as Pods' own edit screens).
		if ( ! self::can_edit_item( $pod, $id ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'item-get' === $this->mode ? 'Your account may not edit this item, so its fields are not shown.' : 'Your account may not change this item. Nothing was changed.' );
		}
		$fields = array();
		foreach ( self::fields( $pod ) as $field ) {
			$fields[ (string) $field->get_arg( 'name' ) ] = (string) $field->get_arg( 'type' );
		}
		if ( 'item-get' === $this->mode ) {
			$wanted = isset( $input['fields'] ) ? array_map( 'strval', (array) $input['fields'] ) : array_keys( $fields );
			$values = array();
			foreach ( $wanted as $name ) {
				if ( ! isset( $fields[ $name ] ) ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Pod "' . $input['pod'] . '" has no field "' . $name . '".' );
				}
				$values[ $name ] = 'password' === $fields[ $name ] ? '[hidden]' : $item->field( $name, null, true );
			}
			return array(
				'id'     => $id,
				'values' => (object) $values,
			);
		}
		$values = (array) $input['values'];
		foreach ( array_keys( $values ) as $name ) {
			if ( ! isset( $fields[ (string) $name ] ) && ! in_array( $name, array( 'post_title', 'post_content', 'post_status', 'name' ), true ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Pod "' . $input['pod'] . '" has no field "' . $name . '" (see kuduy-pods-get). Nothing was changed.' );
			}
		}
		AuditLog::target( 'pods_item', $input['pod'] . '.' . $id );
		$saved = $id > 0 ? $item->save( $values ) : $item->add( $values );
		$saved = (int) self::check( $saved );
		return array(
			'id'      => $saved,
			'created' => 0 === $id,
		);
	}

	/**
	 * Create, extend or relabel a Pod.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When Pods refuses.
	 */
	private function save_pod( array $input ): array {
		$name   = (string) $input['pod'];
		$action = (string) $input['action'];
		AuditLog::target( 'pods_pod', $name );
		if ( 'update' === $action ) {
			self::relabel( self::pod( $name ), isset( $input['plural'] ) ? (string) $input['plural'] : null, isset( $input['singular'] ) ? (string) $input['singular'] : null );
			return array(
				'created' => false,
				'pod'     => self::show( self::pod( $name ), false ),
			);
		}
		$type = (string) ( $input['type'] ?? '' );
		if ( 'create' === $action ) {
			if ( ! in_array( $type, self::CREATE, true ) || empty( $input['singular'] ) || empty( $input['plural'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Creating a Pod needs type (' . implode( ', ', self::CREATE ) . '), singular and plural. Nothing was created.' );
			}
			$params = array(
				'create_extend'         => 'create',
				'create_pod_type'       => $type,
				'create_name'           => $name,
				'create_label_singular' => sanitize_text_field( (string) $input['singular'] ),
				'create_label_plural'   => sanitize_text_field( (string) $input['plural'] ),
				'create_storage'        => (string) ( $input['storage'] ?? ( 'pod' === $type ? 'table' : 'meta' ) ),
				'create_rest_api'       => 1,
			);
		} else {
			if ( ! in_array( $type, self::EXTEND, true ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Extending needs type (' . implode( ', ', self::EXTEND ) . '). Nothing was created.' );
			}
			$params = array(
				'create_extend'   => 'extend',
				'extend_pod_type' => $type,
				'extend_storage'  => (string) ( $input['storage'] ?? 'meta' ),
			);
			if ( 'post_type' === $type ) {
				$params['extend_post_type'] = $name;
			} elseif ( 'taxonomy' === $type ) {
				$params['extend_taxonomy'] = $name;
			}
		}//end if
		self::check( pods_api()->add_pod( $params ) );
		return array(
			'created' => true,
			'pod'     => self::show( self::pod( in_array( $type, array( 'user', 'media', 'comment' ), true ) && 'extend' === $action ? $type : $name ), false ),
		);
	}

	/**
	 * Add or change a field.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When a setting or type is refused or Pods refuses.
	 */
	private function save_field( array $input ): array {
		$pod      = self::pod( (string) $input['pod'] );
		$settings = (array) $input['settings'];
		foreach ( $settings as $key => $value ) {
			if ( ! in_array( $key, self::FIELD_KEYS, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Field setting "' . $key . '" is not set through this tool (it takes ' . implode( ', ', self::FIELD_KEYS ) . '). Nothing was changed.' );
			}
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Field setting "' . $key . '" takes a single value. Nothing was changed.' );
			}
			$settings[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}
		$existing = self::field( $pod, (string) $input['field'] );
		if ( ! is_object( $existing ) ) {
			if ( empty( $settings['label'] ) || empty( $settings['type'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new field needs label and type. Nothing was created.' );
			}
		}
		if ( isset( $settings['type'] ) && ! in_array( $settings['type'], (array) \PodsForm::field_types_list(), true ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Pods has no field type "' . $settings['type'] . '" (it has ' . implode( ', ', (array) \PodsForm::field_types_list() ) . '). Nothing was changed.' );
		}
		AuditLog::target( 'pods_field', $input['pod'] . '.' . $input['field'] );
		$params = array_merge(
			$settings,
			array(
				'pod_id' => (int) $pod->get_arg( 'id' ),
				'pod'    => (string) $input['pod'],
				'name'   => (string) $input['field'],
			)
		);
		if ( is_object( $existing ) ) {
			$params['id'] = (int) $existing->get_arg( 'id' );
		}
		self::check( pods_api()->save_field( $params ) );
		$fields = array_column( self::show( self::pod( (string) $input['pod'] ), true )['fields'], null, 'name' );
		return array(
			'created' => ! is_object( $existing ),
			'field'   => (array) ( $fields[ (string) $input['field'] ] ?? array() ),
		);
	}
}
