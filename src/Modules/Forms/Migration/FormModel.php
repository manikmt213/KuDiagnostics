<?php
/**
 * Canonical form model for migrations between forms plugins (phase B3, decision D83).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Migration;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Forms\Cf7Adapter;
use Kuduy\Mcp\Modules\Forms\Editing\Cf7Tags;
use Kuduy\Mcp\Modules\Forms\Editing\FluentEditor;
use Kuduy\Mcp\Modules\Forms\Editing\FormEditor;
use Kuduy\Mcp\Modules\Forms\Editing\FormidableEditor;
use Kuduy\Mcp\Modules\Forms\Editing\GravityEditor;
use Kuduy\Mcp\Modules\Forms\Editing\NinjaEditor;
use Kuduy\Mcp\Modules\Forms\Editing\WpformsEditor;

/**
 * A form as plugin-neutral data: fields with a canonical type and canonical properties (label, required,
 * placeholder, description, default, choices, key), submit text and confirmation message. Read from a plugin with
 * export(); turned into a target plugin's types and properties with plan(); checked after writing with verify().
 * Field types and properties per plugin were read from the plugins themselves (`docs/plans/phase-b3-form-migration.md`).
 */
final class FormModel {

	public const CF7 = 'cf7';

	/**
	 * Per plugin: its field type => canonical type. Order matters for writing: the first available type of a plugin
	 * that maps to a canonical type is used.
	 */
	private const TYPES = array(
		'cf7'           => array(
			'text'       => 'text',
			'email'      => 'email',
			'textarea'   => 'textarea',
			'number'     => 'number',
			'range'      => 'number',
			'tel'        => 'tel',
			'url'        => 'url',
			'date'       => 'date',
			'select'     => 'select',
			'radio'      => 'radio',
			'checkbox'   => 'checkbox',
			'acceptance' => 'acceptance',
			'file'       => 'file',
			'hidden'     => 'hidden',
		),
		'fluent-forms'  => array(
			'input_text'          => 'text',
			'input_mask'          => 'text',
			'input_email'         => 'email',
			'textarea'            => 'textarea',
			'input_number'        => 'number',
			'phone'               => 'tel',
			'input_url'           => 'url',
			'input_date'          => 'date',
			'select'              => 'select',
			'input_radio'         => 'radio',
			'input_checkbox'      => 'checkbox',
			'gdpr_agreement'      => 'acceptance',
			'terms_and_condition' => 'acceptance',
			'input_file'          => 'file',
			'input_hidden'        => 'hidden',
		),
		'wpforms'       => array(
			'text'        => 'text',
			'email'       => 'email',
			'textarea'    => 'textarea',
			'number'      => 'number',
			'phone'       => 'tel',
			'url'         => 'url',
			'date-time'   => 'date',
			'select'      => 'select',
			'radio'       => 'radio',
			'checkbox'    => 'checkbox',
			'file-upload' => 'file',
			'hidden'      => 'hidden',
		),
		'ninja-forms'   => array(
			'textbox'      => 'text',
			'email'        => 'email',
			'textarea'     => 'textarea',
			'number'       => 'number',
			'phone'        => 'tel',
			'date'         => 'date',
			'listselect'   => 'select',
			'listradio'    => 'radio',
			'listcheckbox' => 'checkbox',
			'checkbox'     => 'acceptance',
			'file_upload'  => 'file',
			'hidden'       => 'hidden',
		),
		'formidable'    => array(
			'text'     => 'text',
			'email'    => 'email',
			'textarea' => 'textarea',
			'number'   => 'number',
			'phone'    => 'tel',
			'url'      => 'url',
			'date'     => 'date',
			'select'   => 'select',
			'radio'    => 'radio',
			'checkbox' => 'checkbox',
			'gdpr'     => 'acceptance',
			'file'     => 'file',
			'hidden'   => 'hidden',
		),
		'gravity-forms' => array(
			'text'       => 'text',
			'email'      => 'email',
			'textarea'   => 'textarea',
			'number'     => 'number',
			'phone'      => 'tel',
			'website'    => 'url',
			'date'       => 'date',
			'select'     => 'select',
			'radio'      => 'radio',
			'checkbox'   => 'checkbox',
			'consent'    => 'acceptance',
			'fileupload' => 'file',
			'hidden'     => 'hidden',
		),
	);

	/**
	 * Per plugin: canonical property => the plugin's property name in its editor (B2).
	 */
	private const PROPERTIES = array(
		'fluent-forms'  => array(
			'label'       => 'label',
			'required'    => 'required',
			'placeholder' => 'placeholder',
			'description' => 'help_message',
			'default'     => 'value',
			'choices'     => 'choices',
			'key'         => 'name',
		),
		'wpforms'       => array(
			'label'       => 'label',
			'required'    => 'required',
			'placeholder' => 'placeholder',
			'description' => 'description',
			'default'     => 'default_value',
			'choices'     => 'choices',
		),
		'ninja-forms'   => array(
			'label'       => 'label',
			'required'    => 'required',
			'placeholder' => 'placeholder',
			'description' => 'help_text',
			'default'     => 'default_value',
			'choices'     => 'options',
			'key'         => 'key',
		),
		'formidable'    => array(
			'label'       => 'name',
			'required'    => 'required',
			'placeholder' => 'placeholder',
			'description' => 'description',
			'default'     => 'default_value',
			'choices'     => 'options',
		),
		'gravity-forms' => array(
			'label'       => 'label',
			'required'    => 'required',
			'placeholder' => 'placeholder',
			'description' => 'description',
			'default'     => 'defaultValue',
			'choices'     => 'choices',
		),
	);

	/**
	 * When the target has no type for a canonical type: what to use instead, and what is lost.
	 */
	private const FALLBACK = array(
		'email'      => array( 'text', 'no email check' ),
		'tel'        => array( 'text', 'no phone format' ),
		'url'        => array( 'text', 'no URL check' ),
		'number'     => array( 'text', 'no number check' ),
		'date'       => array( 'text', 'no date picker' ),
		'acceptance' => array( 'checkbox', 'a checkbox with one choice instead of an agreement field' ),
	);

	/**
	 * Canonical types whose default is a typed value (not an index or a choice).
	 */
	private const TEXTUAL = array( 'text', 'email', 'textarea', 'number', 'tel', 'url', 'date', 'hidden' );

	/**
	 * The B2 editors by plugin key.
	 *
	 * @return array<string, FormEditor>
	 */
	public static function editors(): array {
		$out = array();
		foreach ( array( new FluentEditor(), new WpformsEditor(), new NinjaEditor(), new FormidableEditor(), new GravityEditor() ) as $editor ) {
			$out[ $editor->prefix() ] = $editor;
		}
		return $out;
	}

	/**
	 * Plugin keys: sources (CF7 and the editors) and targets (the editors).
	 *
	 * @return list<string>
	 */
	public static function sources(): array {
		return array_merge( array( self::CF7 ), array_keys( self::editors() ) );
	}

	/**
	 * The editor of a plugin, available on this site.
	 *
	 * @param string $plugin Plugin key.
	 * @throws ToolError When the plugin is unknown or not active.
	 */
	public static function editor( string $plugin ): FormEditor {
		$editor = self::editors()[ $plugin ] ?? null;
		if ( null === $editor ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Forms cannot be written to "' . $plugin . '" here (targets: ' . implode( ', ', array_keys( self::editors() ) ) . ').' );
		}
		$available = $editor->available();
		if ( true !== $available ) {
			throw ToolError::of( ToolError::PLUGIN_NOT_ACTIVE, (string) $available );
		}
		return $editor;
	}

	/**
	 * Whether the current user may read and change the form in its plugin (export needs what editing needs: a
	 * form's settings are editor data).
	 *
	 * @param string $plugin  Plugin key.
	 * @param int    $form_id Form id.
	 * @throws ToolError When the plugin is not active.
	 */
	public static function can_edit( string $plugin, int $form_id ): bool {
		if ( self::CF7 === $plugin ) {
			if ( ! class_exists( 'WPCF7_ContactForm' ) || ! class_exists( 'WPCF7_FormTagsManager' ) ) {
				throw ToolError::of( ToolError::PLUGIN_NOT_ACTIVE, 'Contact Form 7 is not active on this site.' );
			}
			return current_user_can( 'wpcf7_edit_contact_form', $form_id );
		}
		return self::editor( $plugin )->can_edit( $form_id );
	}

	/**
	 * Read a form into the canonical model.
	 *
	 * @param string $plugin  Plugin key.
	 * @param int    $form_id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When the plugin is not active or the form does not exist.
	 */
	public static function export( string $plugin, int $form_id ): array {
		return self::CF7 === $plugin ? self::from_cf7( $form_id ) : self::from_editor( $plugin, self::editor( $plugin )->form( $form_id ), self::editor( $plugin )->notifications( $form_id ) );
	}

	/**
	 * Contact Form 7: tags, the submit button and the "sent" message.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 */
	private static function from_cf7( int $form_id ): array {
		$form    = Cf7Adapter::find( $form_id );
		$fields  = array();
		$skipped = array();
		$submit  = '';
		foreach ( Cf7Tags::tags( $form_id ) as $tag ) {
			$type = (string) $tag['type'];
			if ( 'submit' === $type ) {
				$submit = '' !== $submit ? $submit : (string) ( $tag['values'][0] ?? '' );
				continue;
			}
			$canonical = self::TYPES['cf7'][ $type ] ?? null;
			if ( null === $canonical ) {
				if ( '' !== (string) $tag['name'] ) {
					$skipped[] = array(
						'id'     => (string) $tag['id'],
						'type'   => $type,
						'reason' => 'No field of this kind in the canonical model (e.g. quiz, captcha, payment).',
					);
				}
				continue;
			}
			$options = array_map( 'strval', (array) $tag['options'] );
			$values  = array_map( 'strval', (array) $tag['values'] );
			$field   = array(
				'type'     => $canonical,
				'key'      => (string) $tag['name'],
				'label'    => (string) ( $tag['label'] ?? '' ),
				'required' => (bool) $tag['required'],
				'source'   => array(
					'id'   => (string) $tag['id'],
					'type' => $type,
				),
			);
			if ( in_array( $canonical, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$field['choices'] = array_map(
					static function ( string $value ): array {
						$parts = explode( '|', $value, 2 );
						return array(
							'label' => $parts[0],
							'value' => $parts[1] ?? $parts[0],
						);
					},
					$values
				);
				if ( in_array( 'multiple', $options, true ) ) {
					$field['multiple'] = true;
				}
			} elseif ( 'acceptance' === $canonical ) {
				$field['label']    = '' !== trim( (string) ( $tag['content'] ?? '' ) ) ? trim( wp_strip_all_tags( (string) $tag['content'] ) ) : $field['label'];
				$field['required'] = ! in_array( 'optional', $options, true );
			} elseif ( $values ) {
				$placeholder                                       = in_array( 'placeholder', $options, true ) || in_array( 'watermark', $options, true );
				$field[ $placeholder ? 'placeholder' : 'default' ] = $values[0];
			}//end if
			if ( '' === $field['label'] ) {
				$field['label'] = ucfirst( trim( str_replace( array( '-', '_' ), ' ', (string) preg_replace( '/^your[-_]/', '', (string) $tag['name'] ) ) ) );
			}
			$fields[] = $field;
		}//end foreach
		$messages = (array) $form->prop( 'messages' );
		$mails    = array_filter( Cf7Adapter::mails( $form ), static fn( $mail ) => ! empty( $mail['active'] ) );
		return array(
			'plugin'               => self::CF7,
			'form_id'              => $form_id,
			'title'                => (string) $form->title(),
			'fields'               => $fields,
			'skipped'              => $skipped,
			'submit_text'          => '' !== $submit ? $submit : 'Submit',
			'confirmation_message' => (string) ( $messages['mail_sent_ok'] ?? '' ),
			'notifications'        => count( $mails ),
		);
	}

	/**
	 * A B2 editor's form.
	 *
	 * @param string                     $plugin        Plugin key.
	 * @param array<string, mixed>       $form          From FormEditor::form().
	 * @param list<array<string, mixed>> $notifications From FormEditor::notifications().
	 * @return array<string, mixed>
	 */
	private static function from_editor( string $plugin, array $form, array $notifications ): array {
		$fields  = array();
		$skipped = array();
		foreach ( (array) $form['fields'] as $row ) {
			$field = self::field_from( $plugin, (array) $row );
			if ( null === $field ) {
				$skipped[] = array(
					'id'     => (string) ( $row['id'] ?? '' ),
					'type'   => (string) ( $row['type'] ?? '' ),
					'reason' => 'No field of this kind in the canonical model (e.g. layout, name, address, payment, captcha, submit).',
				);
				continue;
			}
			$fields[] = $field;
		}
		return array(
			'plugin'               => $plugin,
			'form_id'              => (int) $form['id'],
			'title'                => (string) $form['title'],
			'fields'               => $fields,
			'skipped'              => $skipped,
			'submit_text'          => (string) ( $form['submit_text'] ?? '' ),
			'confirmation_message' => is_string( $form['confirmation_message'] ?? null ) ? $form['confirmation_message'] : '',
			'notifications'        => count( $notifications ),
		);
	}

	/**
	 * One editor field in the canonical model, or null when it has no canonical type.
	 *
	 * @param string               $plugin Plugin key.
	 * @param array<string, mixed> $row    Field as FormEditor::form() shows it.
	 * @return array<string, mixed>|null
	 */
	public static function field_from( string $plugin, array $row ): ?array {
		$type      = (string) ( $row['type'] ?? '' );
		$canonical = self::TYPES[ $plugin ][ $type ] ?? null;
		if ( null === $canonical ) {
			return null;
		}
		$field = array(
			'type'     => $canonical,
			'label'    => (string) ( $row['label'] ?? '' ),
			'required' => ! empty( $row['required'] ),
			'source'   => array(
				'id'   => (string) ( $row['id'] ?? '' ),
				'type' => $type,
			),
		);
		foreach ( self::PROPERTIES[ $plugin ] as $property => $native ) {
			if ( in_array( $property, array( 'label', 'required' ), true ) || ! isset( $row[ $native ] ) ) {
				continue;
			}
			$value = $row[ $native ];
			if ( 'choices' === $property ) {
				$value = array_values(
					array_map(
						static fn( $c ) => array(
							'label' => (string) ( is_array( $c ) ? ( $c['label'] ?? '' ) : $c ),
							'value' => (string) ( is_array( $c ) ? ( $c['value'] ?? $c['label'] ?? '' ) : $c ),
						),
						(array) $value
					)
				);
			} elseif ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			} else {
				$value = (string) $value;
			}
			$field[ $property ] = $value;
		}//end foreach
		if ( ! isset( $field['key'] ) && isset( $row['key'] ) && is_string( $row['key'] ) && '' !== $row['key'] ) {
			$field['key'] = $row['key'];
		}
		if ( 'acceptance' === $canonical && '' === $field['label'] ) {
			$field['label'] = (string) ( $row['value'] ?? '' );
		}
		return $field;
	}

	/**
	 * Map the canonical form onto a target plugin. Changes nothing.
	 *
	 * @param array<string, mixed> $model  Canonical form.
	 * @param string               $target Target plugin key.
	 * @return array{operations: list<array<string, mixed>>, fields: list<array<string, mixed>>, not_carried: list<array<string, mixed>>}
	 * @throws ToolError When the target is not active.
	 */
	public static function plan( array $model, string $target ): array {
		$editor = self::editor( $target );
		$types  = array();
		foreach ( $editor->field_types() as $row ) {
			$types[ (string) $row['type'] ] = array_map( 'strval', (array) $row['properties'] );
		}
		$operations = array();
		$fields     = array();
		$lost       = array();
		$keys       = array();
		foreach ( (array) $model['fields'] as $n => $field ) {
			$field     = (array) $field;
			$canonical = (string) $field['type'];
			$notes     = array();
			$native    = self::native_type( $target, $canonical, $types );
			if ( null === $native && isset( self::FALLBACK[ $canonical ] ) ) {
				[ $instead, $loses ] = self::FALLBACK[ $canonical ];
				$native              = self::native_type( $target, $instead, $types );
				if ( null !== $native ) {
					$notes[] = 'Becomes ' . $instead . ' (' . $loses . ').';
					if ( 'acceptance' === $canonical ) {
						$field['choices'] = array(
							array(
								'label' => (string) $field['label'],
								'value' => '1',
							),
						);
					}
					$canonical = $instead;
				}
			}
			if ( null === $native ) {
				$lost[] = array(
					'field'  => self::name_of( $field, $n ),
					'type'   => (string) $field['type'],
					'reason' => $editor->name() . ' offers no field for this on this site.',
				);
				continue;
			}
			[ $properties, $dropped ] = self::properties_for( $target, $canonical, $field, $types[ $native ], $keys );
			foreach ( $dropped as $property ) {
				$notes[] = $property . ' not carried (the ' . $native . ' field has no such setting).';
			}
			if ( ! empty( $field['multiple'] ) ) {
				$notes[] = 'Multiple selection not carried; set it in ' . $editor->name() . ' if needed.';
			}
			try {
				$editor->check_properties( $native, $properties );
			} catch ( ToolError $e ) {
				$lost[] = array(
					'field'  => self::name_of( $field, $n ),
					'type'   => (string) $field['type'],
					'reason' => $editor->name() . ' refused it: ' . $e->getMessage(),
				);
				continue;
			}
			$operations[] = array(
				'op'         => 'add',
				'type'       => $native,
				'properties' => $properties,
			);
			$fields[]     = array(
				'field'     => self::name_of( $field, $n ),
				'from'      => (string) ( $field['source']['type'] ?? $field['type'] ),
				'to'        => $native,
				'canonical' => $canonical,
				'label'     => (string) $field['label'],
				'required'  => ! empty( $field['required'] ),
				'choices'   => array_column( (array) ( $field['choices'] ?? array() ), 'label' ),
				'notes'     => $notes,
			);
		}//end foreach
		return array(
			'operations'  => $operations,
			'fields'      => $fields,
			'not_carried' => $lost,
		);
	}

	/**
	 * The target plugin's type for a canonical type, if it offers one on this site.
	 *
	 * @param string                      $target    Target plugin key.
	 * @param string                      $canonical Canonical type.
	 * @param array<string, list<string>> $types     Target's available types => properties.
	 */
	private static function native_type( string $target, string $canonical, array $types ): ?string {
		foreach ( self::TYPES[ $target ] as $native => $maps_to ) {
			if ( $maps_to === $canonical && isset( $types[ $native ] ) ) {
				return $native;
			}
		}
		return null;
	}

	/**
	 * The target's properties for a field: only those its type takes.
	 *
	 * @param string               $target    Target plugin key.
	 * @param string               $canonical Canonical type (after fallback).
	 * @param array<string, mixed> $field     Canonical field.
	 * @param list<string>         $allowed   Properties the target type takes.
	 * @param array<string, bool>  $keys      Keys used so far in the new form (updated).
	 * @return array{0: array<string, mixed>, 1: list<string>} Properties, canonical properties not carried.
	 */
	private static function properties_for( string $target, string $canonical, array $field, array $allowed, array &$keys ): array {
		$out     = array();
		$dropped = array();
		foreach ( self::PROPERTIES[ $target ] as $property => $native ) {
			if ( ! array_key_exists( $property, $field ) ) {
				continue;
			}
			$value = $field[ $property ];
			if ( 'default' === $property && ! in_array( $canonical, self::TEXTUAL, true ) ) {
				continue;
			}
			if ( 'key' === $property ) {
				$value = self::key( (string) $value, 'ninja-forms' === $target, $keys );
				if ( '' === $value ) {
					continue;
				}
			}
			if ( 'required' === $property ) {
				$value = (bool) $value;
			} elseif ( 'choices' === $property ) {
				$value = array_values( (array) $value );
				if ( ! $value ) {
					continue;
				}
			} elseif ( is_string( $value ) && '' === $value ) {
				continue;
			}
			if ( ! in_array( $native, $allowed, true ) ) {
				// A key or required mark the target type has no setting for is not worth a note.
				if ( ! in_array( $property, array( 'key', 'required' ), true ) ) {
					$dropped[] = $property;
				}
				continue;
			}
			$out[ $native ] = $value;
		}//end foreach
		return array( $out, $dropped );
	}

	/**
	 * A storage key the target accepts, unique in the new form: letters, digits and _ (Ninja: lower case).
	 *
	 * @param string              $key   Source key.
	 * @param bool                $lower Lower case only.
	 * @param array<string, bool> $used  Keys used so far (updated).
	 */
	private static function key( string $key, bool $lower, array &$used ): string {
		$key = (string) preg_replace( '/[^A-Za-z0-9_]+/', '_', $key );
		$key = trim( $lower ? strtolower( $key ) : $key, '_' );
		if ( '' === $key ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z_]/', $key ) ) {
			$key = 'f_' . $key;
		}
		$key  = substr( $key, 0, 60 );
		$base = $key;
		for ( $i = 2; isset( $used[ $key ] ); $i++ ) {
			$key = $base . '_' . $i;
		}
		$used[ $key ] = true;
		return $key;
	}

	/**
	 * A field's name in reports: its key, else its label, else its position.
	 *
	 * @param array<string, mixed> $field Canonical field.
	 * @param int                  $n     Position.
	 */
	private static function name_of( array $field, int $n ): string {
		foreach ( array( 'key', 'label' ) as $part ) {
			if ( isset( $field[ $part ] ) && '' !== (string) $field[ $part ] ) {
				return (string) $field[ $part ];
			}
		}
		return '#' . ( $n + 1 );
	}

	/**
	 * Compare the planned fields with the fields the target saved.
	 *
	 * @param string                     $target  Target plugin key.
	 * @param array<string, mixed>       $form    Saved form (FormEditor::form()).
	 * @param list<string>               $added   Field ids from apply(), in plan order.
	 * @param list<array<string, mixed>> $planned Planned fields from plan().
	 * @param array<string, mixed>       $model   Canonical form (submit text, confirmation).
	 * @return array{ok: bool, fields: list<array<string, mixed>>, form: list<string>}
	 */
	public static function verify( string $target, array $form, array $added, array $planned, array $model ): array {
		$saved = array();
		foreach ( (array) $form['fields'] as $row ) {
			$saved[ (string) ( $row['id'] ?? '' ) ] = (array) $row;
		}
		$fields = array();
		$all_ok = true;
		foreach ( $planned as $i => $plan ) {
			$id   = $added[ $i ] ?? '';
			$row  = $saved[ $id ] ?? null;
			$diff = array();
			if ( null === $row ) {
				$diff[] = 'not found in the saved form';
			} else {
				$got = self::field_from( $target, $row );
				if ( null === $got || $got['type'] !== $plan['canonical'] ) {
					$diff[] = 'type is ' . (string) ( $row['type'] ?? '?' );
				} else {
					if ( '' !== $plan['label'] && 'acceptance' !== $plan['canonical'] && trim( $got['label'] ) !== trim( $plan['label'] ) ) {
						$diff[] = 'label is "' . $got['label'] . '"';
					}
					if ( $got['required'] !== $plan['required'] ) {
						$diff[] = 'required is ' . ( $got['required'] ? 'on' : 'off' );
					}
					if ( $plan['choices'] && array_column( (array) ( $got['choices'] ?? array() ), 'label' ) !== $plan['choices'] ) {
						$diff[] = 'choices differ';
					}
				}
			}
			$all_ok   = $all_ok && ! $diff;
			$fields[] = array(
				'field'       => $plan['field'],
				'id'          => $id,
				'ok'          => ! $diff,
				'differences' => $diff,
			);
		}//end foreach
		$problems = array();
		if ( '' !== (string) $model['submit_text'] && trim( (string) ( $form['submit_text'] ?? '' ) ) !== trim( (string) $model['submit_text'] ) ) {
			$problems[] = 'submit text is "' . (string) ( $form['submit_text'] ?? '' ) . '"';
		}
		if ( '' !== (string) $model['confirmation_message'] && trim( wp_strip_all_tags( (string) ( $form['confirmation_message'] ?? '' ) ) ) !== trim( wp_strip_all_tags( (string) $model['confirmation_message'] ) ) ) {
			$problems[] = 'confirmation message differs';
		}
		return array(
			'ok'     => $all_ok && ! $problems,
			'fields' => $fields,
			'form'   => $problems,
		);
	}
}
