<?php
/**
 * Formidable Forms form editor (phase B2, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits Formidable forms with the controllers Formidable's own abilities run (read on Formidable 7.0,
 * classes/controllers/FrmAbilitiesFieldsController.php, FrmAbilitiesFormsController.php and
 * FrmAbilitiesFormActionsController.php, 2026-11-18): execute_create_field() (FrmFieldsHelper::setup_new_vars()
 * defaults, separate option values, FrmField::create()), execute_update_field() (placeholder kept in
 * field_options, options merged), execute_delete_field() (FrmField::destroy(), which also deletes every entry's
 * value for the field, so KuDiagnostics only reaches it when no entry holds one), execute_create_form() /
 * execute_update_form() (options merged over the stored ones) and the form-action controller for email
 * notifications and the On Submit message (each action type's own update() before save_settings()). Their
 * set_current_user() only acts when nobody is logged in, which a tool call never is. Formidable's caches are
 * cleared by those calls. Permission: Formidable's own ability checks (frm_edit_forms, or an administrator).
 */
final class FormidableEditor implements FormEditor {

	private const FIELDS  = 'FrmAbilitiesFieldsController';
	private const FORMS   = 'FrmAbilitiesFormsController';
	private const ACTIONS = 'FrmAbilitiesFormActionsController';

	/**
	 * Properties every type takes, and those only some take.
	 */
	private const COMMON = array( 'name', 'description', 'required', 'default_value' );

	/**
	 * Types with choices.
	 */
	private const CHOICES = array( 'select', 'radio', 'checkbox' );

	/**
	 * Types without an input box (no placeholder, no default value, never required).
	 */
	private const NO_INPUT = array( 'html', 'divider', 'break', 'end_divider', 'captcha', 'hidden', 'summary' );

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'formidable';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Formidable Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		if ( ! class_exists( 'FrmForm' ) || ! class_exists( 'FrmField' ) ) {
			return 'Formidable Forms is not active on this site.';
		}
		return class_exists( self::FIELDS ) && class_exists( self::FORMS ) && class_exists( self::ACTIONS ) && class_exists( 'FrmAbilitiesHelper' )
			? true
			: 'This Formidable version has no ability controllers for programmatic edits (Formidable 7.0 or later is needed to edit forms here).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create(): bool {
		// Formidable's own ability permission: frm_edit_forms, or an administrator.
		return (bool) call_user_func( array( self::FORMS, 'can_create_form' ), array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool {
		return (bool) call_user_func( array( self::FORMS, 'can_update_form' ), array( 'id' => $form_id ) );
	}

	/**
	 * Run one of Formidable's ability controller methods.
	 *
	 * @param string               $controller Controller.
	 * @param string               $method     Method.
	 * @param array<string, mixed> $input      Input.
	 * @return array<string, mixed>
	 * @throws ToolError When Formidable refuses.
	 */
	private static function run( string $controller, string $method, array $input ): array {
		$result = call_user_func( array( $controller, $method ), $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Formidable refused: ' . $result->get_error_message() );
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Formidable creates and upgrades its tables when an admin page loads (FrmAppController::admin_init). After it is
	 * activated over the API no admin page has loaded, and FrmForm::create() fails ("Form creation failed"; found on
	 * the live site, 2026-11-21, Formidable 7.1.1). Run Formidable's own silent upgrade, the one its REST route and
	 * ajax installer use (`api_install()`: only when `needs_update()`, guarded against running twice).
	 */
	private static function ensure_database(): void {
		if ( is_callable( array( 'FrmAppController', 'needs_update' ) ) && is_callable( array( 'FrmAppController', 'api_install' ) ) && call_user_func( array( 'FrmAppController', 'needs_update' ) ) ) {
			call_user_func( array( 'FrmAppController', 'api_install' ) );
		}
	}

	/**
	 * Types that can be added, with their labels.
	 *
	 * @return array<string, string>
	 */
	private static function types(): array {
		$labels = (array) call_user_func( array( 'FrmField', 'all_field_selection' ) );
		$out    = array();
		foreach ( (array) call_user_func( array( 'FrmAbilitiesHelper', 'get_creatable_field_types' ) ) as $type ) {
			if ( in_array( $type, array( 'dropdown', 'star_rating', 'section' ), true ) ) {
				// Aliases Formidable's abilities accept for select, star and divider.
				continue;
			}
			$label        = $labels[ $type ] ?? $type;
			$out[ $type ] = is_array( $label ) ? (string) ( $label['name'] ?? $type ) : (string) $label;
		}
		return $out;
	}

	/**
	 * Properties of a type.
	 *
	 * @param string $type Type.
	 * @return list<string>
	 */
	private static function properties_of( string $type ): array {
		if ( in_array( $type, self::NO_INPUT, true ) ) {
			return 'hidden' === $type ? array( 'name', 'description', 'default_value' ) : array( 'name', 'description' );
		}
		$out = self::COMMON;
		if ( in_array( $type, self::CHOICES, true ) ) {
			$out[] = 'options';
		} else {
			$out[] = 'placeholder';
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function field_types(): array {
		$out = array();
		foreach ( self::types() as $type => $label ) {
			$out[] = array(
				'type'       => $type,
				'label'      => $label,
				'properties' => self::properties_of( $type ),
			);
		}
		return $out;
	}

	/**
	 * The form row.
	 *
	 * @param int $form_id Form id.
	 * @throws ToolError When there is no such form.
	 */
	private static function row( int $form_id ): object {
		$form = call_user_func( array( 'FrmForm', 'getOne' ), $form_id );
		if ( ! is_object( $form ) || 'trash' === ( $form->status ?? '' ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Formidable has no form ' . $form_id . '.' );
		}
		return $form;
	}

	/**
	 * Field rows in order.
	 *
	 * @param int $form_id Form id.
	 * @return list<object>
	 */
	private static function fields( int $form_id ): array {
		$fields = (array) call_user_func( array( 'FrmField', 'get_all_for_form' ), $form_id );
		$fields = array_values( array_filter( $fields, 'is_object' ) );
		usort( $fields, static fn( $a, $b ) => (int) $a->field_order <=> (int) $b->field_order );
		return $fields;
	}

	/**
	 * Form actions of a type (any status).
	 *
	 * @param int    $form_id Form id.
	 * @param string $type    Action type.
	 * @return list<object>
	 */
	private static function actions( int $form_id, string $type ): array {
		$actions = (array) call_user_func( array( 'FrmFormAction', 'get_action_for_form' ), $form_id, $type, array( 'post_status' => 'any' ) );
		return array_values( array_filter( $actions, 'is_object' ) );
	}

	/**
	 * Choices of a field.
	 *
	 * @param mixed $options Stored options.
	 * @return list<array{label:string, value:string}>
	 */
	private static function choices( mixed $options ): array {
		$out = array();
		foreach ( is_array( $options ) ? $options : array() as $option ) {
			$out[] = is_array( $option )
				? array(
					'label' => (string) ( $option['label'] ?? '' ),
					'value' => (string) ( $option['value'] ?? $option['label'] ?? '' ),
				)
				: array(
					'label' => (string) $option,
					'value' => (string) $option,
				);
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function form( int $form_id ): array {
		$form   = self::row( $form_id );
		$fields = array();
		foreach ( self::fields( $form_id ) as $field ) {
			$type    = (string) $field->type;
			$options = (array) $field->field_options;
			$row     = array(
				'id'       => (string) $field->id,
				'type'     => $type,
				'label'    => (string) $field->name,
				'required' => ! empty( $field->required ),
				'key'      => (string) $field->field_key,
			);
			$props   = self::properties_of( $type );
			if ( in_array( 'description', $props, true ) && '' !== (string) $field->description ) {
				$row['description'] = (string) $field->description;
			}
			if ( in_array( 'placeholder', $props, true ) && ! empty( $options['placeholder'] ) ) {
				$row['placeholder'] = (string) $options['placeholder'];
			}
			if ( in_array( 'default_value', $props, true ) && '' !== (string) ( is_scalar( $field->default_value ) ? $field->default_value : '' ) ) {
				$row['default_value'] = (string) $field->default_value;
			}
			if ( in_array( 'options', $props, true ) ) {
				$row['options'] = self::choices( $field->options );
			}
			$fields[] = $row;
		}//end foreach
		$options = (array) $form->options;
		$message = null;
		$submit  = self::actions( $form_id, 'on_submit' );
		if ( $submit ) {
			$content = (array) call_user_func( array( 'FrmAppHelper', 'maybe_json_decode' ), $submit[0]->post_content );
			$message = 'message' === ( $content['success_action'] ?? 'message' ) ? (string) ( $content['success_msg'] ?? '' ) : null;
		} elseif ( 'message' === ( $options['success_action'] ?? 'message' ) ) {
			$message = (string) ( $options['success_msg'] ?? '' );
		}
		return array(
			'id'                   => $form_id,
			'title'                => (string) $form->name,
			'fields'               => $fields,
			'submit_text'          => (string) ( $options['submit_value'] ?? '' ),
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
		if ( ! isset( self::types()[ $type ] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Formidable offers no field type "' . $type . '" on this site.' );
		}
		$allowed = self::properties_of( $type );
		foreach ( $properties as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' field does not take ' . $key . ' (it takes ' . implode( ', ', $allowed ) . ').' );
			}
			$ok = match ( $key ) {
				'required' => is_bool( $value ),
				'options'  => is_array( $value ) && $value && count( array_filter( $value, static fn( $o ) => is_string( $o ) || ( is_array( $o ) && isset( $o['label'] ) && is_string( $o['label'] ) ) ) ) === count( $value ),
				default    => is_string( $value ) || is_numeric( $value ),
			};
			if ( ! $ok ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $key . ' has the wrong kind of value (' . ( 'options' === $key ? 'a list of {"label","value"}' : ( 'required' === $key ? 'true or false' : 'text' ) ) . ').' );
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
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT item_id) FROM {$wpdb->prefix}frm_item_metas WHERE field_id = %d AND meta_value <> ''", (int) $field_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only count in Formidable's entry values.
	}

	/**
	 * Input for Formidable's field controller from tool properties.
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function field_input( array $properties ): array {
		$input = array();
		foreach ( $properties as $key => $value ) {
			$input[ $key ] = match ( $key ) {
				'required'    => $value ? 1 : 0,
				'options'     => array_map(
					static fn( $o ) => is_array( $o )
						? array(
							'label' => sanitize_text_field( (string) $o['label'] ),
							'value' => sanitize_text_field( (string) ( $o['value'] ?? $o['label'] ) ),
						)
						: sanitize_text_field( (string) $o ),
					(array) $value
				),
				'description' => wp_kses_post( (string) $value ),
				default       => sanitize_text_field( (string) $value ),
			};
		}
		return $input;
	}

	/**
	 * Write field_order 1..n in the given order.
	 *
	 * @param list<int> $ids Field ids in order.
	 */
	private static function order( array $ids ): void {
		foreach ( $ids as $n => $id ) {
			self::run(
				self::FIELDS,
				'execute_update_field',
				array(
					'id'          => $id,
					'field_order' => $n + 1,
				)
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function apply( int $form_id, array $operations ): array {
		self::ensure_database();
		$before = $this->snapshot( $form_id );
		$added  = array();
		try {
			foreach ( $operations as $operation ) {
				$op = (string) $operation['op'];
				if ( 'form' === $op ) {
					$this->form_settings( $form_id, $operation );
					continue;
				}
				$ids = array_map( static fn( $f ) => (int) $f->id, self::fields( $form_id ) );
				if ( 'add' === $op ) {
					$made    = self::run(
						self::FIELDS,
						'execute_create_field',
						array_merge(
							self::field_input( (array) ( $operation['properties'] ?? array() ) ),
							array(
								'form_id' => $form_id,
								'type'    => (string) $operation['type'],
							)
						)
					);
					$id      = (int) ( $made['id'] ?? 0 );
					$added[] = (string) $id;
					if ( isset( $operation['index'] ) ) {
						array_splice( $ids, min( (int) $operation['index'], count( $ids ) ), 0, array( $id ) );
						self::order( $ids );
					}
				} elseif ( 'update' === $op ) {
					self::run( self::FIELDS, 'execute_update_field', array_merge( self::field_input( (array) $operation['properties'] ), array( 'id' => (int) $operation['field'] ) ) );
				} elseif ( 'remove' === $op ) {
					self::run(
						self::FIELDS,
						'execute_delete_field',
						array(
							'id'      => (int) $operation['field'],
							'form_id' => $form_id,
						)
					);
				} else {
					$ids = array_values( array_diff( $ids, array( (int) $operation['field'] ) ) );
					array_splice( $ids, min( (int) $operation['index'], count( $ids ) ), 0, array( (int) $operation['field'] ) );
					self::order( $ids );
				}//end if
			}//end foreach
		} catch ( ToolError $e ) {
			$this->restore( $form_id, $before );
			throw ToolError::of( ToolError::VALIDATION_FAILED, $e->getMessage() . ' The operations already applied were undone; nothing was changed.' );
		}//end try
		return $added;
	}

	/**
	 * Title, submit button text and On Submit message.
	 *
	 * @param int                  $form_id   Form id.
	 * @param array<string, mixed> $operation Form operation.
	 */
	private function form_settings( int $form_id, array $operation ): void {
		$input = array( 'id' => $form_id );
		if ( isset( $operation['title'] ) ) {
			$input['name'] = (string) $operation['title'];
		}
		if ( isset( $operation['submit_text'] ) ) {
			$input['options']['submit_value'] = sanitize_text_field( (string) $operation['submit_text'] );
		}
		if ( isset( $operation['confirmation_message'] ) ) {
			$message = wp_kses_post( (string) $operation['confirmation_message'] );
			$submit  = self::actions( $form_id, 'on_submit' );
			if ( $submit ) {
				// A message, as Formidable's default On Submit action; a redirect or page is replaced by it.
				self::run(
					self::ACTIONS,
					'execute_update_form_action',
					array(
						'id'           => (int) $submit[0]->ID,
						'post_content' => array(
							'success_action' => 'message',
							'success_msg'    => $message,
						),
					)
				);
			} else {
				$input['options']['success_action'] = 'message';
				$input['options']['success_msg']    = $message;
			}
		}//end if
		if ( count( $input ) > 1 ) {
			self::run( self::FORMS, 'execute_update_form', $input );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $title Title.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function create( string $title ): int {
		self::ensure_database();
		$made = self::run( self::FORMS, 'execute_create_form', array( 'name' => sanitize_text_field( $title ) ) );
		$id   = (int) ( $made['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Formidable did not create the form.' );
		}
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function snapshot( int $form_id ): array {
		$form   = self::row( $form_id );
		$fields = array();
		foreach ( self::fields( $form_id ) as $field ) {
			$fields[ (string) $field->id ] = array(
				'field_key'     => (string) $field->field_key,
				'name'          => (string) $field->name,
				'description'   => (string) $field->description,
				'type'          => (string) $field->type,
				'default_value' => $field->default_value,
				'options'       => $field->options,
				'field_order'   => (int) $field->field_order,
				'required'      => (int) $field->required,
				'field_options' => (array) $field->field_options,
			);
		}
		$actions = array();
		foreach ( array_merge( self::actions( $form_id, 'on_submit' ), self::actions( $form_id, 'email' ) ) as $action ) {
			$actions[ (string) $action->ID ] = array(
				'post_title'   => (string) $action->post_title,
				'post_status'  => (string) $action->post_status,
				'post_content' => (array) call_user_func( array( 'FrmAppHelper', 'maybe_json_decode' ), $action->post_content ),
			);
		}
		return array(
			'name'    => (string) $form->name,
			'options' => (array) $form->options,
			'fields'  => $fields,
			'actions' => $actions,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $state   Snapshot.
	 */
	public function restore( int $form_id, array $state ): void {
		self::row( $form_id );
		self::run(
			self::FORMS,
			'execute_update_form',
			array(
				'id'      => $form_id,
				'name'    => (string) ( $state['name'] ?? '' ),
				'options' => (array) ( $state['options'] ?? array() ),
			)
		);
		$saved = (array) ( $state['fields'] ?? array() );
		foreach ( self::fields( $form_id ) as $field ) {
			$id = (string) $field->id;
			if ( ! isset( $saved[ $id ] ) ) {
				self::run(
					self::FIELDS,
					'execute_delete_field',
					array(
						'id'      => (int) $id,
						'form_id' => $form_id,
					)
				);
				continue;
			}
			$values = (array) $saved[ $id ];
			unset( $values['field_key'], $values['type'] );
			call_user_func( array( 'FrmField', 'update' ), (int) $id, $values );
			unset( $saved[ $id ] );
		}
		// Fields removed since the snapshot come back with a new id and their old key.
		foreach ( $saved as $values ) {
			call_user_func( array( 'FrmField', 'create' ), array_merge( (array) $values, array( 'form_id' => $form_id ) ) );
		}
		foreach ( (array) ( $state['actions'] ?? array() ) as $id => $action ) {
			if ( get_post( (int) $id ) ) {
				self::run( self::ACTIONS, 'execute_update_form_action', array_merge( (array) $action, array( 'id' => (int) $id ) ) );
			}
		}
		call_user_func( array( 'FrmField', 'delete_form_transient' ), $form_id );
		call_user_func( array( 'FrmForm', 'clear_form_cache' ) );
	}

	/**
	 * Split Formidable's "Name <email>" sender.
	 *
	 * @param string $from Sender.
	 * @return array{0:string, 1:string} Name, email.
	 */
	private static function split_from( string $from ): array {
		if ( preg_match( '/^(.*)<([^>]*)>\s*$/', $from, $m ) ) {
			return array( trim( $m[1] ), trim( $m[2] ) );
		}
		return array( '', trim( $from ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function notifications( int $form_id ): array {
		self::row( $form_id );
		$out = array();
		foreach ( self::actions( $form_id, 'email' ) as $action ) {
			$content       = (array) call_user_func( array( 'FrmAppHelper', 'maybe_json_decode' ), $action->post_content );
			list( $n, $e ) = self::split_from( (string) ( $content['from'] ?? '' ) );
			$out[]         = array(
				'id'         => (string) $action->ID,
				'name'       => (string) $action->post_title,
				'enabled'    => 'publish' === $action->post_status,
				'to'         => (string) ( $content['email_to'] ?? '' ),
				'from_name'  => $n,
				'from_email' => $e,
				'reply_to'   => (string) ( $content['reply_to'] ?? '' ),
				'cc'         => (string) ( $content['cc'] ?? '' ),
				'bcc'        => (string) ( $content['bcc'] ?? '' ),
				'subject'    => (string) ( $content['email_subject'] ?? '' ),
				'message'    => (string) ( $content['email_message'] ?? '' ),
			);
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
		$current = null;
		foreach ( $this->notifications( $form_id ) as $notification ) {
			if ( $notification['id'] === $id ) {
				$current = $notification;
			}
		}
		if ( null !== $id && null === $current ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Formidable form ' . $form_id . ' has no email action ' . $id . '. Nothing was changed.' );
		}
		$content = array();
		foreach ( array(
			'to'       => 'email_to',
			'reply_to' => 'reply_to',
			'cc'       => 'cc',
			'bcc'      => 'bcc',
			'subject'  => 'email_subject',
		) as $key => $name ) {
			if ( array_key_exists( $key, $values ) ) {
				$content[ $name ] = sanitize_text_field( (string) $values[ $key ] );
			}
		}
		if ( array_key_exists( 'message', $values ) ) {
			$content['email_message'] = wp_kses_post( (string) $values['message'] );
		}
		if ( array_key_exists( 'from_name', $values ) || array_key_exists( 'from_email', $values ) ) {
			$name            = sanitize_text_field( (string) ( $values['from_name'] ?? $current['from_name'] ?? '' ) );
			$email           = sanitize_text_field( (string) ( $values['from_email'] ?? $current['from_email'] ?? '' ) );
			$content['from'] = '' !== $name ? $name . ' <' . $email . '>' : $email;
		}
		$input = array( 'post_content' => $content );
		if ( array_key_exists( 'name', $values ) ) {
			$input['post_title'] = (string) $values['name'];
		}
		if ( array_key_exists( 'enabled', $values ) ) {
			$input['post_status'] = $values['enabled'] ? 'publish' : 'draft';
		}
		if ( null === $id ) {
			$made = self::run(
				self::ACTIONS,
				'execute_create_form_action',
				array_merge(
					$input,
					array(
						'form_id' => $form_id,
						'type'    => 'email',
					)
				)
			);
			return (string) ( $made['id'] ?? '' );
		}
		self::run( self::ACTIONS, 'execute_update_form_action', array_merge( $input, array( 'id' => (int) $id ) ) );
		return $id;
	}
}
