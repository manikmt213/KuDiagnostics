<?php
/**
 * Tools: kuduy-P-field-types, kuduy-P-form-create, kuduy-P-form-edit, kuduy-P-notification-save for each form
 * editor (phase B2, decision D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * One tool class for four modes over any FormEditor. The checks that do not depend on the plugin live here: the
 * form exists and the user may change it in the plugin, every operation names a field that exists at that point,
 * a field is removed only while no entry holds a value for it (Formidable deletes those values with the field,
 * the others would leave them without a field), and notification routing (recipient, sender, reply-to, Cc/Bcc,
 * on/off) changes only after the user confirmed (as CF7 mail, D16 A). Every operation is checked before the
 * plugin saves anything.
 */
final class FormEditTools implements Tool, Snapshottable {

	/**
	 * Notification keys that decide where entries are emailed.
	 */
	private const ROUTING = array( 'enabled', 'to', 'from_email', 'reply_to', 'cc', 'bcc' );

	/**
	 * Notification keys the tool takes.
	 */
	private const NOTIFICATION = array( 'name', 'enabled', 'to', 'from_name', 'from_email', 'reply_to', 'cc', 'bcc', 'subject', 'message' );

	/**
	 * Most operations in one call.
	 */
	private const MAX_OPERATIONS = 30;

	/**
	 * Constructor.
	 *
	 * @param FormEditor $editor Plugin editor.
	 * @param string     $mode   field-types | form-create | form-edit | notification-save.
	 */
	public function __construct( private FormEditor $editor, private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->editor->prefix() . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		$what = array(
			'field-types'       => 'Field types',
			'form-create'       => 'Create a form',
			'form-edit'         => 'Edit a form',
			'notification-save' => 'Save an email notification',
		);
		return $this->editor->name() . ': ' . $what[ $this->mode ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$p    = 'kuduy-' . $this->editor->prefix();
		$name = $this->editor->name();
		return match ( $this->mode ) {
			'field-types'       => 'Lists the field types ' . $name . ' offers on this site and, per type, the properties a field of that type takes in ' . $p . '-form-create and ' . $p . '-form-edit.',
			'form-create'       => 'Creates a ' . $name . ' form with a title and fields, through ' . $name . '\'s own save path; the form starts with ' . $name . '\'s defaults (e.g. its admin notification and confirmation). Fields: [{"type":"email","properties":{"label":"Your email","required":true}}], types and properties from ' . $p . '-field-types. Returns the form as read back. Forms are not deleted through KuDiagnostics, so a created form has no undo.',
			'form-edit'         => 'Changes a ' . $name . ' form through ' . $name . '\'s own save path: operations (up to 30, applied in order) add a field {"op":"add","type":"text","properties":{"label":"Company"},"index":2}, change one {"op":"update","field":"3","properties":{"required":true}}, move one {"op":"move","field":"3","index":0} or remove one {"op":"remove","field":"3"}; "form" sets title, submit_text or confirmation_message. Field ids and properties as ' . $p . '-form-edit returns them and ' . $p . '-field-types lists them. Everything is checked before anything is saved. A field is not removed while entries hold a value for it. Snapshot first; undo with kuduy-snapshot-restore.',
			default             => 'Creates (no id) or changes an email notification of a ' . $name . ' form: name, enabled, to, from_name, from_email, reply_to, cc, bcc, subject, message (the plugin\'s own smart tags allowed). These decide where every future entry is emailed: when the recipient, sender, reply-to, Cc/Bcc or on/off change, the first call only reports the old and new routing (done: false); ask the user, then call again with confirm: true. Needs the admin scope. Changes are undone with kuduy-snapshot-restore; a new notification has no undo (switch it off with enabled: false).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$form_id    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = array(
			'type'        => 'object',
			'description' => 'Field properties by the plugin\'s own names (see the field-types tool), e.g. label, required, placeholder, description, choices [{"label":"A","value":"a"}].',
		);
		$text       = array(
			'type'      => 'string',
			'maxLength' => 5000,
		);
		return match ( $this->mode ) {
			'field-types'       => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'form-create'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'  => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'fields' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_OPERATIONS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'type'       => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 60,
								),
								'properties' => $properties,
							),
							'required'             => array( 'type' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'title' ),
				'additionalProperties' => false,
			),
			'form-edit'         => array(
				'type'                 => 'object',
				'properties'           => array(
					'form_id'    => $form_id,
					'operations' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_OPERATIONS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'op'         => array(
									'type' => 'string',
									'enum' => array( 'add', 'update', 'move', 'remove' ),
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
								'properties' => $properties,
								'index'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
							),
							'required'             => array( 'op' ),
							'additionalProperties' => false,
						),
					),
					'form'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'                => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'submit_text'          => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'confirmation_message' => $text,
						),
						'additionalProperties' => false,
					),
				),
				'required'             => array( 'form_id' ),
				'additionalProperties' => false,
			),
			default             => array(
				'type'                 => 'object',
				'properties'           => array(
					'form_id'    => $form_id,
					'id'         => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
					),
					'name'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'enabled'    => array( 'type' => 'boolean' ),
					'to'         => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'from_name'  => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'from_email' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'reply_to'   => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'cc'         => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'bcc'        => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'subject'    => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'message'    => array(
						'type'      => 'string',
						'maxLength' => 20000,
					),
					'confirm'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'form_id' ),
				'additionalProperties' => false,
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return match ( $this->mode ) {
			'field-types'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugin' => array( 'type' => 'string' ),
					'types'  => array( 'type' => 'array' ),
				),
				'required'   => array( 'plugin', 'types' ),
			),
			'notification-save' => array(
				'type'       => 'object',
				'properties' => array(
					'done'         => array( 'type' => 'boolean' ),
					'notification' => array( 'type' => 'object' ),
					'routing'      => array( 'type' => 'object' ),
					'next'         => array( 'type' => 'string' ),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'done' ),
			),
			default             => array(
				'type'       => 'object',
				'properties' => array(
					'form'         => array( 'type' => 'object' ),
					'added'        => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'form' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'field-types' === $this->mode,
			'destructiveHint' => in_array( $this->mode, array( 'form-edit', 'notification-save' ), true ),
			'idempotentHint'  => 'field-types' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'field-types'       => 'kuduy:read',
			'notification-save' => 'kuduy:admin',
			default             => 'kuduy:write',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * The plugin's own permission is checked per form in snapshot() and execute(); this is the WordPress gate.
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'notification-save' === $this->mode ? 'manage_options' : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return $this->editor->available();
	}

	/**
	 * The form after the plugin's own permission check.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When the form is missing or the user may not change it in the plugin.
	 */
	private function editable( int $form_id ): array {
		$form = $this->editor->form( $form_id );
		if ( ! $this->editor->can_edit( $form_id ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not change ' . $this->editor->name() . ' form ' . $form_id . ' in ' . $this->editor->name() . '. Nothing was changed.' );
		}
		return $form;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'form-edit' === $this->mode ) {
			$id = (int) $input['form_id'];
			$this->editable( $id );
			return array(
				'object_type' => $this->editor->prefix() . '_form',
				'object_id'   => (string) $id,
				'payload'     => array(
					'form_id' => $id,
					'state'   => $this->editor->snapshot( $id ),
				),
			);
		}
		if ( 'notification-save' !== $this->mode ) {
			return null;
		}
		$id = (int) $input['form_id'];
		$this->editable( $id );
		if ( ! isset( $input['id'] ) ) {
			// A new notification has no undo (as new forms); it is switched off with enabled: false.
			return null;
		}
		$old = $this->notification( $id, (string) $input['id'] );
		if ( self::routing_changes( $old, $input ) && empty( $input['confirm'] ) ) {
			return null;
		}
		return array(
			'object_type' => $this->editor->prefix() . '_notification',
			'object_id'   => $id . ':' . $input['id'],
			'payload'     => array(
				'form_id'      => $id,
				'notification' => $old,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the form or notification cannot be put back.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['form_id'] ?? 0 );
		if ( 'form-edit' === $this->mode ) {
			$this->editor->restore( $id, (array) ( $payload['state'] ?? array() ) );
			return;
		}
		$old = (array) ( $payload['notification'] ?? array() );
		$this->editor->save_notification( $id, (string) $old['id'], array_intersect_key( $old, array_flip( self::NOTIFICATION ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an operation or the plugin refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'field-types'       => array(
				'plugin' => $this->editor->name(),
				'types'  => $this->editor->field_types(),
			),
			'form-create'       => $this->create( $input ),
			'form-edit'         => $this->edit( $input ),
			default             => $this->save_notification( $input ),
		};
	}

	/**
	 * Create a form, then add its fields with the edit path.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the user may not create forms or a field is refused.
	 */
	private function create( array $input ): array {
		if ( ! $this->editor->can_create() ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not create ' . $this->editor->name() . ' forms. Nothing was created.' );
		}
		$operations = array();
		foreach ( (array) ( $input['fields'] ?? array() ) as $field ) {
			$operations[] = array(
				'op'         => 'add',
				'type'       => (string) $field['type'],
				'properties' => (array) ( $field['properties'] ?? array() ),
			);
		}
		$known = $this->known_types();
		foreach ( $operations as $n => $operation ) {
			$this->check_add( $operation, $known, $n );
		}
		$id = $this->editor->create( (string) $input['title'] );
		AuditLog::target( $this->editor->prefix() . '_form', (string) $id );
		$added = $operations ? $this->editor->apply( $id, $operations ) : array();
		return array(
			'form'  => $this->editor->form( $id ),
			'added' => $added,
		);
	}

	/**
	 * Check every operation against the form as it will be at that point, then let the plugin save.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an operation is refused; nothing is saved then.
	 */
	private function edit( array $input ): array {
		$id         = (int) $input['form_id'];
		$form       = $this->editable( $id );
		$operations = array_values( (array) ( $input['operations'] ?? array() ) );
		$settings   = (array) ( $input['form'] ?? array() );
		if ( ! $operations && ! $settings ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give operations or form settings. Nothing was changed.' );
		}
		$known = $this->known_types();
		$order = array();
		$types = array();
		foreach ( (array) $form['fields'] as $field ) {
			$order[]                        = (string) $field['id'];
			$types[ (string) $field['id'] ] = (string) $field['type'];
		}
		foreach ( $operations as $n => $operation ) {
			$op    = (string) $operation['op'];
			$where = 'Operation ' . ( $n + 1 ) . ' (' . $op . ')';
			if ( 'add' === $op ) {
				$this->check_add( $operation, $known, $n );
				$new           = 'new:' . $n;
				$index         = min( (int) ( $operation['index'] ?? count( $order ) ), count( $order ) );
				$order         = array_merge( array_slice( $order, 0, $index ), array( $new ), array_slice( $order, $index ) );
				$types[ $new ] = (string) $operation['type'];
				continue;
			}
			$field = (string) ( $operation['field'] ?? '' );
			if ( '' === $field || ! in_array( $field, $order, true ) || str_starts_with( $field, 'new:' ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $where . ': the form has no field "' . $field . '" at that point (field ids as the form-edit result lists them). Nothing was changed.' );
			}
			if ( 'update' === $op ) {
				if ( isset( $operation['type'] ) && $operation['type'] !== $types[ $field ] ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': a field keeps its type (' . $types[ $field ] . '); add a new field instead. Nothing was changed.' );
				}
				if ( empty( $operation['properties'] ) ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, $where . ': give properties to change. Nothing was changed.' );
				}
				$this->check_properties( $types[ $field ], (array) $operation['properties'], $where );
				continue;
			}
			$order = array_values( array_diff( $order, array( $field ) ) );
			if ( 'move' === $op ) {
				if ( ! isset( $operation['index'] ) ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, $where . ': give index (0 = first). Nothing was changed.' );
				}
				$index = min( (int) $operation['index'], count( $order ) );
				$order = array_merge( array_slice( $order, 0, $index ), array( $field ), array_slice( $order, $index ) );
				continue;
			}
			$held = $this->editor->entry_values( $id, $field );
			if ( $held > 0 ) {
				throw ToolError::of( ToolError::CONFLICT, $where . ': ' . $held . ' ' . ( 1 === $held ? 'entry holds' : 'entries hold' ) . ' a value for field ' . $field . '; removing the field would lose those answers. Remove it in ' . $this->editor->name() . ' itself if that is wanted. Nothing was changed.' );
			}
		}//end foreach
		if ( $settings ) {
			$operations[] = array_merge( array( 'op' => 'form' ), $settings );
		}
		AuditLog::target( $this->editor->prefix() . '_form', (string) $id );
		$added = $this->editor->apply( $id, $operations );
		return array(
			'form'  => $this->editor->form( $id ),
			'added' => $added,
		);
	}

	/**
	 * Field types by type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function known_types(): array {
		$out = array();
		foreach ( $this->editor->field_types() as $type ) {
			$out[ $type['type'] ] = $type;
		}
		return $out;
	}

	/**
	 * Check an add operation.
	 *
	 * @param array<string, mixed>                $operation Operation.
	 * @param array<string, array<string, mixed>> $known     Field types.
	 * @param int                                 $n         Position.
	 * @throws ToolError When the type is unknown or a property is refused.
	 */
	private function check_add( array $operation, array $known, int $n ): void {
		$where = 'Operation ' . ( $n + 1 ) . ' (add)';
		$type  = (string) ( $operation['type'] ?? '' );
		if ( ! isset( $known[ $type ] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': ' . $this->editor->name() . ' offers no field type "' . $type . '" on this site (see kuduy-' . $this->editor->prefix() . '-field-types). Nothing was changed.' );
		}
		$this->check_properties( $type, (array) ( $operation['properties'] ?? array() ), $where );
	}

	/**
	 * Let the editor check properties, naming the operation in its message.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @param string               $where      Operation label.
	 * @throws ToolError When a property is refused.
	 */
	private function check_properties( string $type, array $properties, string $where ): void {
		try {
			$this->editor->check_properties( $type, $properties );
		} catch ( ToolError $e ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, $where . ': ' . $e->getMessage() . ' Nothing was changed.' );
		}
	}

	/**
	 * One notification of the form.
	 *
	 * @param int    $form_id Form id.
	 * @param string $id      Notification id.
	 * @return array<string, mixed>
	 * @throws ToolError When the form has no such notification.
	 */
	private function notification( int $form_id, string $id ): array {
		foreach ( $this->editor->notifications( $form_id ) as $notification ) {
			if ( (string) $notification['id'] === $id ) {
				return $notification;
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $this->editor->name() . ' form ' . $form_id . ' has no notification "' . $id . '" (see kuduy-forms-get). Nothing was changed.' );
	}

	/**
	 * Whether the call changes where entries are emailed.
	 *
	 * @param array<string, mixed>|null $old   Notification now (null = new).
	 * @param array<string, mixed>      $input Arguments.
	 */
	private static function routing_changes( ?array $old, array $input ): bool {
		if ( null === $old ) {
			return true;
		}
		foreach ( self::ROUTING as $key ) {
			if ( array_key_exists( $key, $input ) && (string) ( $old[ $key ] ?? '' ) !== (string) $input[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save a notification, confirm-first when its routing changes.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the form or notification is missing or the plugin refuses.
	 */
	private function save_notification( array $input ): array {
		$form_id = (int) $input['form_id'];
		$this->editable( $form_id );
		$id     = isset( $input['id'] ) ? (string) $input['id'] : null;
		$old    = null === $id ? null : $this->notification( $form_id, $id );
		$values = array_intersect_key( $input, array_flip( self::NOTIFICATION ) );
		if ( null === $id && empty( $values['to'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new notification needs "to". Nothing was changed.' );
		}
		if ( ! $values ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give the notification values to change. Nothing was changed.' );
		}
		if ( self::routing_changes( $old, $input ) && empty( $input['confirm'] ) ) {
			$routing = array();
			foreach ( self::ROUTING as $key ) {
				$routing[ $key ] = array(
					'old' => null === $old ? null : ( $old[ $key ] ?? null ),
					'new' => $values[ $key ] ?? ( null === $old ? null : ( $old[ $key ] ?? null ) ),
				);
			}
			return array(
				'done'    => false,
				'routing' => $routing,
				'next'    => 'Nothing was changed. These values decide where every future entry of the form is emailed. Show them to the user; if they agree, call again with the same arguments and confirm: true.',
			);
		}
		AuditLog::target( $this->editor->prefix() . '_form', (string) $form_id );
		$saved = $this->editor->save_notification( $form_id, $id, $values );
		return array(
			'done'         => true,
			'notification' => $this->notification( $form_id, $saved ),
		);
	}
}
