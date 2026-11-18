<?php
/**
 * Fluent Forms form editor (phase B2, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use FluentForm\App\Models\Form;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Modules\MCP\Support\FormCreator;
use FluentForm\App\Services\Form\FormService;
use FluentForm\App\Services\FormBuilder\Components;
use FluentForm\App\Services\Settings\SettingsService;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits Fluent Forms forms the way Fluent's own MCP tools do (read on Fluent Forms 6.2.17,
 * app/Modules/MCP/Tools/FieldTools.php and NotificationTools.php, 2026-11-18): new fields are built by Fluent's
 * FormCreator::formatFields() from its default elements (the same element list its editor and AI builder use,
 * with unique storage names), the field list is saved with FormService::update() (Fluent's duplicate-name check
 * and field sanitizing), the confirmation is the formSettings meta and notifications are notifications meta rows,
 * both saved through SettingsService::store() (Fluent's validators and sanitizer). Entries are keyed by a field's
 * name, so names are kept and a removal is refused while entries hold values. Multi-step forms and fields inside
 * column containers are left to Fluent's editor, as Fluent's own tools leave them.
 */
final class FluentEditor implements FormEditor {

	/**
	 * Tool property => where it lives in a Fluent field.
	 */
	private const PROPERTIES = array(
		'label'        => 'settings.label',
		'required'     => 'settings.validation_rules.required',
		'placeholder'  => 'attributes.placeholder',
		'help_message' => 'settings.help_message',
		'value'        => 'attributes.value',
		'choices'      => 'settings.advanced_options',
		'name'         => 'attributes.name',
	);

	/**
	 * Notification keys: tool name => Fluent name.
	 */
	private const NOTIFICATION = array(
		'name'       => 'name',
		'from_name'  => 'fromName',
		'from_email' => 'fromEmail',
		'reply_to'   => 'replyTo',
		'cc'         => 'cc',
		'bcc'        => 'bcc',
		'subject'    => 'subject',
		'message'    => 'message',
	);

	/**
	 * Default elements by key, built once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $defaults = null;

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'fluent-forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Fluent Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		if ( ! function_exists( 'fluentFormApi' ) || ! class_exists( Form::class ) ) {
			return 'Fluent Forms is not active on this site.';
		}
		return class_exists( FormCreator::class ) && class_exists( SettingsService::class ) && function_exists( 'fluentformLoadFile' )
			? true
			: 'This Fluent Forms version has no form creator for programmatic edits (Fluent Forms 6.2 or later is needed to edit forms here).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create(): bool {
		return Acl::hasPermission( 'fluentform_forms_manager' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool {
		return Acl::hasPermission( 'fluentform_forms_manager', $form_id );
	}

	/**
	 * Fluent's default elements by key (general, advanced and add-on elements; not containers or payments), as
	 * FormCreator matches them: Components over DefaultElements.php after fluentform/editor_init, plus the
	 * elements add-ons add through fluentform/editor_components.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function defaults(): array {
		if ( null !== $this->defaults ) {
			return $this->defaults;
		}
		$components = new Components( fluentformLoadFile( 'Services/FormBuilder/DefaultElements.php' ) );
		do_action( 'fluentform/editor_init', $components ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own hook, as its form creator runs it.
		$all   = (array) $components->toArray();
		$extra = (array) apply_filters( 'fluentform/editor_components', array(), true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own filter, as its form creator applies it.
		$out   = array();
		foreach ( array( 'general', 'advanced' ) as $group ) {
			$out = array_merge( $out, (array) ( $all[ $group ] ?? array() ), array_column( (array) ( $extra[ $group ] ?? array() ), null, 'element' ) );
		}
		$disabled = array_keys( (array) ( new FormService() )->getDisabledComponents() );
		foreach ( array_merge( $disabled, array( 'form_step' ) ) as $key ) {
			unset( $out[ $key ] );
		}
		$this->defaults = $out;
		return $out;
	}

	/**
	 * Properties a field structure takes.
	 *
	 * @param array<string, mixed> $field Field or default element.
	 * @return list<string>
	 */
	private static function properties_of( array $field ): array {
		$out = array();
		foreach ( self::PROPERTIES as $property => $path ) {
			if ( self::has( $field, $path ) || ( 'placeholder' === $property && self::has( $field, 'settings.placeholder' ) ) ) {
				$out[] = $property;
			}
		}
		return $out;
	}

	/**
	 * Whether a dotted path exists.
	 *
	 * @param array<string, mixed> $array Array.
	 * @param string               $path  Path.
	 */
	private static function has( array $array, string $path ): bool {
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
				return false;
			}
			$array = $array[ $key ];
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function field_types(): array {
		$out = array();
		foreach ( $this->defaults() as $key => $element ) {
			$out[] = array(
				'type'       => (string) $key,
				'label'      => (string) ( $element['editor_options']['title'] ?? $key ),
				'properties' => self::properties_of( (array) $element ),
			);
		}
		return $out;
	}

	/**
	 * The stored form row.
	 *
	 * @param int $form_id Form id.
	 * @throws ToolError When there is no such form.
	 */
	private static function row( int $form_id ): object {
		$form = Form::where( 'id', $form_id )->first();
		if ( ! $form ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Fluent Forms has no form ' . $form_id . '.' );
		}
		return $form;
	}

	/**
	 * Decoded form_fields.
	 *
	 * @param object $row Form row.
	 * @return array<string, mixed>
	 */
	private static function fields_of( object $row ): array {
		$data           = json_decode( (string) $row->form_fields, true );
		$data           = is_array( $data ) ? $data : array();
		$data['fields'] = isset( $data['fields'] ) && is_array( $data['fields'] ) ? array_values( $data['fields'] ) : array();
		return $data;
	}

	/**
	 * A field's id: its storage name, or Fluent's element key for fields without one.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function id_of( array $field ): string {
		$name = (string) ( $field['attributes']['name'] ?? '' );
		return '' !== $name ? $name : (string) ( $field['uniqElKey'] ?? '' );
	}

	/**
	 * Storage names in a field, including those inside a container.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return list<string>
	 */
	private static function names_in( array $field ): array {
		$names = array();
		if ( 'container' === ( $field['element'] ?? '' ) ) {
			foreach ( (array) ( $field['columns'] ?? array() ) as $column ) {
				foreach ( (array) ( $column['fields'] ?? array() ) as $child ) {
					$names = array_merge( $names, self::names_in( (array) $child ) );
				}
			}
			return $names;
		}
		$name = (string) ( $field['attributes']['name'] ?? '' );
		return '' !== $name ? array( $name ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function form( int $form_id ): array {
		$row    = self::row( $form_id );
		$data   = self::fields_of( $row );
		$fields = array();
		foreach ( $data['fields'] as $field ) {
			$field   = (array) $field;
			$element = (string) ( $field['element'] ?? '' );
			$item    = array(
				'id'       => self::id_of( $field ),
				'type'     => $element,
				'label'    => (string) ( $field['settings']['label'] ?? $field['settings']['admin_field_label'] ?? '' ),
				'required' => ! empty( $field['settings']['validation_rules']['required']['value'] ),
			);
			if ( 'container' === $element ) {
				$item['contains'] = self::names_in( $field );
				$item['note']     = 'Fields in columns are changed in Fluent Forms itself; the container can be moved.';
			}
			foreach ( self::properties_of( $field ) as $property ) {
				if ( ! isset( $item[ $property ] ) ) {
					$item[ $property ] = self::read( $field, $property );
				}
			}
			$fields[] = $item;
		}
		$settings     = (array) FormMeta::retrieve( 'formSettings', $form_id, array() );
		$confirmation = (array) ( $settings['confirmation'] ?? array() );
		return array(
			'id'                   => $form_id,
			'title'                => (string) $row->title,
			'status'               => (string) $row->status,
			'multi_step'           => ! empty( $data['stepsWrapper'] ),
			'fields'               => $fields,
			'submit_text'          => (string) ( $data['submitButton']['settings']['button_ui']['text'] ?? '' ),
			'confirmation_message' => 'samePage' === ( $confirmation['redirectTo'] ?? 'samePage' ) ? (string) ( $confirmation['messageToShow'] ?? '' ) : null,
			'confirmation_type'    => (string) ( $confirmation['redirectTo'] ?? 'samePage' ),
		);
	}

	/**
	 * A property's value in a field.
	 *
	 * @param array<string, mixed> $field    Field.
	 * @param string               $property Tool property.
	 * @return mixed
	 */
	private static function read( array $field, string $property ): mixed {
		return match ( $property ) {
			'required'    => ! empty( $field['settings']['validation_rules']['required']['value'] ),
			'placeholder' => (string) ( $field['attributes']['placeholder'] ?? $field['settings']['placeholder'] ?? '' ),
			'choices'     => array_map(
				static fn( $o ) => array(
					'label' => (string) ( $o['label'] ?? '' ),
					'value' => (string) ( $o['value'] ?? '' ),
				),
				array_values( (array) ( $field['settings']['advanced_options'] ?? array() ) )
			),
			'label'       => (string) ( $field['settings']['label'] ?? '' ),
			'help_message' => (string) ( $field['settings']['help_message'] ?? '' ),
			'value'       => (string) ( $field['attributes']['value'] ?? '' ),
			default       => (string) ( $field['attributes']['name'] ?? '' ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $type       Field type (element key).
	 * @param array<string, mixed> $properties Properties.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function check_properties( string $type, array $properties ): void {
		$defaults = $this->defaults();
		if ( ! isset( $defaults[ $type ] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Fluent Forms offers no field type "' . $type . '" on this site.' );
		}
		$allowed = self::properties_of( (array) $defaults[ $type ] );
		foreach ( $properties as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' field does not take ' . $key . ' (it takes ' . implode( ', ', $allowed ) . ').' );
			}
			$ok = match ( $key ) {
				'required' => is_bool( $value ),
				'choices'  => is_array( $value ) && $value && array_filter( $value, static fn( $c ) => is_string( $c ) || ( is_array( $c ) && isset( $c['label'] ) && is_string( $c['label'] ) ) ) === $value,
				'name'     => is_string( $value ) && (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $value ),
				default    => is_string( $value ) || is_numeric( $value ),
			};
			if ( ! $ok ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, $key . ' has the wrong kind of value (' . ( 'name' === $key ? 'letters, digits and _' : ( 'choices' === $key ? 'a list of {"label","value"}' : ( 'required' === $key ? 'true or false' : 'text' ) ) ) . ').' );
			}
		}
	}

	/**
	 * Field with tool properties applied.
	 *
	 * @param array<string, mixed> $field      Field.
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function set( array $field, array $properties ): array {
		foreach ( $properties as $key => $value ) {
			switch ( $key ) {
				case 'label':
					$field['settings']['label'] = sanitize_text_field( (string) $value );
					if ( empty( $field['settings']['admin_field_label'] ) ) {
						$field['settings']['admin_field_label'] = $field['settings']['label'];
					}
					break;
				case 'required':
					$field['settings']['validation_rules']['required']['value'] = (bool) $value;
					break;
				case 'placeholder':
					if ( array_key_exists( 'placeholder', (array) ( $field['attributes'] ?? array() ) ) ) {
						$field['attributes']['placeholder'] = sanitize_text_field( (string) $value );
					} else {
						$field['settings']['placeholder'] = sanitize_text_field( (string) $value );
					}
					break;
				case 'help_message':
					$field['settings']['help_message'] = sanitize_text_field( (string) $value );
					break;
				case 'value':
					$field['attributes']['value'] = sanitize_text_field( (string) $value );
					break;
				case 'name':
					$field['attributes']['name'] = (string) $value;
					break;
				case 'choices':
					$options = array();
					foreach ( (array) $value as $choice ) {
						$label     = sanitize_text_field( (string) ( is_array( $choice ) ? $choice['label'] : $choice ) );
						$given     = is_array( $choice ) && isset( $choice['value'] ) ? sanitize_text_field( (string) $choice['value'] ) : $label;
						$options[] = array(
							'label'      => $label,
							'value'      => '' !== $given ? $given : $label,
							'calc_value' => '',
						);
					}
					$field['settings']['advanced_options'] = $options;
					break;
			}//end switch
		}//end foreach
		return $field;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $form_id  Form id.
	 * @param string $field_id Field id.
	 */
	public function entry_values( int $form_id, string $field_id ): int {
		global $wpdb;
		$names = array( $field_id );
		foreach ( self::fields_of( self::row( $form_id ) )['fields'] as $field ) {
			if ( self::id_of( (array) $field ) === $field_id ) {
				$names = self::names_in( (array) $field );
			}
		}
		if ( ! $names ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT submission_id) FROM {$wpdb->prefix}fluentform_entry_details WHERE form_id = %d AND field_name IN ({$in}) AND field_value <> ''", array_merge( array( $form_id ), $names ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count in Fluent Forms' entry details; placeholders built for the name list.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function apply( int $form_id, array $operations ): array {
		$row  = self::row( $form_id );
		$data = self::fields_of( $row );
		if ( ! empty( $data['stepsWrapper'] ) && array_filter( $operations, static fn( $o ) => 'form' !== $o['op'] ) ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Form ' . $form_id . ' is a multi-step form; change its fields in Fluent Forms itself (as Fluent\'s own tools do). Nothing was changed.' );
		}
		$fields   = $data['fields'];
		$title    = (string) $row->title;
		$settings = null;
		$added    = array();
		foreach ( $operations as $operation ) {
			$op = (string) $operation['op'];
			if ( 'form' === $op ) {
				$title = isset( $operation['title'] ) ? sanitize_text_field( (string) $operation['title'] ) : $title;
				if ( isset( $operation['submit_text'] ) ) {
					$data['submitButton']['settings']['button_ui']['text'] = sanitize_text_field( (string) $operation['submit_text'] );
				}
				if ( isset( $operation['confirmation_message'] ) ) {
					$settings = (string) $operation['confirmation_message'];
				}
				continue;
			}
			if ( 'add' === $op ) {
				$field = $this->build( (string) $operation['type'], (array) ( $operation['properties'] ?? array() ), $fields );
				$index = min( (int) ( $operation['index'] ?? count( $fields ) ), count( $fields ) );
				array_splice( $fields, $index, 0, array( $field ) );
				$added[] = self::id_of( $field );
				continue;
			}
			$at = self::position( $fields, (string) $operation['field'] );
			if ( 'update' === $op ) {
				$fields[ $at ] = self::set( (array) $fields[ $at ], (array) $operation['properties'] );
				continue;
			}
			$field = $fields[ $at ];
			array_splice( $fields, $at, 1 );
			if ( 'move' === $op ) {
				array_splice( $fields, min( (int) $operation['index'], count( $fields ) ), 0, array( $field ) );
			}
		}//end foreach
		$data['fields'] = array_values( $fields );
		self::save( $form_id, $title, (string) $row->status, $data );
		if ( null !== $settings ) {
			self::save_confirmation( $form_id, $settings );
		}
		return $added;
	}

	/**
	 * Position of a field by id.
	 *
	 * @param list<mixed> $fields Fields.
	 * @param string      $id     Field id.
	 * @throws ToolError When the field is gone.
	 */
	private static function position( array $fields, string $id ): int {
		foreach ( $fields as $n => $field ) {
			if ( self::id_of( (array) $field ) === $id ) {
				return (int) $n;
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Fluent Forms form has no field "' . $id . '". Nothing was changed.' );
	}

	/**
	 * A new field, built by Fluent's FormCreator, with a storage name no other field has.
	 *
	 * @param string               $type       Element key.
	 * @param array<string, mixed> $properties Properties.
	 * @param list<mixed>          $fields     Fields so far.
	 * @return array<string, mixed>
	 * @throws ToolError When Fluent builds nothing for the type or the name is taken.
	 */
	private function build( string $type, array $properties, array $fields ): array {
		$used = array();
		foreach ( $fields as $field ) {
			$used = array_merge( $used, self::names_in( (array) $field ) );
		}
		$default = (string) ( $this->defaults()[ $type ]['attributes']['name'] ?? '' );
		$spec    = array( 'element' => $type );
		if ( '' !== $default || isset( $properties['name'] ) ) {
			$base = (string) ( $properties['name'] ?? '' );
			if ( '' !== $base && in_array( $base, $used, true ) ) {
				throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'Another field already stores its answers as "' . $base . '"; choose another name. Nothing was changed.' );
			}
			if ( '' === $base ) {
				$base = sanitize_key( str_replace( array( ' ', '-' ), '_', (string) ( $properties['label'] ?? '' ) ) );
				$base = '' !== $base ? substr( $base, 0, 40 ) : $default;
			}
			$name = $base;
			for ( $n = 1; in_array( $name, $used, true ); $n++ ) {
				$name = $base . '_' . $n;
			}
			$spec['attributes'] = array( 'name' => $name );
			unset( $properties['name'] );
		}
		$built = ( new FormCreator() )->formatFields( array( $spec ) );
		if ( ! $built ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Fluent Forms built no ' . $type . ' field. Nothing was changed.' );
		}
		return self::set( (array) $built[0], $properties );
	}

	/**
	 * Save the field list with FormService::update().
	 *
	 * @param int                  $form_id Form id.
	 * @param string               $title   Title.
	 * @param string               $status  Status.
	 * @param array<string, mixed> $data    form_fields.
	 * @throws ToolError When Fluent refuses.
	 */
	private static function save( int $form_id, string $title, string $status, array $data ): void {
		try {
			( new FormService() )->update(
				array(
					'form_id'    => $form_id,
					'title'      => $title,
					'status'     => $status,
					'formFields' => wp_json_encode( $data ),
				)
			);
		} catch ( \Throwable $e ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Fluent Forms refused the change: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was changed.' );
		}
	}

	/**
	 * Save a same-page confirmation message through SettingsService::store().
	 *
	 * @param int    $form_id Form id.
	 * @param string $message Message.
	 * @throws ToolError When Fluent refuses.
	 */
	private static function save_confirmation( int $form_id, string $message ): void {
		$meta     = FormMeta::where( 'form_id', $form_id )->where( 'meta_key', 'formSettings' )->first();
		$settings = $meta ? json_decode( (string) $meta->value, true ) : array();
		$settings = is_array( $settings ) ? $settings : array();
		// A same-page message, as Fluent's default confirmation; a page or URL redirect is replaced by it.
		$settings['confirmation']['redirectTo']    = 'samePage';
		$settings['confirmation']['messageToShow'] = $message;
		self::store_meta( $form_id, 'formSettings', $settings, $meta ? (int) $meta->id : 0 );
	}

	/**
	 * Store a form meta row through Fluent's SettingsService (validation and sanitizing).
	 *
	 * @param int                  $form_id Form id.
	 * @param string               $key     Meta key.
	 * @param array<string, mixed> $value   Value.
	 * @param int                  $meta_id Row id (0 = new row).
	 * @return int Row id.
	 * @throws ToolError When Fluent refuses.
	 */
	private static function store_meta( int $form_id, string $key, array $value, int $meta_id ): int {
		$args = array(
			'form_id'  => $form_id,
			'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- argument of Fluent's SettingsService, not a WP_Query.
			'value'    => wp_json_encode( $value ),
		);
		if ( $meta_id > 0 ) {
			$args['meta_id'] = $meta_id;
		}
		try {
			$saved = ( new SettingsService() )->store( $args );
		} catch ( \Throwable $e ) {
			$errors = method_exists( $e, 'errors' ) ? (array) $e->errors() : array();
			$flat   = array();
			array_walk_recursive(
				$errors,
				static function ( $m ) use ( &$flat ) {
					if ( is_string( $m ) && '' !== $m ) {
						$flat[] = $m;
					}
				}
			);
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Fluent Forms refused the change: ' . wp_strip_all_tags( $flat ? implode( ' ', array_unique( $flat ) ) : $e->getMessage() ) . ' Nothing was changed.' );
		}
		return (int) ( $saved[0] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $title Title.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function create( string $title ): int {
		try {
			$form = ( new FormService() )->store(
				array(
					'type'       => 'form',
					'predefined' => 'blank_form',
				)
			);
		} catch ( \Throwable $e ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Fluent Forms did not create the form: ' . wp_strip_all_tags( $e->getMessage() ) );
		}
		$id  = (int) $form->id;
		$row = self::row( $id );
		self::save( $id, sanitize_text_field( $title ), (string) $row->status, self::fields_of( $row ) );
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function snapshot( int $form_id ): array {
		$row  = self::row( $form_id );
		$meta = FormMeta::where( 'form_id', $form_id )->where( 'meta_key', 'formSettings' )->first();
		return array(
			'title'        => (string) $row->title,
			'status'       => (string) $row->status,
			'form_fields'  => (string) $row->form_fields,
			'formSettings' => $meta ? (string) $meta->value : null,
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
		$data = json_decode( (string) ( $state['form_fields'] ?? '' ), true );
		self::save( $form_id, (string) ( $state['title'] ?? '' ), (string) ( $state['status'] ?? 'published' ), is_array( $data ) ? $data : array() );
		if ( isset( $state['formSettings'] ) ) {
			$meta     = FormMeta::where( 'form_id', $form_id )->where( 'meta_key', 'formSettings' )->first();
			$settings = json_decode( (string) $state['formSettings'], true );
			self::store_meta( $form_id, 'formSettings', is_array( $settings ) ? $settings : array(), $meta ? (int) $meta->id : 0 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $form_id Form id.
	 */
	public function notifications( int $form_id ): array {
		self::row( $form_id );
		$out = array();
		foreach ( FormMeta::where( 'form_id', $form_id )->where( 'meta_key', 'notifications' )->get() as $meta ) {
			$n = json_decode( (string) $meta->value, true );
			if ( ! is_array( $n ) ) {
				continue;
			}
			$send = (array) ( $n['sendTo'] ?? array() );
			$type = (string) ( $send['type'] ?? 'email' );
			$row  = array(
				'id'      => (string) $meta->id,
				'enabled' => ! empty( $n['enabled'] ),
				'to'      => 'email' === $type ? (string) ( $send['email'] ?? '' ) : $type . ':' . ( 'field' === $type ? (string) ( $send['field'] ?? '' ) : 'rules' ),
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
	 * @param string|null          $id      Notification id (meta row id).
	 * @param array<string, mixed> $values  Values.
	 * @throws ToolError When the plugin refuses or the form is missing.
	 */
	public function save_notification( int $form_id, ?string $id, array $values ): string {
		$current = array(
			'name'           => __( 'Email Notification', 'kuduy-mcp' ),
			'sendTo'         => array(
				'type'    => 'email',
				'email'   => '{wp.admin_email}',
				'field'   => '',
				'routing' => array(),
			),
			'fromName'       => '',
			'fromEmail'      => '',
			'replyTo'        => '',
			'bcc'            => '',
			'subject'        => '',
			'message'        => '',
			'conditionals'   => array(
				'status'     => false,
				'type'       => 'all',
				'conditions' => array(
					array(
						'field'    => '',
						'operator' => '=',
						'value'    => '',
					),
				),
			),
			'enabled'        => true,
			'email_template' => '',
		);
		if ( null !== $id ) {
			$meta    = FormMeta::where( 'form_id', $form_id )->where( 'meta_key', 'notifications' )->where( 'id', (int) $id )->first();
			$decoded = $meta ? json_decode( (string) $meta->value, true ) : null;
			if ( ! is_array( $decoded ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Fluent Forms form ' . $form_id . ' has no notification ' . $id . '. Nothing was changed.' );
			}
			$current = $decoded;
		}
		foreach ( self::NOTIFICATION as $key => $name ) {
			if ( array_key_exists( $key, $values ) ) {
				$current[ $name ] = (string) $values[ $key ];
			}
		}
		if ( array_key_exists( 'enabled', $values ) ) {
			$current['enabled'] = (bool) $values['enabled'];
		}
		$send = (array) ( $current['sendTo'] ?? array() );
		$type = (string) ( $send['type'] ?? 'email' );
		$now  = 'email' === $type ? (string) ( $send['email'] ?? '' ) : $type . ':' . ( 'field' === $type ? (string) ( $send['field'] ?? '' ) : 'rules' );
		if ( array_key_exists( 'to', $values ) && (string) $values['to'] !== $now ) {
			$to = (string) $values['to'];
			if ( str_starts_with( $to, 'field:' ) || str_starts_with( $to, 'routing:' ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, '"to" takes email addresses or smart tags; sending to a form field or by routing rules is set in Fluent Forms itself. Nothing was changed.' );
			}
			$current['sendTo']          = (array) ( $current['sendTo'] ?? array() );
			$current['sendTo']['type']  = 'email';
			$current['sendTo']['email'] = $to;
		}
		return (string) self::store_meta( $form_id, 'notifications', $current, null === $id ? 0 : (int) $id );
	}
}
