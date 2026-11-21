<?php
/**
 * Tools: kuduy-cf7-form-tags, kuduy-cf7-form-edit, kuduy-cf7-validate (phase B2, D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Forms\Cf7Adapter;
use Kuduy\Mcp\Modules\Forms\Cf7Save;

/**
 * Field-level work on Contact Form 7 form markup (read on Contact Form 7 6.2.4.1, includes/form-tags-manager.php and
 * includes/config-validator/validator.php, 2026-11-18). A CF7 form is markup with form-tags such as
 * [email* your-email placeholder "you@example.com"]; CF7 reads them with WPCF7_FormTagsManager::scan(), whose
 * pattern is private, so tags are found here with the same shape of pattern over the tag types CF7 has registered
 * (collect_tag_types()) and every tag found is parsed by CF7's own scan(). Tags are addressed by name (nameless
 * tags such as submit as "submit#1"); a tag directly inside a <label> moves and goes with its label, as in CF7's
 * default template. The changed markup is scanned again, saved with wpcf7_save_contact_form() and checked with
 * WPCF7_ConfigValidator, whose messages are returned. Names are kept: mail templates refer to them.
 */
final class Cf7Tags implements Tool, Snapshottable {

	/**
	 * Constructor.
	 *
	 * @param string $mode form-tags | form-edit | validate.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-cf7-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'form-tags' => 'Contact Form 7: form-tags',
			'form-edit' => 'Contact Form 7: edit form-tags',
			default     => 'Contact Form 7: check a form',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'form-tags' => 'Lists the form-tag types Contact Form 7 offers on this site (text, email, textarea, select, checkbox, radio, acceptance, file, submit, …; whether each takes a name and a required mark) and, with form_id, the form\'s tags in order: id (the name, or "submit#1" for nameless tags), type, required, options (e.g. placeholder, class:x, default:1, include_blank), values (choices or default text) and the label text around it.',
			'form-edit' => 'Changes the form-tags of a Contact Form 7 form, up to 30 operations in order: add {"op":"add","type":"email","name":"work-email","required":true,"options":["placeholder"],"values":["you@example.com"],"label":"Work email","before":"submit#1"} (default: before the submit button), update {"op":"update","tag":"your-name","required":false,"options":[…],"values":[…],"label":"…"} (left-out parts stay; the name and type stay), remove {"op":"remove","tag":"your-subject"}, move {"op":"move","tag":"your-email","after":"your-name"}. A tag inside a <label> goes with its label. Every change is parsed by CF7 before saving; returns the tags and CF7\'s configuration warnings. Mail templates use tag names: check the mail with kuduy-forms-get after removing a tag. Snapshot first; undo with kuduy-snapshot-restore.',
			default     => 'Runs Contact Form 7\'s own configuration check (the warnings its editor shows: form-tags, mail, messages, additional settings) on a saved form (form_id) or on form markup before it is saved (form, optionally with form_id for that form\'s mail and messages). Changes nothing.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$tag_ref = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 100,
		);
		$list    = array(
			'type'     => 'array',
			'maxItems' => 100,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
		);
		return match ( $this->mode ) {
			'form-tags' => array(
				'type'                 => 'object',
				'properties'           => array( 'form_id' => $id ),
				'additionalProperties' => false,
			),
			'form-edit' => array(
				'type'                 => 'object',
				'properties'           => array(
					'form_id'    => $id,
					'operations' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 30,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'op'       => array(
									'type' => 'string',
									'enum' => array( 'add', 'update', 'remove', 'move' ),
								),
								'tag'      => $tag_ref,
								'type'     => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 60,
								),
								'name'     => $tag_ref,
								'required' => array( 'type' => 'boolean' ),
								'options'  => $list,
								'values'   => $list,
								'content'  => array(
									'type'      => 'string',
									'maxLength' => 2000,
								),
								'label'    => array(
									'type'      => 'string',
									'maxLength' => 500,
								),
								'before'   => $tag_ref,
								'after'    => $tag_ref,
							),
							'required'             => array( 'op' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'form_id', 'operations' ),
				'additionalProperties' => false,
			),
			default     => array(
				'type'                 => 'object',
				'properties'           => array(
					'form_id' => $id,
					'form'    => array(
						'type'      => 'string',
						'maxLength' => 200000,
					),
				),
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
				'types'         => array( 'type' => 'array' ),
				'tags'          => array( 'type' => 'array' ),
				'valid'         => array( 'type' => 'boolean' ),
				'config_errors' => array( 'type' => 'object' ),
				'_snapshot_id'  => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'form-edit' !== $this->mode,
			'destructiveHint' => 'form-edit' === $this->mode,
			'idempotentHint'  => 'form-edit' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'form-edit' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['form_id'] ?? 0 );
		if ( 'form-tags' === $this->mode ) {
			return $id > 0 ? array( 'wpcf7_read_contact_form', $id ) : 'wpcf7_read_contact_forms';
		}
		return $id > 0 ? array( 'wpcf7_edit_contact_form', $id ) : 'wpcf7_edit_contact_forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_FormTagsManager' ) && function_exists( 'wpcf7_save_contact_form' )
			? true
			: 'Contact Form 7 is not active on this site.';
	}

	/**
	 * CF7's tag manager.
	 */
	private static function manager(): \WPCF7_FormTagsManager {
		return \WPCF7_FormTagsManager::get_instance();
	}

	/**
	 * Tags of markup with their place: CF7's tag shape over its registered types, each parsed by CF7's scan().
	 *
	 * @param string $markup Markup.
	 * @return list<array<string, mixed>> Each: id, tag (WPCF7_FormTag), start, end, block_start, block_end, label.
	 */
	private static function locate( string $markup ): array {
		$types = (array) self::manager()->collect_tag_types();
		if ( ! $types ) {
			return array();
		}
		usort( $types, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );
		$pattern = '/(\[?)\[(' . implode( '|', array_map( static fn( $t ) => preg_quote( (string) $t, '/' ), $types ) ) . ')(?:\s+(.*?))?(?:\s+(\/))?\](?:([^[]*?)\[\/\2\])?(\]?)/su';
		preg_match_all( $pattern, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$out    = array();
		$counts = array();
		foreach ( $matches as $match ) {
			if ( '[' === $match[1][0] && ']' === $match[6][0] ) {
				continue;
			}
			$source = substr( $match[0][0], strlen( $match[1][0] ), strlen( $match[0][0] ) - strlen( $match[1][0] ) - strlen( $match[6][0] ) );
			$parsed = self::manager()->scan( $source );
			if ( ! $parsed ) {
				continue;
			}
			$tag   = $parsed[0];
			$start = (int) $match[0][1] + strlen( $match[1][0] );
			$end   = $start + strlen( $source );
			$base  = (string) $tag->basetype;
			if ( '' === (string) $tag->name ) {
				$counts[ $base ] = ( $counts[ $base ] ?? 0 ) + 1;
				$id              = $base . '#' . $counts[ $base ];
			} else {
				$id = (string) $tag->name;
			}
			$row = array(
				'id'          => $id,
				'tag'         => $tag,
				'source'      => $source,
				'start'       => $start,
				'end'         => $end,
				'block_start' => $start,
				'block_end'   => $end,
				'label'       => null,
			);
			// A tag directly inside <label>text [tag] </label>: the label is its block.
			$open = strrpos( substr( $markup, 0, $start ), '<label' );
			if ( false !== $open ) {
				$open_end = strpos( $markup, '>', $open );
				$close    = strpos( $markup, '</label>', $end );
				if ( false !== $open_end && false !== $close && $open_end < $start ) {
					$before = substr( $markup, $open_end + 1, $start - $open_end - 1 );
					$after  = substr( $markup, $end, $close - $end );
					if ( ! preg_match( '/[<\[]/', $before ) && ! preg_match( '/[<\[]/', $after ) ) {
						$row['block_start'] = $open;
						$row['block_end']   = $close + strlen( '</label>' );
						$row['label']       = trim( $before . ' ' . $after );
					}
				}
			}
			$out[] = $row;
		}//end foreach
		return $out;
	}

	/**
	 * The tags of a form, as kuduy-cf7-form-tags lists them (used by the form migration, phase B3).
	 *
	 * @param int $form_id Form id.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When the form does not exist.
	 */
	public static function tags( int $form_id ): array {
		return array_map( array( self::class, 'show' ), self::locate( (string) Cf7Adapter::find( $form_id )->prop( 'form' ) ) );
	}

	/**
	 * A tag as the tools show it.
	 *
	 * @param array<string, mixed> $row Located tag.
	 * @return array<string, mixed>
	 */
	private static function show( array $row ): array {
		$tag = $row['tag'];
		$out = array(
			'id'       => $row['id'],
			'type'     => (string) $tag->basetype,
			'name'     => (string) $tag->name,
			'required' => str_ends_with( (string) $tag->type, '*' ),
			'options'  => array_values( array_map( 'strval', (array) $tag->options ) ),
			'values'   => array_values( array_map( 'strval', (array) $tag->raw_values ) ),
			'source'   => $row['source'],
		);
		if ( '' !== (string) $tag->content ) {
			$out['content'] = (string) $tag->content;
		}
		if ( null !== $row['label'] ) {
			$out['label'] = $row['label'];
		}
		return $out;
	}

	/**
	 * Tag types CF7 offers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function types(): array {
		$out = array();
		foreach ( (array) self::manager()->collect_tag_types() as $type ) {
			$type = (string) $type;
			if ( str_ends_with( $type, '*' ) ) {
				continue;
			}
			$out[] = array(
				'type'         => $type,
				'takes_name'   => self::manager()->tag_type_supports( $type, 'name-attr' ),
				'can_require'  => self::manager()->tag_type_exists( $type . '*' ),
				'one_per_form' => self::manager()->tag_type_supports( $type, 'singular' ),
			);
		}
		return $out;
	}

	/**
	 * Build a tag's source.
	 *
	 * @param string       $type     Base type.
	 * @param bool         $required Required mark.
	 * @param string       $name     Name ('' for nameless types).
	 * @param list<string> $options  Options.
	 * @param list<string> $values   Values.
	 * @param string       $content  Content between opening and closing tag ('' for none).
	 * @throws ToolError When a part cannot be written as a form-tag.
	 */
	private static function build( string $type, bool $required, string $name, array $options, array $values, string $content ): string {
		$manager = self::manager();
		if ( ! $manager->tag_type_exists( $type ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Contact Form 7 has no form-tag type "' . $type . '" on this site (see kuduy-cf7-form-tags).' );
		}
		if ( $required && ! $manager->tag_type_exists( $type . '*' ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' tag cannot be required.' );
		}
		$takes_name = $manager->tag_type_supports( $type, 'name-attr' );
		if ( $takes_name && ! wpcf7_is_name( $name ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'A ' . $type . ' tag needs a name of letters, digits, -, _, : or . that starts with a letter.' );
		}
		$source = '[' . $type . ( $required ? '*' : '' ) . ( $takes_name ? ' ' . $name : '' );
		foreach ( $options as $option ) {
			if ( ! preg_match( '/^[^\s"\'\[\]]+$/u', $option ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Option "' . $option . '" is not a form-tag option (one word such as placeholder, class:name, id:name, default:1).' );
			}
			$source .= ' ' . $option;
		}
		foreach ( $values as $value ) {
			if ( preg_match( '/["\[\]]/u', $value ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Value "' . $value . '" holds a quote or bracket, which a form-tag value cannot.' );
			}
			$source .= ' "' . $value . '"';
		}
		$source .= ']';
		if ( '' !== $content ) {
			if ( str_contains( $content, '[' ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Tag content cannot hold "[".' );
			}
			$source .= wp_kses_post( $content ) . '[/' . $type . ( $required ? '*' : '' ) . ']';
		}
		return $source;
	}

	/**
	 * A block (the tag, wrapped in a label when one is given).
	 *
	 * @param string      $source Tag source.
	 * @param string|null $label  Label text.
	 */
	private static function block( string $source, ?string $label ): string {
		return null === $label || '' === $label ? $source : '<label> ' . esc_html( $label ) . "\n    " . $source . ' </label>';
	}

	/**
	 * Located tag by id.
	 *
	 * @param list<array<string, mixed>> $tags Tags.
	 * @param string                     $id   Id.
	 * @param string                     $where Operation label.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such tag.
	 */
	private static function find( array $tags, string $id, string $where ): array {
		foreach ( $tags as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $where . ': the form has no form-tag "' . $id . '" at that point (ids as kuduy-cf7-form-tags lists them). Nothing was changed.' );
	}

	/**
	 * Insert a block before or after a tag's block, or before the submit button, or at the end.
	 *
	 * @param string               $markup Markup.
	 * @param string               $block  Block.
	 * @param array<string, mixed> $op     Operation.
	 * @param string               $where  Operation label.
	 * @throws ToolError When the reference tag is missing.
	 */
	private static function insert( string $markup, string $block, array $op, string $where ): string {
		$tags = self::locate( $markup );
		if ( isset( $op['after'] ) ) {
			$at = self::find( $tags, (string) $op['after'], $where )['block_end'];
			return substr( $markup, 0, $at ) . "\n\n" . $block . substr( $markup, $at );
		}
		$ref = null;
		if ( isset( $op['before'] ) ) {
			$ref = self::find( $tags, (string) $op['before'], $where );
		} else {
			foreach ( $tags as $row ) {
				if ( 'submit' === (string) $row['tag']->basetype ) {
					$ref = $row;
					break;
				}
			}
		}
		if ( null === $ref ) {
			return rtrim( $markup ) . "\n\n" . $block . "\n";
		}
		$at = $ref['block_start'];
		return substr( $markup, 0, $at ) . $block . "\n\n" . substr( $markup, $at );
	}

	/**
	 * Markup with a tag's block cut out.
	 *
	 * @param string               $markup Markup.
	 * @param array<string, mixed> $row    Located tag.
	 */
	private static function cut( string $markup, array $row ): string {
		$out = substr( $markup, 0, $row['block_start'] ) . substr( $markup, $row['block_end'] );
		return (string) preg_replace( "/\n[ \t]*\n(?:[ \t]*\n)+/", "\n\n", $out );
	}

	/**
	 * Apply operations to markup.
	 *
	 * @param string                     $markup     Markup.
	 * @param list<array<string, mixed>> $operations Operations.
	 * @throws ToolError When an operation is refused.
	 */
	private static function apply( string $markup, array $operations ): string {
		foreach ( $operations as $n => $op ) {
			$where = 'Operation ' . ( $n + 1 ) . ' (' . $op['op'] . ')';
			$tags  = self::locate( $markup );
			try {
				if ( 'add' === $op['op'] ) {
					$name = (string) ( $op['name'] ?? '' );
					foreach ( $tags as $row ) {
						if ( '' !== $name && (string) $row['tag']->name === $name ) {
							throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'the form already has a tag named "' . $name . '".' );
						}
					}
					$source = self::build( (string) ( $op['type'] ?? '' ), ! empty( $op['required'] ), $name, array_values( (array) ( $op['options'] ?? array() ) ), array_values( (array) ( $op['values'] ?? array() ) ), (string) ( $op['content'] ?? '' ) );
					$markup = self::insert( $markup, self::block( $source, isset( $op['label'] ) ? (string) $op['label'] : null ), $op, $where );
					continue;
				}
				$row = self::find( $tags, (string) ( $op['tag'] ?? '' ), $where );
				if ( 'remove' === $op['op'] ) {
					$markup = self::cut( $markup, $row );
					continue;
				}
				if ( 'move' === $op['op'] ) {
					if ( ! isset( $op['before'] ) && ! isset( $op['after'] ) ) {
						throw ToolError::of( ToolError::INVALID_ARGUMENT, 'give before or after.' );
					}
					$block  = substr( $markup, $row['block_start'], $row['block_end'] - $row['block_start'] );
					$markup = self::insert( self::cut( $markup, $row ), $block, $op, $where );
					continue;
				}
				$tag = $row['tag'];
				if ( ( isset( $op['name'] ) && (string) $op['name'] !== (string) $tag->name ) || ( isset( $op['type'] ) && (string) $op['type'] !== (string) $tag->basetype ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'a tag keeps its name and type (mail templates use the name); add a new tag instead.' );
				}
				$source = self::build(
					(string) $tag->basetype,
					array_key_exists( 'required', $op ) ? (bool) $op['required'] : str_ends_with( (string) $tag->type, '*' ),
					(string) $tag->name,
					array_values( isset( $op['options'] ) ? (array) $op['options'] : array_map( 'strval', (array) $tag->options ) ),
					array_values( isset( $op['values'] ) ? (array) $op['values'] : array_map( 'strval', (array) $tag->raw_values ) ),
					isset( $op['content'] ) ? (string) $op['content'] : (string) $tag->content
				);
				$label  = array_key_exists( 'label', $op ) ? (string) $op['label'] : $row['label'];
				$block  = self::block( $source, $label );
				$markup = substr( $markup, 0, $row['block_start'] ) . $block . substr( $markup, $row['block_end'] );
			} catch ( ToolError $e ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, ( str_starts_with( $e->getMessage(), 'Operation ' ) ? '' : $where . ': ' ) . $e->getMessage() . ( str_contains( $e->getMessage(), 'Nothing was changed' ) ? '' : ' Nothing was changed.' ) );
			}//end try
		}//end foreach
		return $markup;
	}

	/**
	 * CF7's configuration check of a form object, without saving the result.
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array<int|string, mixed>|\stdClass
	 */
	private static function check( \WPCF7_ContactForm $form ): array|\stdClass {
		if ( ! class_exists( 'WPCF7_ConfigValidator' ) ) {
			return new \stdClass();
		}
		$validator = new \WPCF7_ConfigValidator( $form );
		$validator->validate();
		$errors = (array) $validator->collect_error_messages( array( 'decodes_html_entities' => true ) );
		return $errors ? $errors : new \stdClass();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'form-edit' !== $this->mode ) {
			return null;
		}
		$form = Cf7Adapter::find( (int) $input['form_id'] );
		return array(
			'object_type' => 'cf7_form',
			'object_id'   => (string) $form->id(),
			'payload'     => array(
				'form_id' => (int) $form->id(),
				'form'    => (string) $form->prop( 'form' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the form is gone.
	 */
	public function restore( array $payload ): void {
		$form = Cf7Adapter::find( (int) ( $payload['form_id'] ?? 0 ) );
		wpcf7_save_contact_form(
			array(
				'id'   => (int) $form->id(),
				'form' => (string) ( $payload['form'] ?? '' ),
			),
			'save'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the form is missing or an operation is refused.
	 */
	public function execute( array $input ): array {
		$id = (int) ( $input['form_id'] ?? 0 );
		if ( 'form-tags' === $this->mode ) {
			$out = array( 'types' => self::types() );
			if ( $id > 0 ) {
				$out['tags'] = array_map( array( self::class, 'show' ), self::locate( (string) Cf7Adapter::find( $id )->prop( 'form' ) ) );
			}
			return $out;
		}
		if ( 'validate' === $this->mode ) {
			if ( $id <= 0 && ! isset( $input['form'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give form_id, form markup, or both.' );
			}
			$form = $id > 0 ? clone Cf7Adapter::find( $id ) : \WPCF7_ContactForm::get_template();
			if ( isset( $input['form'] ) ) {
				$form->set_properties( array( 'form' => (string) $input['form'] ) );
			}
			$errors = self::check( $form );
			return array(
				'valid'         => $errors instanceof \stdClass,
				'config_errors' => $errors,
				'tags'          => array_map( array( self::class, 'show' ), self::locate( (string) $form->prop( 'form' ) ) ),
			);
		}
		$form   = Cf7Adapter::find( $id );
		$before = self::locate( (string) $form->prop( 'form' ) );
		$markup = self::apply( (string) $form->prop( 'form' ), array_values( (array) $input['operations'] ) );
		$after  = self::locate( $markup );
		$kept   = array_diff( array_column( $before, 'id' ), array_column( $after, 'id' ) );
		$asked  = array_map( static fn( $o ) => (string) ( $o['tag'] ?? '' ), array_filter( (array) $input['operations'], static fn( $o ) => 'remove' === $o['op'] ) );
		$lost   = array_values( array_diff( $kept, $asked ) );
		if ( $lost ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'After the change Contact Form 7 would no longer read these tags: ' . implode( ', ', $lost ) . '. Nothing was changed.' );
		}
		AuditLog::target( 'cf7_form', (string) $id );
		$saved = wpcf7_save_contact_form(
			array(
				'id'   => $id,
				'form' => $markup,
			),
			'save'
		);
		if ( ! $saved instanceof \WPCF7_ContactForm ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Contact Form 7 did not save the form.' );
		}
		return array(
			'tags'          => array_map( array( self::class, 'show' ), self::locate( (string) $saved->prop( 'form' ) ) ),
			'config_errors' => Cf7Save::config_errors( $saved ),
		);
	}
}
