<?php
/**
 * Tool: kuduy-avada-global-options-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Avada;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Changes Avada Global Options (phase Q4) through Avada's settings class, Fusion_Settings (read on Avada 7.16 on a live
 * site, 2026-11-18: includes/lib/inc/class-fusion-settings.php). Fusion_Settings::set() writes one setting into the
 * option named by get_option_name() (the language's copy on multilingual sites) from the values it loaded at start,
 * so several set() calls in one request would undo each other; this tool merges all changes into get_all() and saves
 * that option once, as set() does, then reloads Avada's copy (get_available_options()) and resets Avada's caches
 * (fusion_reset_all_caches(), so the generated CSS follows). Only settings that already exist can be changed, a value
 * keeps its kind (text, number, list of sub-values), and secret settings are refused. Snapshot first; undo puts the
 * previous values back.
 */
final class AvadaOptions implements Tool, Snapshottable {

	/**
	 * Settings that are never changed here (as never read by kuduy-avada-global-options).
	 */
	private const SECRET = '/key|secret|token|password|passwd|api|captcha|private|license|purchase|mailchimp|hubspot|client_id/i';

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-avada-global-options-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Change Avada Global Options', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Changes Avada Global Options (Avada > Options): colours, typography, layout widths, breakpoints, … Give settings as name => value with names from kuduy-avada-global-options, e.g. {"primary_color":"#1e73be","site_width":"1200px"}; a setting with sub-values (typography) takes an object of the sub-values to change, e.g. {"body_typography":{"font-size":"17px"}}. Only existing settings can be changed and each keeps its kind; API keys and other secrets are not changed here. Applies to the whole site; Avada\'s caches are reset so the new CSS is built. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'maxProperties' => 40,
				),
			),
			'required'             => array( 'settings' ),
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
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'settings'     => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'changed', 'settings' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return class_exists( '\Fusion_Settings' ) && method_exists( '\Fusion_Settings', 'get_option_name' )
			? true
			: 'The Avada theme (its Global Options) is not active on this site.';
	}

	/**
	 * Avada's settings object.
	 */
	private static function settings(): object {
		return call_user_func( array( '\Fusion_Settings', 'get_instance' ) );
	}

	/**
	 * Name of the option holding the Global Options (per language on multilingual sites).
	 */
	private static function option(): string {
		return (string) call_user_func( array( '\Fusion_Settings', 'get_option_name' ) );
	}

	/**
	 * All saved Global Options.
	 *
	 * @return array<string, mixed>
	 */
	private static function all(): array {
		$all = get_option( self::option(), array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * The new values, checked against the saved ones.
	 *
	 * @param array<string, mixed> $settings Requested changes.
	 * @return array<string, mixed>
	 * @throws ToolError When a name is unknown or secret, or a value has the wrong kind.
	 */
	private static function checked( array $settings ): array {
		$all = self::all();
		$out = array();
		foreach ( $settings as $name => $value ) {
			$name = (string) $name;
			if ( preg_match( self::SECRET, $name ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'Avada setting ' . $name . ' holds a secret and is not changed here. Nothing was changed.' );
			}
			if ( ! array_key_exists( $name, $all ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Avada has no saved Global Option ' . $name . ' (see kuduy-avada-global-options). Nothing was changed.' );
			}
			$old = $all[ $name ];
			if ( is_array( $old ) ) {
				if ( ! is_array( $value ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'Avada setting ' . $name . ' has sub-values (' . implode( ', ', array_map( 'strval', array_keys( $old ) ) ) . '); send an object of the ones to change. Nothing was changed.' );
				}
				foreach ( $value as $sub => $sub_value ) {
					if ( ! array_key_exists( (string) $sub, $old ) || ! is_scalar( $sub_value ) ) {
						throw ToolError::of( ToolError::VALIDATION_FAILED, 'Avada setting ' . $name . ' has no sub-value ' . $sub . ' that takes this value. Nothing was changed.' );
					}
					$old[ (string) $sub ] = sanitize_text_field( (string) $sub_value );
				}
				$out[ $name ] = $old;
				continue;
			}
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Avada setting ' . $name . ' takes a single value. Nothing was changed.' );
			}
			$out[ $name ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}//end foreach
		return $out;
	}

	/**
	 * Save values into the Global Options option at once, as Fusion_Settings::set() saves one.
	 *
	 * @param array<string, mixed> $values Name => value (null removes the setting).
	 */
	private static function write( array $values ): void {
		$all = self::all();
		foreach ( $values as $name => $value ) {
			if ( null === $value ) {
				unset( $all[ $name ] );
			} else {
				$all[ $name ] = $value;
			}
		}
		update_option( self::option(), $all );
		$settings = self::settings();
		if ( method_exists( $settings, 'get_available_options' ) ) {
			$settings->get_available_options();
		}
		if ( function_exists( 'fusion_reset_all_caches' ) ) {
			fusion_reset_all_caches();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): array {
		$all  = self::all();
		$prev = array();
		foreach ( array_keys( (array) $input['settings'] ) as $name ) {
			$prev[ (string) $name ] = $all[ (string) $name ] ?? null;
		}
		return array(
			'object_type' => 'option',
			'object_id'   => self::option(),
			'payload'     => array( 'values' => $prev ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		self::write( (array) ( $payload['values'] ?? array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a setting is refused.
	 */
	public function execute( array $input ): array {
		$values = self::checked( (array) $input['settings'] );
		AuditLog::target( 'option', self::option() );
		self::write( $values );
		$all = self::all();
		return array(
			'changed'  => array_keys( $values ),
			'settings' => (object) array_intersect_key( $all, $values ),
		);
	}
}
