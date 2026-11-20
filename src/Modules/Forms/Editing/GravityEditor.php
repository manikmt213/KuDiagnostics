<?php
/**
 * Gravity Forms form editor (phase L1, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits Gravity Forms forms (read on Gravity Forms 3.2.2 on the owner's site, 2026-11-18: includes/api.php and
 * includes/abilities). With Gravity Forms 3.1+ the saves go through Gravity's own ability handlers
 * (GF_Abilities_Handler_Forms::create_form() / update_form()): they give a new form the editor's admin notification,
 * prepare compound fields (name, address, …) with the editor's defaults, merge supplied fields over the stored ones
 * and normalize layout groups before calling GFAPI. Those handlers are public static methods whose ability
 * registration Gravity gates with its MCP settings and whose capability checks live in the abilities' permission
 * callbacks, so this editor checks Gravity's capabilities itself (gravityforms_create_form, gravityforms_edit_forms).
 * On older versions it saves with GFAPI::add_form() / update_form() and builds fields with GF_Fields::create().
 * Notifications and confirmations are the form's own arrays, saved with GFAPI::update_form(). Built from the source
 * on the owner's site and tested against a stand-in; not yet run against the licensed plugin in Playground.
 */
final class GravityEditor implements FormEditor {

	/**
	 * Properties every field takes: Gravity's own names, except required (isRequired) and choices ({label, value} for
	 * Gravity's {text, value}), named as the other form editors name them.
	 */
	private const COMMON = array( 'label', 'adminLabel', 'required', 'description', 'cssClass', 'size' );

	/**
	 * Types with choices.
	 */
	private const CHOICES = array( 'select', 'multiselect', 'radio', 'checkbox', 'multi_choice', 'image_choice' );

	/**
	 * Types with an input box.
	 */
	private const INPUT = array( 'text', 'textarea', 'number', 'email', 'phone', 'website', 'date', 'time', 'select' );

	/**
	 * Notification keys: tool name => Gravity name.
	 */
	private const NOTIFICATION = array(
		'name'       => 'name',
		'to'         => 'to',
		'from_name'  => 'fromName',
		'from_email' => 'from',
		'reply_to'   => 'replyTo',
		'cc'         => 'cc',
		'bcc'        => 'bcc',
		'subject'    => 'subject',
		'message'    => 'message',
	);

	/**
	 * Gravity's own form handler class (3.1+).
	 */
	private const HANDLER = 'GF_Abilities_Handler_Forms';

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'gravity-forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Gravity Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		return class_exists( 'GFAPI' ) && class_exists( 'GF_Fields' ) && class_exists( 'GFCommon' ) ? true : 'Gravity Forms is not active on this site.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create(): bool {
		return (bool) \GFCommon::current_user_can_any( 'gravityforms_create_form' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool {
		return (bool) \GFCommon::current_user_can_any( 'gravityforms_edit_forms' );
	}

	/**
	 * Properties a type takes.
	 *
	 * @param string $type Type.
	 * @return list<string>
	 */
	private static function properties_of( string $type ): array {
		$out = self::COMMON;
		if ( in_array( $type, self::CHOICES, true ) ) {
			$out[] = 'choices';
		}
		if ( in_array( $type, self::INPUT, true ) ) {
			$out = array_merge( $out, array( 'placeholder', 'defaultValue' ) );
		}
		if ( in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$out[] = 'maxLength';
		}
		if ( 'html' === $type ) {
			$out = array( 'label', 'content', 'cssClass' );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function field_types(): array {
		$out = array();
		foreach ( array_keys( (array) \GF_Fields::get_all() ) as $type ) {
			$type = (string) $type;
			// Live check on 3.2.2: submit, honeypot, password and repeater also need settings or handling this tool does not do.
			if ( in_array( $type, array( 'submit', 'honeypot', 'password', 'repeater', 'price', 'list', 'image_choice', 'page', 'section', 'captcha', 'creditcard', 'post_title', 'post_content', 'post_excerpt', 'post_tags', 'post_category', 'post_image', 'post_custom_field', 'product', 'quantity', 'option', 'shipping', 'total', 'donation', 'calculation', 'hiddenproduct', 'singleproduct', 'singleshipping' ), true ) ) {
				// Page breaks, sections, post and pricing fields need settings this tool does not set.
				continue;
			}
			$out[] = array(
				'type'       => $type,
				'label'      => ucfirst( $type ),
				'properties' => self::properties_of( $type ),
			);
		}
		return $out;
	}

	/**
	 * The stored form as an array (fields as arrays).
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such form.
	 */
	private static function data( int $form_id ): array {
		$form = \GFAPI::get_form( $form_id );
		if ( ! is_array( $form ) || ! empty( $form['is_trash'] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Gravity Forms has no form ' . $form_id . '.' );
		}
		$form['fields'] = array_values( array_map( static fn( $f ) => (array) json_decode( (string) wp_json_encode( $f ), true ), (array) ( $form['fields'] ?? array() ) ) );
		return $form;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function form( int $form_id ): array {
		$form   = self::data( $form_id );
		$fields = array();
		foreach ( $form['fields'] as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$row  = array(
				'id'       => (string) ( $field['id'] ?? '' ),
				'type'     => $type,
				'label'    => (string) ( $field['label'] ?? '' ),
				'required' => ! empty( $field['isRequired'] ),
			);
			foreach ( self::properties_of( $type ) as $property ) {
				if ( ! isset( $row[ $property ] ) && isset( $field[ $property ] ) && '' !== $field[ $property ] && array() !== $field[ $property ] ) {
					$row[ $property ] = 'choices' === $property
						? array_map(
							static fn( $c ) => array(
								'label' => (string) ( $c['text'] ?? '' ),
								'value' => (string) ( $c['value'] ?? '' ),
							),
							(array) $field['choices']
						)
						: $field[ $property ];
				}
			}
			$fields[] = $row;
		}//end foreach
		$confirmation = null;
		foreach ( (array) ( $form['confirmations'] ?? array() ) as $item ) {
			if ( ! empty( $item['isDefault'] ) ) {
				$confirmation = 'message' === ( $item['type'] ?? 'message' ) ? (string) ( $item['message'] ?? '' ) : null;
			}
		}
		return array(
			'id'                   => $form_id,
			'title'                => (string) ( $form['title'] ?? '' ),
			'fields'               => $fields,
			'submit_text'          => (string) ( $form['button']['text'] ?? '' ),
			'confirmation_message' => $confirmation,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @throws ToolError When a type or property is not offered.
	 */
	public function check_properties( string $type, array $properties ): void {
		if ( ! in_array( $type, array_column( $this->field_types(), 'type' ), true ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Gravity Forms offers no field type "' . $type . '" here (see kuduy-gravity-forms-field-types).' );
		}
		$allowed = self::properties_of( $type );
		foreach ( $properties as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' field does not take ' . $key . ' (it takes ' . implode( ', ', $allowed ) . ').' );
			}
			$ok = match ( $key ) {
				'required'   => is_bool( $value ),
				'choices'    => is_array( $value ) && $value && count( array_filter( $value, static fn( $c ) => is_array( $c ) && isset( $c['label'] ) && is_string( $c['label'] ) ) ) === count( $value ),
				'size'       => in_array( $value, array( 'small', 'medium', 'large' ), true ),
				'maxLength'  => is_int( $value ) && $value > 0,
				default      => is_string( $value ) || is_numeric( $value ),
			};
			if ( ! $ok ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $key . ' has the wrong kind of value.' );
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
		return (int) \GFAPI::count_entries(
			$form_id,
			array(
				'status'        => 'active',
				'field_filters' => array(
					array(
						'key'      => $field_id,
						'operator' => 'isnot',
						'value'    => '',
					),
				),
			)
		);
	}

	/**
	 * A field array with tool properties applied (sanitized).
	 *
	 * @param array<string, mixed> $field      Field.
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function set( array $field, array $properties ): array {
		foreach ( $properties as $key => $value ) {
			if ( 'choices' === $key ) {
				$field['choices'] = array_map(
					static fn( $c ) => array(
						'text'       => sanitize_text_field( (string) $c['label'] ),
						'value'      => sanitize_text_field( (string) ( $c['value'] ?? $c['label'] ) ),
						'isSelected' => false,
						'price'      => '',
					),
					(array) $value
				);
			} elseif ( 'required' === $key ) {
				$field['isRequired'] = (bool) $value;
			} elseif ( in_array( $key, array( 'description', 'content' ), true ) ) {
				$field[ $key ] = wp_kses_post( (string) $value );
			} else {
				$field[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}
		return $field;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations.
	 * @throws ToolError When a field is missing or Gravity refuses.
	 */
	public function apply( int $form_id, array $operations ): array {
		$form   = self::data( $form_id );
		$fields = $form['fields'];
		$next   = $fields ? max( array_map( static fn( $f ) => (int) ( $f['id'] ?? 0 ), $fields ) ) + 1 : 1;
		$next   = max( $next, (int) ( $form['nextFieldId'] ?? 0 ) );
		$added  = array();
		$update = array( 'id' => $form_id );
		foreach ( $operations as $operation ) {
			$op = (string) $operation['op'];
			if ( 'form' === $op ) {
				if ( isset( $operation['title'] ) ) {
					$update['title'] = sanitize_text_field( (string) $operation['title'] );
				}
				if ( isset( $operation['submit_text'] ) ) {
					$button           = (array) ( $form['button'] ?? array( 'type' => 'text' ) );
					$button['text']   = sanitize_text_field( (string) $operation['submit_text'] );
					$update['button'] = $button;
				}
				if ( isset( $operation['confirmation_message'] ) ) {
					$confirmations = (array) ( $form['confirmations'] ?? array() );
					$key           = null;
					foreach ( $confirmations as $id => $item ) {
						if ( ! empty( $item['isDefault'] ) ) {
							$key = $id;
						}
					}
					if ( null === $key ) {
						$key                   = (string) uniqid();
						$confirmations[ $key ] = array(
							'id'        => $key,
							'name'      => 'Default Confirmation',
							'isDefault' => true,
						);
					}
					// A text confirmation, as Gravity's default; a page or redirect confirmation is replaced by it.
					$confirmations[ $key ]['type']    = 'message';
					$confirmations[ $key ]['message'] = wp_kses_post( (string) $operation['confirmation_message'] );
					$update['confirmations']          = $confirmations;
				}//end if
				continue;
			}//end if
			if ( 'add' === $op ) {
				$field = self::set(
					array(
						'id'     => $next,
						'type'   => (string) $operation['type'],
						'formId' => $form_id,
						'label'  => ucfirst( (string) $operation['type'] ),
					),
					(array) ( $operation['properties'] ?? array() )
				);
				$index = min( (int) ( $operation['index'] ?? count( $fields ) ), count( $fields ) );
				array_splice( $fields, $index, 0, array( $field ) );
				$added[] = (string) $next;
				++$next;
				continue;
			}
			$at = null;
			foreach ( $fields as $n => $field ) {
				if ( (string) ( $field['id'] ?? '' ) === (string) $operation['field'] ) {
					$at = $n;
				}
			}
			if ( null === $at ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Gravity Forms form has no field ' . $operation['field'] . '. Nothing was changed.' );
			}
			if ( 'update' === $op ) {
				$fields[ $at ] = self::set( $fields[ $at ], (array) $operation['properties'] );
				continue;
			}
			$field = $fields[ $at ];
			array_splice( $fields, $at, 1 );
			if ( 'move' === $op ) {
				array_splice( $fields, min( (int) $operation['index'], count( $fields ) ), 0, array( $field ) );
			}
		}//end foreach
		$update['fields'] = array_values( $fields );
		self::save( $form, $update );
		return $added;
	}

	/**
	 * Save changes: Gravity's own handler when present, else GFAPI with field objects.
	 *
	 * @param array<string, mixed> $form   Stored form.
	 * @param array<string, mixed> $update Changed keys (id included).
	 * @throws ToolError When Gravity refuses.
	 */
	private static function save( array $form, array $update ): void {
		if ( class_exists( self::HANDLER ) && is_callable( array( self::HANDLER, 'update_form' ) ) ) {
			$result = call_user_func( array( self::HANDLER, 'update_form' ), array( 'form' => $update ) );
		} else {
			$merged           = array_merge( $form, $update );
			$merged['fields'] = array_map( static fn( $f ) => \GF_Fields::create( $f ), (array) $merged['fields'] );
			$result           = \GFAPI::update_form( $merged );
		}
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Gravity Forms refused the change: ' . $result->get_error_message() . ' Nothing was changed.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $title Title.
	 * @throws ToolError When Gravity does not create the form.
	 */
	public function create( string $title ): int {
		$form = array(
			'title'  => sanitize_text_field( $title ),
			'fields' => array(),
			'button' => array(
				'type' => 'text',
				'text' => __( 'Submit', 'kuduy-mcp' ),
			),
		);
		if ( class_exists( self::HANDLER ) && is_callable( array( self::HANDLER, 'create_form' ) ) ) {
			$result = call_user_func( array( self::HANDLER, 'create_form' ), array( 'form' => $form ) );
			$id     = is_array( $result ) ? (int) ( $result['form_id'] ?? 0 ) : 0;
		} else {
			$result = \GFAPI::add_form( $form );
			$id     = is_int( $result ) ? $result : 0;
		}
		if ( is_wp_error( $result ) || $id <= 0 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Gravity Forms did not create the form' . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '.' ) );
		}
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function snapshot( int $form_id ): array {
		return array( 'form' => self::data( $form_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $state   Snapshot.
	 * @throws ToolError When Gravity refuses.
	 */
	public function restore( int $form_id, array $state ): void {
		self::data( $form_id );
		$form           = (array) ( $state['form'] ?? array() );
		$form['fields'] = array_map( static fn( $f ) => \GF_Fields::create( $f ), (array) ( $form['fields'] ?? array() ) );
		$result         = \GFAPI::update_form( $form, $form_id );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Gravity Forms did not put the form back: ' . $result->get_error_message() );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function notifications( int $form_id ): array {
		$out = array();
		foreach ( (array) ( self::data( $form_id )['notifications'] ?? array() ) as $id => $n ) {
			$row = array(
				'id'      => (string) $id,
				'enabled' => ! isset( $n['isActive'] ) || ! empty( $n['isActive'] ),
			);
			foreach ( self::NOTIFICATION as $key => $name ) {
				$row[ $key ] = (string) ( $n[ $name ] ?? '' );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param string|null          $id      Notification id.
	 * @param array<string, mixed> $values  Values.
	 * @throws ToolError When Gravity refuses.
	 */
	public function save_notification( int $form_id, ?string $id, array $values ): string {
		$form          = self::data( $form_id );
		$notifications = (array) ( $form['notifications'] ?? array() );
		if ( null === $id ) {
			$id                   = (string) uniqid();
			$notifications[ $id ] = array(
				'id'      => $id,
				'name'    => 'Notification',
				'event'   => 'form_submission',
				'toType'  => 'email',
				'message' => '{all_fields}',
			);
		} elseif ( ! isset( $notifications[ $id ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Gravity Forms form ' . $form_id . ' has no notification ' . $id . '. Nothing was changed.' );
		}
		foreach ( self::NOTIFICATION as $key => $name ) {
			if ( array_key_exists( $key, $values ) ) {
				$notifications[ $id ][ $name ] = 'message' === $key ? wp_kses_post( (string) $values[ $key ] ) : sanitize_text_field( (string) $values[ $key ] );
			}
		}
		if ( array_key_exists( 'to', $values ) ) {
			// An email address list, as the notification screen's "Enter Email" choice.
			$notifications[ $id ]['toType'] = 'email';
		}
		if ( array_key_exists( 'enabled', $values ) ) {
			$notifications[ $id ]['isActive'] = (bool) $values['enabled'];
		}
		$form['notifications'] = $notifications;
		$form['fields']        = array_map( static fn( $f ) => \GF_Fields::create( $f ), $form['fields'] );
		$result                = \GFAPI::update_form( $form, $form_id );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Gravity Forms refused the notification: ' . $result->get_error_message() . ' Nothing was changed.' );
		}
		return $id;
	}
}
