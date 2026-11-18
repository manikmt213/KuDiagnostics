<?php
/**
 * WPForms form editor (phase B2, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits WPForms forms the way WPForms' own abilities do (read on WPForms Lite 2.0.4,
 * src/Integrations/Abilities/FormMutator.php, 2026-11-18): the form data comes from the form handler
 * (`wpforms()->obj( 'form' )->get( $id, ['content_only' => true] )`), field types and the properties each type
 * takes from WPForms' FieldRegistry (Pro-only types are not available on Lite), new fields start from the
 * `wpforms_field_new_default` filter every field class answers, properties are sanitized by FieldRegistry and
 * form settings by SettingsRegistry, and the result is saved once with the handler's update(), which checks
 * `edit_form_single`, strips tags for users without unfiltered_html and keeps form meta. Notifications are the
 * `settings.notifications` entries; WPForms Lite sends only the first one and has no Bcc.
 */
final class WpformsEditor implements FormEditor {

	private const FIELDS   = '\WPForms\Integrations\Abilities\FieldRegistry';
	private const SETTINGS = '\WPForms\Integrations\Abilities\SettingsRegistry';

	/**
	 * Notification keys: tool name => WPForms name.
	 */
	private const NOTIFICATION = array(
		'name'       => 'notification_name',
		'to'         => 'email',
		'from_name'  => 'sender_name',
		'from_email' => 'sender_address',
		'reply_to'   => 'replyto',
		'cc'         => 'carboncopy',
		'subject'    => 'subject',
		'message'    => 'message',
	);

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		if ( ! function_exists( 'wpforms' ) ) {
			return 'WPForms is not active on this site.';
		}
		return class_exists( ltrim( self::FIELDS, '\\' ) ) && class_exists( ltrim( self::SETTINGS, '\\' ) )
			? true
			: 'This WPForms version has no field registry (WPForms 1.10.2 or later is needed to edit forms here).';
	}

	/**
	 * WPForms' field registry.
	 */
	private static function registry(): object {
		$class = ltrim( self::FIELDS, '\\' );
		return new $class();
	}

	/**
	 * WPForms' form handler.
	 *
	 * @throws ToolError When WPForms has no form handler.
	 */
	private static function handler(): object {
		$handler = wpforms()->obj( 'form' );
		if ( ! is_object( $handler ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WPForms has no form handler loaded.' );
		}
		return $handler;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create(): bool {
		return (bool) wpforms_current_user_can( 'create_forms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool {
		return (bool) wpforms_current_user_can( 'edit_form_single', $form_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function field_types(): array {
		$registry = self::registry();
		$out      = array();
		foreach ( (array) $registry->available_types() as $type ) {
			$out[] = array(
				'type'       => (string) $type,
				'label'      => (string) $registry->get_type_label( (string) $type ),
				'properties' => array_values( array_map( 'strval', (array) $registry->allowed_properties( (string) $type ) ) ),
			);
		}
		return $out;
	}

	/**
	 * The stored form data.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such form.
	 */
	private static function data( int $form_id ): array {
		$post = get_post( $form_id );
		$data = $post instanceof \WP_Post && 'wpforms' === $post->post_type ? self::handler()->get( $form_id, array( 'content_only' => true ) ) : null;
		if ( ! is_array( $data ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'WPForms has no form ' . $form_id . '.' );
		}
		$data['fields']   = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
		$data['settings'] = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function form( int $form_id ): array {
		$data     = self::data( $form_id );
		$registry = self::registry();
		$fields   = array();
		foreach ( $data['fields'] as $id => $field ) {
			$field = (array) $field;
			$type  = (string) ( $field['type'] ?? '' );
			$row   = array(
				'id'       => (string) $id,
				'type'     => $type,
				'label'    => (string) ( $field['label'] ?? '' ),
				'required' => ! empty( $field['required'] ),
			);
			foreach ( (array) $registry->allowed_properties( $type ) as $property ) {
				if ( 'choices' === $property ) {
					$row['choices'] = self::choices( $field );
				} elseif ( ! isset( $row[ $property ] ) && isset( $field[ $property ] ) && '' !== $field[ $property ] ) {
					$row[ $property ] = $field[ $property ];
				}
			}
			$fields[] = $row;
		}
		$confirmation = self::first( (array) ( $data['settings']['confirmations'] ?? array() ) );
		return array(
			'id'                   => $form_id,
			'title'                => (string) ( $data['settings']['form_title'] ?? get_the_title( $form_id ) ),
			'fields'               => $fields,
			'submit_text'          => (string) ( $data['settings']['submit_text'] ?? '' ),
			'confirmation_message' => 'message' === ( $confirmation['type'] ?? 'message' ) ? (string) ( $confirmation['message'] ?? '' ) : null,
			'confirmation_type'    => (string) ( $confirmation['type'] ?? 'message' ),
		);
	}

	/**
	 * Choices of a field as a list.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return list<array{label:string, value:string}>
	 */
	private static function choices( array $field ): array {
		$out = array();
		foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
			$out[] = array(
				'label' => (string) ( $choice['label'] ?? '' ),
				'value' => (string) ( $choice['value'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * First element of a keyed list.
	 *
	 * @param array<mixed> $items List.
	 * @return array<string, mixed>
	 */
	private static function first( array $items ): array {
		$first = reset( $items );
		return is_array( $first ) ? $first : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function check_properties( string $type, array $properties ): void {
		$registry = self::registry();
		if ( ! $registry->is_field_type_available( $type ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'WPForms offers no field type "' . $type . '" on this site.' );
		}
		$sanitized = $registry->sanitize_properties( $type, $properties );
		if ( ! empty( $sanitized['ignored'] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' field does not take ' . implode( ', ', array_map( 'strval', (array) $sanitized['ignored'] ) ) . ' (it takes ' . implode( ', ', array_map( 'strval', (array) $registry->allowed_properties( $type ) ) ) . ').' );
		}
		if ( isset( $properties['choices'] ) && ! $sanitized['values']['choices'] ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'choices needs at least one {"label": …}.' );
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
		if ( ! wpforms()->is_pro() ) {
			return 0;
		}
		$table = $wpdb->prefix . 'wpforms_entry_fields';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existence check of WPForms Pro's table.
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND field_id = %d AND value <> ''", $form_id, (int) $field_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count in WPForms Pro's table; the table name is the prefix plus a constant.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations.
	 */
	public function apply( int $form_id, array $operations ): array {
		$data     = self::data( $form_id );
		$registry = self::registry();
		$fields   = $data['fields'];
		$added    = array();
		foreach ( $operations as $operation ) {
			$op = (string) $operation['op'];
			if ( 'form' === $op ) {
				$data['settings'] = self::form_settings( $data['settings'], $operation );
				continue;
			}
			if ( 'add' === $op ) {
				$type    = (string) $operation['type'];
				$id      = self::next_id( $data, $fields );
				$field   = (array) apply_filters(
					'wpforms_field_new_default', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPForms' own filter, as its builder and abilities call it.
					array(
						'id'          => $id,
						'type'        => $type,
						'label'       => (string) $registry->get_type_label( $type ),
						'description' => '',
					)
				);
				$field   = self::overlay( $field, (array) $registry->sanitize_properties( $type, (array) ( $operation['properties'] ?? array() ) )['values'] );
				$fields  = self::insert( $fields, $id, $field, (int) ( $operation['index'] ?? count( $fields ) ) );
				$added[] = (string) $id;
				continue;
			}
			$id = (int) $operation['field'];
			if ( 'update' === $op ) {
				$type          = (string) ( $fields[ $id ]['type'] ?? '' );
				$fields[ $id ] = self::overlay( (array) $fields[ $id ], (array) $registry->sanitize_properties( $type, (array) $operation['properties'] )['values'] );
				continue;
			}
			$field = $fields[ $id ];
			unset( $fields[ $id ] );
			if ( 'move' === $op ) {
				$fields = self::insert( $fields, $id, (array) $field, (int) $operation['index'] );
			}
		}//end foreach
		$data['fields'] = $fields;
		self::save( $form_id, $data );
		return $added;
	}

	/**
	 * Next field id, as WPForms' FormMutator allocates it (counter or highest id + 1), advancing the counter.
	 *
	 * @param array<string, mixed>     $data   Form data (counter advanced).
	 * @param array<int|string, mixed> $fields Fields so far.
	 */
	private static function next_id( array &$data, array $fields ): int {
		$max              = $fields ? max( array_map( 'absint', array_keys( $fields ) ) ) : 0;
		$id               = max( absint( $data['field_id'] ?? 0 ), $max + 1 );
		$data['field_id'] = $id + 1;
		return $id;
	}

	/**
	 * Field with sanitized properties laid over it (choices kept per index, as WPForms merges them).
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param array<string, mixed> $values Sanitized values.
	 * @return array<string, mixed>
	 */
	private static function overlay( array $field, array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( 'choices' !== $key ) {
				$field[ $key ] = $value;
				continue;
			}
			$old    = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
			$merged = array();
			$n      = 1;
			foreach ( (array) $value as $choice ) {
				$merged[ $n ]          = (array) ( $old[ $n ] ?? array() );
				$merged[ $n ]['label'] = $choice['label'];
				$merged[ $n ]['value'] = $choice['value'];
				++$n;
			}
			$field['choices'] = $merged;
		}
		return $field;
	}

	/**
	 * Fields with one inserted at a position, keys kept.
	 *
	 * @param array<int|string, mixed> $fields Fields.
	 * @param int                      $id     Field id.
	 * @param array<string, mixed>     $field  Field.
	 * @param int                      $index  Position.
	 * @return array<int|string, mixed>
	 */
	private static function insert( array $fields, int $id, array $field, int $index ): array {
		$out = array();
		$n   = 0;
		foreach ( $fields as $key => $value ) {
			if ( $n === $index ) {
				$out[ $id ] = $field;
			}
			$out[ $key ] = $value;
			++$n;
		}
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = $field;
		}
		return $out;
	}

	/**
	 * Settings with title, submit text and confirmation message changed.
	 *
	 * @param array<string, mixed> $settings  Settings.
	 * @param array<string, mixed> $operation Form operation.
	 * @return array<string, mixed>
	 */
	private static function form_settings( array $settings, array $operation ): array {
		$class = ltrim( self::SETTINGS, '\\' );
		$keys  = array_filter(
			array(
				'form_title'  => $operation['title'] ?? null,
				'submit_text' => $operation['submit_text'] ?? null,
			),
			static fn( $v ) => null !== $v
		);
		if ( $keys ) {
			$settings = array_merge( $settings, (array) ( new $class() )->sanitize( $keys )['sanitized'] );
		}
		if ( isset( $operation['confirmation_message'] ) ) {
			$confirmations = isset( $settings['confirmations'] ) && is_array( $settings['confirmations'] ) ? $settings['confirmations'] : array();
			$key           = array_key_first( $confirmations ) ?? 1;
			$confirmation  = (array) ( $confirmations[ $key ] ?? array() );
			// A message confirmation, as WPForms' own default; a page or redirect confirmation is replaced by it.
			$confirmation['type']      = 'message';
			$confirmation['message']   = wp_kses_post( (string) $operation['confirmation_message'] );
			$confirmations[ $key ]     = $confirmation;
			$settings['confirmations'] = $confirmations;
		}
		return $settings;
	}

	/**
	 * Save form data through the handler.
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $data    Form data.
	 * @throws ToolError When WPForms refuses.
	 */
	private static function save( int $form_id, array $data ): void {
		$data['id'] = $form_id;
		// Passed as WPForms' FormMutator::persist() passes it (update() handles slashing itself).
		if ( ! self::handler()->update( $form_id, $data ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WPForms did not save form ' . $form_id . '.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $title Title.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function create( string $title ): int {
		$id = (int) self::handler()->add( sanitize_text_field( $title ), array(), array( 'builder' => false ) );
		if ( $id <= 0 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WPForms did not create the form.' );
		}
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function snapshot( int $form_id ): array {
		return array( 'data' => self::data( $form_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $state   Snapshot.
	 */
	public function restore( int $form_id, array $state ): void {
		self::data( $form_id );
		self::save( $form_id, (array) ( $state['data'] ?? array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function notifications( int $form_id ): array {
		$settings = self::data( $form_id )['settings'];
		$on       = ! empty( $settings['notification_enable'] );
		$out      = array();
		$n        = 0;
		foreach ( (array) ( $settings['notifications'] ?? array() ) as $id => $notification ) {
			$notification = (array) $notification;
			$row          = array(
				'id'      => (string) $id,
				'enabled' => $on && ( ! isset( $notification['enable'] ) || 0 !== (int) $notification['enable'] ),
			);
			foreach ( self::NOTIFICATION as $key => $name ) {
				$row[ $key ] = (string) ( $notification[ $name ] ?? '' );
			}
			$row['bcc'] = '';
			if ( $n > 0 && ! wpforms()->is_pro() ) {
				$row['note'] = 'WPForms Lite sends only the first notification.';
			}
			$out[] = $row;
			++$n;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param string|null          $id      Notification id.
	 * @param array<string, mixed> $values  Values.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function save_notification( int $form_id, ?string $id, array $values ): string {
		if ( ! empty( $values['bcc'] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'WPForms notifications have no Bcc (use cc; WPForms sends Cc only when "Carbon Copy" is on in WPForms > Settings > Email). Nothing was changed.' );
		}
		$data          = self::data( $form_id );
		$notifications = isset( $data['settings']['notifications'] ) && is_array( $data['settings']['notifications'] ) ? $data['settings']['notifications'] : array();
		if ( null === $id ) {
			if ( $notifications && ! wpforms()->is_pro() ) {
				throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'WPForms Lite sends only one notification per form; change notification ' . array_key_first( $notifications ) . ' instead. Nothing was changed.' );
			}
			$id = (string) ( $notifications ? max( array_map( 'absint', array_keys( $notifications ) ) ) + 1 : 1 );
		}
		$notification = (array) ( $notifications[ $id ] ?? array() );
		foreach ( self::NOTIFICATION as $key => $name ) {
			if ( array_key_exists( $key, $values ) ) {
				$notification[ $name ] = 'message' === $key ? wp_kses_post( (string) $values[ $key ] ) : sanitize_text_field( (string) $values[ $key ] );
			}
		}
		if ( array_key_exists( 'enabled', $values ) ) {
			$notification['enable'] = $values['enabled'] ? '1' : '0';
			if ( $values['enabled'] ) {
				$data['settings']['notification_enable'] = '1';
			}
		}
		$notifications[ $id ]              = $notification;
		$data['settings']['notifications'] = $notifications;
		self::save( $form_id, $data );
		return $id;
	}
}
