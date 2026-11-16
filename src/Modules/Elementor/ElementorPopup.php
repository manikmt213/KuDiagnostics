<?php
/**
 * Tools: kuduy-elementor-popup-settings, kuduy-elementor-popup-settings-update (Elementor Pro popups).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * When a popup opens (triggers) and for whom (timing). Elementor Pro keeps both on the popup document and offers
 * public methods for them (ElementorPro\Modules\Popup\Document, read on Pro 4.0.4): get_display_settings() returns
 * the two control stacks (Triggers, Timing) that define every setting, get_display_settings_data() the stored values
 * and save_display_settings_data() saves them. Settings are checked against those controls (name, type, options,
 * min/max) before saving. Where a popup appears (display conditions) is set with kuduy-elementor-theme-conditions.
 */
final class ElementorPopup implements Tool, Snapshottable {

	/**
	 * The two parts of a popup's display settings.
	 */
	private const SECTIONS = array( 'triggers', 'timing' );

	/**
	 * Control types that hold no value (labels) or are filled in by Elementor itself (the server's clock).
	 */
	private const NOT_SET = array( 'heading', 'hidden', 'raw_html', 'divider', 'section', 'tabs', 'tab' );

	/**
	 * Mode.
	 *
	 * @param string $mode settings | settings-update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-popup-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'settings' === $this->mode
			? __( 'Read when an Elementor popup opens', 'kuduy-mcp' )
			: __( 'Change when an Elementor popup opens', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'settings' === $this->mode
			? 'Returns an Elementor Pro popup\'s display settings: triggers (on page load, on scroll, on scroll to an element, on click, after inactivity, on exit intent, AdBlock detection) and timing rules (after X page views or sessions, up to X times, arriving from a URL or source, logged-in users, devices, browsers, schedule), with the stored values and every setting\'s type, options and limits. Each group is switched on by its own key set to "yes" (e.g. page_load = "yes", page_load_delay = 3). Popups are listed with kuduy-elementor-theme-templates (type popup), created with kuduy-elementor-create (template_type popup) and placed with kuduy-elementor-theme-conditions.'
			: 'Changes an Elementor Pro popup\'s triggers and timing: give the settings to set under triggers and/or timing, e.g. {"triggers": {"page_load": "yes", "page_load_delay": 3}, "timing": {"devices": "yes", "devices_devices": ["desktop"]}}, and names to clear under unset. Values are checked against Elementor Pro\'s own setting definitions (see kuduy-elementor-popup-settings); nothing is saved when one is wrong. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'settings-update' === $this->mode ) {
			$props['triggers'] = array(
				'type'        => 'object',
				'description' => 'Trigger settings to set, by name.',
			);
			$props['timing']   = array(
				'type'        => 'object',
				'description' => 'Timing settings to set, by name.',
			);
			$props['unset']    = array(
				'type'                 => 'object',
				'description'          => 'Names to clear (back to the default), e.g. {"triggers": ["page_load_delay"]}.',
				'properties'           => array(
					'triggers' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'timing'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'additionalProperties' => false,
			);
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'triggers'     => array( 'type' => 'object' ),
				'timing'       => array( 'type' => 'object' ),
				'controls'     => array( 'type' => 'object' ),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'triggers', 'timing' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		$write = 'settings-update' === $this->mode;
		return array(
			'readOnlyHint'    => ! $write,
			'destructiveHint' => $write,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'settings-update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$base = Elementor::available();
		if ( true !== $base ) {
			return $base;
		}
		return \Elementor\Plugin::$instance->documents->get_document_type( 'popup', false ) ? true : 'Popups need Elementor Pro, which is not active on this site.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'settings-update' !== $this->mode || $id <= 0 || ! get_post( $id ) ) {
			return null;
		}
		try {
			$data = self::stored( self::popup( $id ) );
		} catch ( ToolError $e ) {
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'   => $id,
				'data' => $data,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the popup is gone.
	 */
	public function restore( array $payload ): void {
		$doc  = self::popup( (int) ( $payload['id'] ?? 0 ) );
		$data = (array) ( $payload['data'] ?? array() );
		$doc->save_display_settings_data(
			array(
				'triggers' => (array) ( $data['triggers'] ?? array() ),
				'timing'   => (array) ( $data['timing'] ?? array() ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is not a popup or a setting is wrong.
	 */
	public function execute( array $input ): array {
		$id  = (int) $input['id'];
		$doc = self::popup( $id );
		if ( 'settings' === $this->mode ) {
			return self::describe( $doc, true );
		}
		if ( ! $doc->is_editable_by_current_user() ) {
			throw new ToolError( 'Elementor does not allow your account to edit this popup.' );
		}
		AuditLog::target( 'post', (string) $id );
		$controls = self::controls( $doc );
		$data     = self::stored( $doc );
		$changed  = array();
		$unset    = (array) ( $input['unset'] ?? array() );
		foreach ( self::SECTIONS as $section ) {
			foreach ( (array) ( $input[ $section ] ?? array() ) as $name => $value ) {
				$data[ $section ][ (string) $name ] = self::check( $section, (string) $name, $value, $controls[ $section ] );
				$changed[]                          = 'set ' . $section . '.' . $name;
			}
			foreach ( array_map( 'strval', (array) ( $unset[ $section ] ?? array() ) ) as $name ) {
				if ( ! isset( $controls[ $section ][ $name ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
					throw new ToolError( $section . '.' . $name . ': no such popup setting. See kuduy-elementor-popup-settings. Nothing was changed.' );
				}
				unset( $data[ $section ][ $name ] );
				$changed[] = 'unset ' . $section . '.' . $name;
			}
		}
		if ( ! $changed ) {
			throw new ToolError( 'Nothing to change: give triggers, timing or unset.' );
		}
		$doc->save_display_settings_data( $data );
		$out            = self::describe( self::popup( $id ), false );
		$out['changed'] = $changed;
		return $out;
	}

	/**
	 * The popup document.
	 *
	 * @param int $id Post id.
	 * @return \ElementorPro\Modules\Popup\Document
	 * @throws ToolError When the post is not an Elementor Pro popup.
	 */
	private static function popup( int $id ): object {
		$doc = Elementor::document( $id, false );
		if ( 'popup' !== $doc->get_name() || ! method_exists( $doc, 'get_display_settings' ) || ! method_exists( $doc, 'save_display_settings_data' ) ) {
			throw new ToolError( 'This is not an Elementor Pro popup. Popups are listed with kuduy-elementor-theme-templates (type popup).' );
		}
		// Checked above: a popup with Pro's display-settings methods.
		/** @var \ElementorPro\Modules\Popup\Document $doc */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline type for PHPStan.
		return $doc;
	}

	/**
	 * Stored triggers and timing.
	 *
	 * @param object $doc Popup document.
	 * @return array{triggers: array<string, mixed>, timing: array<string, mixed>}
	 */
	private static function stored( object $doc ): array {
		$data = $doc->get_display_settings_data();
		$data = is_array( $data ) ? $data : array();
		return array(
			'triggers' => is_array( $data['triggers'] ?? null ) ? $data['triggers'] : array(),
			'timing'   => is_array( $data['timing'] ?? null ) ? $data['timing'] : array(),
		);
	}

	/**
	 * Settable controls of each section, by name, from Pro's own control stacks.
	 *
	 * @param object $doc Popup document.
	 * @return array{triggers: array<string, array<string, mixed>>, timing: array<string, array<string, mixed>>}
	 */
	private static function controls( object $doc ): array {
		$stacks = (array) $doc->get_display_settings();
		$out    = array(
			'triggers' => array(),
			'timing'   => array(),
		);
		foreach ( self::SECTIONS as $section ) {
			$stack = $stacks[ $section ] ?? null;
			if ( ! is_object( $stack ) || ! method_exists( $stack, 'get_controls' ) ) {
				continue;
			}
			foreach ( (array) $stack->get_controls() as $name => $control ) {
				$type = is_array( $control ) ? strtolower( (string) ( $control['type'] ?? '' ) ) : '';
				if ( '' === $type || in_array( $type, self::NOT_SET, true ) ) {
					continue;
				}
				$out[ $section ][ (string) $name ] = $control;
			}
		}
		return $out;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the messages are returned as JSON text, never HTML.
	/**
	 * A value checked against its control, in the form Elementor stores it.
	 *
	 * @param string                              $section  triggers | timing.
	 * @param string                              $name     Setting name.
	 * @param mixed                               $value    Value given.
	 * @param array<string, array<string, mixed>> $controls Controls of the section.
	 * @return mixed
	 * @throws ToolError When the setting is unknown or the value does not fit.
	 */
	private static function check( string $section, string $name, mixed $value, array $controls ): mixed {
		$control = $controls[ $name ] ?? null;
		$where   = $section . '.' . $name;
		if ( null === $control ) {
			throw self::bad( $where, 'no such popup setting.' );
		}
		$type    = strtolower( (string) ( $control['type'] ?? '' ) );
		$options = array_map( 'strval', array_keys( (array) ( $control['options'] ?? array() ) ) );
		// Elementor drops options (and labels) from controls built on a front-end page view (Controls_Stack::add_control()
		// with Performance::should_optimize_controls(), Elementor 4.3.6); MCP requests are REST requests, where they are
		// kept. Without them only the value's form is checked.
		$known = array_key_exists( 'options', $control );
		switch ( $type ) {
			case 'switcher':
				if ( true === $value || 'yes' === $value ) {
					return 'yes';
				}
				if ( false === $value || '' === $value || 'no' === $value ) {
					return '';
				}
				throw self::bad( $where, 'use "yes" (on) or "" (off).' );
			case 'number':
				if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
					throw self::bad( $where, 'must be a number.' );
				}
				$number = (float) $value;
				if ( isset( $control['min'] ) && $number < (float) $control['min'] ) {
					throw self::bad( $where, 'must be at least ' . $control['min'] . '.' );
				}
				if ( isset( $control['max'] ) && $number > (float) $control['max'] ) {
					throw self::bad( $where, 'must be at most ' . $control['max'] . '.' );
				}
				return floor( $number ) === $number ? (int) $number : $number;
			case 'select':
			case 'select2':
				if ( ! empty( $control['multiple'] ) ) {
					if ( ! is_array( $value ) || array_filter( $value, static fn( $v ) => ! is_string( $v ) && ! is_int( $v ) ) ) {
						throw self::bad( $where, 'must be a list of: ' . implode( ', ', $options ) . '.' );
					}
					$list = array_values( array_map( 'strval', (array) $value ) );
					$bad  = $known ? array_diff( $list, $options ) : array();
					if ( $bad ) {
						throw self::bad( $where, 'unknown ' . implode( ', ', $bad ) . '; choose from ' . implode( ', ', $options ) . '.' );
					}
					return $list;
				}
				if ( ! is_string( $value ) && ! is_int( $value ) ) {
					throw self::bad( $where, 'must be one of: ' . implode( ', ', $options ) . '.' );
				}
				if ( $known && ! in_array( (string) $value, $options, true ) ) {
					throw self::bad( $where, 'must be one of: ' . implode( ', ', array_map( static fn( string $o ): string => '' === $o ? '""' : $o, $options ) ) . '.' );
				}
				return (string) $value;
			case 'text':
				if ( ! is_string( $value ) ) {
					throw self::bad( $where, 'must be text.' );
				}
				return sanitize_text_field( $value );
			case 'date_time':
				if ( ! is_string( $value ) || ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value ) ) ) {
					throw self::bad( $where, 'must be a date as "YYYY-MM-DD HH:MM", or "" to clear.' );
				}
				return $value;
		}//end switch
		throw self::bad( $where, 'settings of type ' . $type . ' are not changed here; use the Elementor editor.' );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The error for a setting that does not fit (JSON text, never HTML).
	 *
	 * @param string $where Section and name, e.g. triggers.page_load_delay.
	 * @param string $why   What is wrong.
	 */
	private static function bad( string $where, string $why ): ToolError {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
		return new ToolError( $where . ': ' . $why . ' See kuduy-elementor-popup-settings. Nothing was changed.' );
	}

	/**
	 * The popup as the tools return it.
	 *
	 * @param object $doc           Popup document.
	 * @param bool   $with_controls Include the setting definitions.
	 * @return array<string, mixed>
	 */
	private static function describe( object $doc, bool $with_controls ): array {
		$post = $doc->get_post();
		$data = self::stored( $doc );
		$out  = array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'status'   => (string) $post->post_status,
			'triggers' => $data['triggers'] ? $data['triggers'] : new \stdClass(),
			'timing'   => $data['timing'] ? $data['timing'] : new \stdClass(),
		);
		if ( $with_controls ) {
			$controls = array();
			foreach ( self::controls( $doc ) as $section => $list ) {
				$rows = array();
				foreach ( $list as $name => $control ) {
					$row = array(
						'name'    => $name,
						'type'    => strtolower( (string) ( $control['type'] ?? '' ) ),
						'label'   => wp_strip_all_tags( html_entity_decode( (string) ( $control['label'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
						'default' => $control['default'] ?? null,
					);
					foreach ( array( 'min', 'max', 'multiple' ) as $key ) {
						if ( isset( $control[ $key ] ) ) {
							$row[ $key ] = $control[ $key ];
						}
					}
					if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
						$row['options'] = array_map( static fn( $o ) => is_array( $o ) ? (string) ( $o['title'] ?? '' ) : wp_strip_all_tags( (string) $o ), $control['options'] );
					}
					if ( ! empty( $control['condition'] ) && is_array( $control['condition'] ) ) {
						$row['only_when'] = $control['condition'];
					}
					$rows[] = $row;
				}
				$controls[ $section ] = $rows;
			}//end foreach
			$out['controls'] = $controls;
		}//end if
		return $out;
	}
}
