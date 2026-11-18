<?php
/**
 * Ninja Forms form editor (phase B2, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits Ninja Forms forms with the functions Ninja Forms' own abilities run (read on Ninja Forms 3.16.1,
 * includes/Abilities/Utils/FieldUtils.php and ActionUtils.php, 2026-11-18; loaded with WordPress' Abilities API,
 * which every supported WordPress version has): ninja_forms_ability_add_field() (type check, unique key with
 * Ninja's timestamp, the type's default options, insertion by position), _update_field(), _reorder_fields(),
 * _remove_field() (only reached when no submission holds a value; Ninja's own export-and-confirm path is not used
 * because KuDiagnostics refuses those removals), _add_action() and _update_action() for email notifications and
 * the success message. Form title and the undo use the form, field and action models the builder saves with
 * (`update_settings()->save()`), and the form cache is cleared afterwards as Ninja's abilities clear it.
 * Capability: manage_options (Ninja's `ninja_forms_admin_all_forms_capabilities` filter).
 */
final class NinjaEditor implements FormEditor {

	/**
	 * Tool property => Ninja field setting.
	 */
	private const PROPERTIES = array(
		'label'         => 'label',
		'required'      => 'required',
		'placeholder'   => 'placeholder',
		'default_value' => 'default',
		'help_text'     => 'help_text',
		'admin_label'   => 'admin_label',
		'options'       => 'options',
		'key'           => 'key',
	);

	/**
	 * Notification keys: tool name => Ninja email action setting.
	 */
	private const NOTIFICATION = array(
		'name'       => 'label',
		'to'         => 'to',
		'from_name'  => 'from_name',
		'from_email' => 'from_address',
		'reply_to'   => 'reply_to',
		'cc'         => 'cc',
		'bcc'        => 'bcc',
		'subject'    => 'email_subject',
		'message'    => 'email_message',
	);

	/**
	 * Ninja's ability functions this editor calls.
	 */
	private const FUNCTIONS = array( 'ninja_forms_ability_create_form', 'ninja_forms_ability_add_field', 'ninja_forms_ability_update_field', 'ninja_forms_ability_reorder_fields', 'ninja_forms_ability_remove_field', 'ninja_forms_ability_add_action', 'ninja_forms_ability_update_action' );

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'ninja-forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Ninja Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return 'Ninja Forms is not active on this site.';
		}
		if ( ! function_exists( 'ninja_forms_ability_add_field' ) && function_exists( 'wp_get_abilities' ) ) {
			// Ninja loads these functions when it registers its abilities, the first time WordPress' ability
			// registry starts in a request.
			wp_get_abilities();
		}
		foreach ( self::FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) ) {
				return 'This Ninja Forms version has no field functions for programmatic edits (Ninja Forms 3.14 or later with WordPress\' Abilities API is needed to edit forms here).';
			}
		}
		return true;
	}

	/**
	 * Ninja's capability for its forms screens.
	 */
	private static function cap(): string {
		return (string) apply_filters( 'ninja_forms_admin_all_forms_capabilities', 'manage_options' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Ninja Forms' own filter, as its builder save checks it.
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create(): bool {
		return current_user_can( self::cap() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool {
		return current_user_can( self::cap() );
	}

	/**
	 * Setting names a field type's definitions hold (fieldsets flattened).
	 *
	 * @param array<string, mixed> $settings Definitions.
	 * @return list<string>
	 */
	private static function setting_names( array $settings ): array {
		$out = array();
		foreach ( $settings as $name => $setting ) {
			$out[] = (string) $name;
			if ( is_array( $setting ) && isset( $setting['settings'] ) && is_array( $setting['settings'] ) ) {
				$out = array_merge( $out, self::setting_names( $setting['settings'] ) );
			}
		}
		return $out;
	}

	/**
	 * Tool properties a type takes.
	 *
	 * @param object $type Field type object.
	 * @return list<string>
	 */
	private static function properties_of( object $type ): array {
		$names = self::setting_names( method_exists( $type, 'get_settings' ) ? (array) $type->get_settings() : array() );
		$out   = array( 'label', 'admin_label', 'key' );
		foreach ( array( 'required', 'placeholder', 'default_value', 'help_text', 'options' ) as $property ) {
			if ( in_array( self::PROPERTIES[ $property ], $names, true ) ) {
				$out[] = $property;
			}
		}
		return $out;
	}

	/**
	 * Field types that can be added (Ninja's own selectable check: retired types have no builder section).
	 *
	 * @return array<string, object>
	 */
	private static function types(): array {
		$out = array();
		foreach ( (array) Ninja_Forms()->fields as $name => $type ) {
			if ( ! is_object( $type ) ) {
				continue;
			}
			$selectable = function_exists( 'ninja_forms_ability_field_type_is_selectable' )
				? ninja_forms_ability_field_type_is_selectable( (string) $name )
				: ( method_exists( $type, 'get_section' ) && '' !== (string) $type->get_section() );
			if ( $selectable ) {
				$out[ (string) $name ] = $type;
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function field_types(): array {
		$out = array();
		foreach ( self::types() as $name => $type ) {
			$out[] = array(
				'type'       => $name,
				'label'      => method_exists( $type, 'get_nicename' ) && '' !== (string) $type->get_nicename() ? (string) $type->get_nicename() : $name,
				'properties' => self::properties_of( $type ),
			);
		}
		return $out;
	}

	/**
	 * A model factory of the form that reads the database now. Ninja_Forms()->form( $id ) keeps one factory per form
	 * for the whole request with the fields and actions it loaded first (read on 3.16.1, ninja-forms.php form()), so
	 * after a change it can hand back stale models; Ninja itself builds a new factory for new forms.
	 *
	 * @param int $form_id Form id.
	 */
	private static function factory( int $form_id ): object {
		global $wpdb;
		\WPN_Helper::delete_nf_cache( $form_id );
		return new \NF_Abstracts_ModelFactory( $wpdb, $form_id );
	}

	/**
	 * Bring the shared factory Ninja's own functions use up to date with the database before one runs.
	 *
	 * @param int $form_id Form id.
	 */
	private static function refresh( int $form_id ): void {
		\WPN_Helper::delete_nf_cache( $form_id );
		$shared = Ninja_Forms()->form( $form_id );
		$shared->get_fields( array(), true );
		$shared->get_actions( array(), true );
	}

	/**
	 * The form model, or a ToolError.
	 *
	 * @param int $form_id Form id.
	 * @throws ToolError When there is no such form.
	 */
	private static function model( int $form_id ): object {
		$form = self::factory( $form_id )->get();
		if ( ! is_object( $form ) || ! $form->get_id() ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Ninja Forms has no form ' . $form_id . '.' );
		}
		return $form;
	}

	/**
	 * Field models in their order.
	 *
	 * @param int $form_id Form id.
	 * @return list<object>
	 */
	private static function fields( int $form_id ): array {
		$fields = array_values( array_filter( (array) self::factory( $form_id )->get_fields(), 'is_object' ) );
		usort( $fields, static fn( $a, $b ) => (int) $a->get_setting( 'order' ) <=> (int) $b->get_setting( 'order' ) );
		return $fields;
	}

	/**
	 * Action models of the form.
	 *
	 * @param int $form_id Form id.
	 * @return list<object>
	 */
	private static function actions( int $form_id ): array {
		return array_values( array_filter( (array) self::factory( $form_id )->get_actions(), 'is_object' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function form( int $form_id ): array {
		$form   = self::model( $form_id );
		$types  = self::types();
		$fields = array();
		$submit = '';
		foreach ( self::fields( $form_id ) as $field ) {
			$type = (string) $field->get_setting( 'type' );
			if ( 'submit' === $type && '' === $submit ) {
				$submit = (string) $field->get_setting( 'label' );
			}
			$row = array(
				'id'       => (string) $field->get_id(),
				'type'     => $type,
				'label'    => (string) $field->get_setting( 'label' ),
				'required' => ! empty( $field->get_setting( 'required' ) ),
				'key'      => (string) $field->get_setting( 'key' ),
			);
			foreach ( isset( $types[ $type ] ) ? self::properties_of( $types[ $type ] ) : array() as $property ) {
				if ( isset( $row[ $property ] ) ) {
					continue;
				}
				$value = $field->get_setting( self::PROPERTIES[ $property ] );
				if ( 'options' === $property ) {
					$row['options'] = array_values(
						array_map(
							static fn( $o ) => array(
								'label' => (string) ( $o['label'] ?? '' ),
								'value' => (string) ( $o['value'] ?? '' ),
							),
							is_array( $value ) ? $value : array()
						)
					);
				} elseif ( null !== $value && '' !== $value ) {
					$row[ $property ] = $value;
				}
			}
			$fields[] = $row;
		}//end foreach
		$message = null;
		foreach ( self::actions( $form_id ) as $action ) {
			if ( 'successmessage' === $action->get_setting( 'type' ) ) {
				$message = (string) $action->get_setting( 'success_msg' );
				break;
			}
		}
		return array(
			'id'                   => $form_id,
			'title'                => (string) $form->get_setting( 'title' ),
			'fields'               => $fields,
			'submit_text'          => $submit,
			'confirmation_message' => $message,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function check_properties( string $type, array $properties ): void {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Ninja Forms offers no field type "' . $type . '" on this site.' );
		}
		$allowed = self::properties_of( $types[ $type ] );
		foreach ( $properties as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' field does not take ' . $key . ' (it takes ' . implode( ', ', $allowed ) . ').' );
			}
			$ok = match ( $key ) {
				'required' => is_bool( $value ),
				'options'  => is_array( $value ) && $value && count( array_filter( $value, static fn( $o ) => is_array( $o ) && isset( $o['label'] ) && is_string( $o['label'] ) ) ) === count( $value ),
				'key'      => is_string( $value ) && (bool) preg_match( '/^[a-z0-9_]{1,100}$/', $value ),
				default    => is_string( $value ) || is_numeric( $value ),
			};
			if ( ! $ok ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $key . ' has the wrong kind of value (' . ( 'options' === $key ? 'a list of {"label","value"}' : ( 'required' === $key ? 'true or false' : ( 'key' === $key ? 'lower-case letters, digits and _' : 'text' ) ) ) . ').' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $form_id  Form id.
	 * @param string $field_id Field id.
	 */
	public function entry_values( int $form_id, string $field_id ): int {
		return function_exists( 'ninja_forms_count_submissions_with_field_data' ) ? (int) ninja_forms_count_submissions_with_field_data( $form_id, (int) $field_id ) : 0;
	}

	/**
	 * Run one of Ninja's ability functions.
	 *
	 * @param string               $function Function name.
	 * @param array<string, mixed> $input    Input.
	 * @return array<string, mixed>
	 * @throws ToolError When Ninja refuses.
	 */
	private static function run( string $function, array $input ): array {
		if ( isset( $input['form_id'] ) ) {
			// Ninja's insert check compares its cached field list with the database: start from the database.
			self::refresh( (int) $input['form_id'] );
		}
		$result = call_user_func( $function, $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Ninja Forms refused: ' . $result->get_error_message() );
		}
		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Ninja Forms refused: ' . (string) ( is_array( $result ) ? ( $result['message'] ?? 'no reason given' ) : 'no result' ) );
		}
		return $result;
	}

	/**
	 * Input for Ninja's field functions from tool properties.
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function field_input( array $properties ): array {
		$input = array();
		foreach ( $properties as $key => $value ) {
			if ( 'options' === $key ) {
				$options = array();
				foreach ( (array) $value as $option ) {
					$label     = sanitize_text_field( (string) $option['label'] );
					$options[] = array(
						'label' => $label,
						'value' => isset( $option['value'] ) ? sanitize_text_field( (string) $option['value'] ) : $label,
					);
				}
				$value = $options;
			}
			$input[ $key ] = $value;
		}
		return $input;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function apply( int $form_id, array $operations ): array {
		$before = $this->snapshot( $form_id );
		$added  = array();
		try {
			foreach ( $operations as $operation ) {
				$op = (string) $operation['op'];
				if ( 'form' === $op ) {
					$this->form_settings( $form_id, $operation );
				} elseif ( 'add' === $op ) {
					$input = array_merge(
						self::field_input( (array) ( $operation['properties'] ?? array() ) ),
						array(
							'form_id' => $form_id,
							'type'    => (string) $operation['type'],
						)
					);
					if ( isset( $operation['index'] ) ) {
						$input['order'] = (int) $operation['index'] + 1;
					}
					$added[] = (string) ( self::run( 'ninja_forms_ability_add_field', $input )['field_id'] ?? '' );
				} elseif ( 'update' === $op ) {
					self::run(
						'ninja_forms_ability_update_field',
						array_merge(
							self::field_input( (array) $operation['properties'] ),
							array(
								'form_id'  => $form_id,
								'field_id' => (int) $operation['field'],
							)
						)
					);
				} elseif ( 'remove' === $op ) {
					self::run(
						'ninja_forms_ability_remove_field',
						array(
							'form_id'  => $form_id,
							'field_id' => (int) $operation['field'],
						)
					);
				} else {
					$ids = array_values( array_diff( array_map( static fn( $f ) => (int) $f->get_id(), self::fields( $form_id ) ), array( (int) $operation['field'] ) ) );
					array_splice( $ids, min( (int) $operation['index'], count( $ids ) ), 0, array( (int) $operation['field'] ) );
					$order = array();
					foreach ( $ids as $n => $id ) {
						$order[ $id ] = $n + 1;
					}
					self::run(
						'ninja_forms_ability_reorder_fields',
						array(
							'form_id'     => $form_id,
							'field_order' => $order,
						)
					);
				}//end if
			}//end foreach
		} catch ( ToolError $e ) {
			$this->restore( $form_id, $before );
			throw ToolError::of( ToolError::VALIDATION_FAILED, $e->getMessage() . ' The operations already applied were undone; nothing was changed.' );
		}//end try
		\WPN_Helper::delete_nf_cache( $form_id );
		return $added;
	}

	/**
	 * Title, submit text and success message.
	 *
	 * @param int                  $form_id   Form id.
	 * @param array<string, mixed> $operation Form operation.
	 * @throws ToolError When the form has no submit button or success message to change.
	 */
	private function form_settings( int $form_id, array $operation ): void {
		if ( isset( $operation['title'] ) ) {
			self::model( $form_id )->update_setting( 'title', sanitize_text_field( (string) $operation['title'] ) )->save();
		}
		if ( isset( $operation['submit_text'] ) ) {
			$submit = null;
			foreach ( self::fields( $form_id ) as $field ) {
				if ( 'submit' === $field->get_setting( 'type' ) ) {
					$submit = $field;
					break;
				}
			}
			if ( null === $submit ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The form has no submit button.' );
			}
			self::run(
				'ninja_forms_ability_update_field',
				array(
					'form_id'  => $form_id,
					'field_id' => (int) $submit->get_id(),
					'label'    => sanitize_text_field( (string) $operation['submit_text'] ),
				)
			);
		}
		if ( isset( $operation['confirmation_message'] ) ) {
			$input = array(
				'form_id'     => $form_id,
				'success_msg' => wp_kses_post( (string) $operation['confirmation_message'] ),
			);
			foreach ( self::actions( $form_id ) as $action ) {
				if ( 'successmessage' === $action->get_setting( 'type' ) ) {
					self::run( 'ninja_forms_ability_update_action', $input + array( 'action_id' => (int) $action->get_id() ) );
					return;
				}
			}
			self::run( 'ninja_forms_ability_add_action', $input + array( 'type' => 'successmessage' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $title Title.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function create( string $title ): int {
		// Ninja's own create-form ability: the form with its submit button, success message, admin email and the
		// action that stores submissions.
		$result = self::run( 'ninja_forms_ability_create_form', array( 'title' => sanitize_text_field( $title ) ) );
		$id     = (int) ( $result['form_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Ninja Forms did not create the form.' );
		}
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function snapshot( int $form_id ): array {
		$fields = array();
		foreach ( self::fields( $form_id ) as $field ) {
			$fields[ (string) $field->get_id() ] = (array) $field->get_settings();
		}
		$actions = array();
		foreach ( self::actions( $form_id ) as $action ) {
			$actions[ (string) $action->get_id() ] = (array) $action->get_settings();
		}
		return array(
			'settings' => (array) self::model( $form_id )->get_settings(),
			'fields'   => $fields,
			'actions'  => $actions,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $state   Snapshot.
	 */
	public function restore( int $form_id, array $state ): void {
		self::model( $form_id )->update_settings( (array) ( $state['settings'] ?? array() ) )->save();
		$saved = (array) ( $state['fields'] ?? array() );
		foreach ( self::fields( $form_id ) as $field ) {
			$id = (string) $field->get_id();
			if ( ! isset( $saved[ $id ] ) ) {
				self::run(
					'ninja_forms_ability_remove_field',
					array(
						'form_id'  => $form_id,
						'field_id' => (int) $id,
					)
				);
				continue;
			}
			$field->update_settings( (array) $saved[ $id ] )->save();
			unset( $saved[ $id ] );
		}
		// Fields removed since the snapshot come back with a new id; their key (used by merge tags) is kept.
		foreach ( $saved as $settings ) {
			$field = Ninja_Forms()->form( $form_id )->field()->get();
			$field->update_settings( (array) $settings );
			$field->update_setting( 'parent_id', $form_id );
			$field->save();
		}
		foreach ( (array) ( $state['actions'] ?? array() ) as $id => $settings ) {
			$action = self::factory( $form_id )->get_action( (int) $id );
			if ( is_object( $action ) && $action->get_id() ) {
				$action->update_settings( (array) $settings )->save();
			}
		}
		\WPN_Helper::delete_nf_cache( $form_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function notifications( int $form_id ): array {
		self::model( $form_id );
		$out = array();
		foreach ( self::actions( $form_id ) as $action ) {
			if ( 'email' !== $action->get_setting( 'type' ) ) {
				continue;
			}
			$row = array(
				'id'      => (string) $action->get_id(),
				'enabled' => ! empty( $action->get_setting( 'active' ) ),
			);
			foreach ( self::NOTIFICATION as $key => $name ) {
				$row[ $key ] = (string) $action->get_setting( $name );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param string|null          $id      Action id.
	 * @param array<string, mixed> $values  Values.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function save_notification( int $form_id, ?string $id, array $values ): string {
		$input = array( 'form_id' => $form_id );
		$names = array(
			'name'       => 'label',
			'to'         => 'to',
			'from_name'  => 'from_name',
			'from_email' => 'from_address',
			'reply_to'   => 'reply_to',
			'cc'         => 'cc',
			'bcc'        => 'bcc',
			'subject'    => 'subject',
			'message'    => 'message',
		);
		foreach ( $names as $key => $name ) {
			if ( array_key_exists( $key, $values ) ) {
				$input[ $name ] = 'message' === $key ? wp_kses_post( (string) $values[ $key ] ) : sanitize_text_field( (string) $values[ $key ] );
			}
		}
		if ( array_key_exists( 'enabled', $values ) ) {
			$input['active'] = $values['enabled'] ? 1 : 0;
		}
		if ( null === $id ) {
			$result = self::run( 'ninja_forms_ability_add_action', $input + array( 'type' => 'email' ) );
			return (string) $result['action_id'];
		}
		$found = array_filter( self::actions( $form_id ), static fn( $a ) => (string) $a->get_id() === $id && 'email' === $a->get_setting( 'type' ) );
		if ( ! $found ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Ninja Forms form ' . $form_id . ' has no email action ' . $id . '. Nothing was changed.' );
		}
		// Ninja's update-action ability sets only the values it is given (empty strings included, unlike add).
		self::run( 'ninja_forms_ability_update_action', $input + array( 'action_id' => (int) $id ) );
		\WPN_Helper::delete_nf_cache( $form_id );
		return $id;
	}
}
