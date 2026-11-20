<?php
/**
 * Tools: kuduy-wp-rocket-settings, kuduy-wp-rocket-setting-update, kuduy-wp-rocket-cache-status.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Cache;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * WP Rocket settings and cache state through WP Rocket's own abilities (phase L5; read on WP Rocket 3.24,
 * inc/Engine/Abilities, Preload/Abilities): wp-rocket/get-options (only the options on its MCP allowlist, so license
 * and API keys are never read), wp-rocket/set-option (its allowlist minus its read-only options, its own sanitizing,
 * "update" adds to list options and "replace" overwrites), wp-rocket/get-cache-status (one URL, post or term in its
 * preload table) and wp-rocket/get-cache-health (preload queue counts). Every ability checks rocket_manage_options.
 * Clearing the cache stays with kuduy-cache-purge (rocket_clean_domain / rocket_clean_post / rocket_clean_files);
 * when WP Rocket's preload is on, clearing the whole cache starts its preload (Preload\Subscriber::clean_full_cache).
 */
final class WpRocketTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode settings | setting-update | cache-status.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-wp-rocket-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'settings'       => 'WP Rocket settings',
			'setting-update' => 'Change a WP Rocket setting',
			default          => 'WP Rocket cache status',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'settings'       => 'Reads the WP Rocket settings WP Rocket exposes to AI tools (cache, CSS, JS, media, fonts, preload, database cleanup, CDN, Cloudflare switches, heartbeat): option name => value. License and API keys are not part of it.',
			'setting-update' => 'Changes one WP Rocket setting through WP Rocket\'s own set-option ability (only the options it allows; it sanitizes the value). For list options, update_mode "update" (default) adds entries and "replace" overwrites the list. Settings such as delay_js or remove_unused_css can change how pages look: check the site afterwards. Snapshot first; undo with kuduy-snapshot-restore.',
			default          => 'Reports WP Rocket\'s cache state: the preload queue (pending, in progress, completed, failed) and, for a url, post_id or term_id (+ taxonomy), whether that page is cached and when. Page state needs WP Rocket\'s preload switched on.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array();
		$required   = array();
		if ( 'setting-update' === $this->mode ) {
			$properties = array(
				'option'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'value'       => array(
					'anyOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'boolean' ),
						array( 'type' => 'integer' ),
						array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => 500,
						),
					),
				),
				'update_mode' => array(
					'type' => 'string',
					'enum' => array( 'update', 'replace' ),
				),
			);
			$required   = array( 'option', 'value' );
		} elseif ( 'cache-status' === $this->mode ) {
			$properties = array(
				'url'      => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'taxonomy' => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
			);
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
				'settings'       => array( 'type' => 'object' ),
				'option'         => array( 'type' => 'string' ),
				'previous_value' => array(),
				'new_value'      => array(),
				'health'         => array( 'type' => 'object' ),
				'page'           => array( 'type' => 'object' ),
				'_snapshot_id'   => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'setting-update' !== $this->mode ) {
			return Content::read_only();
		}
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
		return 'setting-update' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// WP Rocket's abilities check rocket_manage_options as well.
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			return 'WP Rocket is not active.';
		}
		$name = match ( $this->mode ) {
			'settings'       => 'wp-rocket/get-options',
			'setting-update' => 'wp-rocket/set-option',
			default          => 'wp-rocket/get-cache-health',
		};
		return function_exists( 'wp_get_ability' ) && wp_get_ability( $name ) ? true : 'WP Rocket\'s abilities are not registered (WP Rocket 3.24 or later with the rocket_enable_abilities filter on).';
	}

	/**
	 * Run one of WP Rocket's abilities.
	 *
	 * @param string                    $name  Ability.
	 * @param array<string, mixed>|null $input Input (null when it takes none).
	 * @return array<string, mixed>
	 * @throws ToolError When it is missing or refuses.
	 */
	private static function ability( string $name, ?array $input ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'WP Rocket\'s ability ' . $name . ' is not registered on this site.' );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED === $result->get_error_code() ? ToolError::PERMISSION_DENIED : ToolError::VALIDATION_FAILED, 'WP Rocket refused: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		$result = is_array( $result ) ? $result : array();
		if ( false === ( $result['success'] ?? true ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'WP Rocket refused: ' . wp_strip_all_tags( (string) ( $result['error'] ?? 'no reason given' ) ) . ' Nothing was changed.' );
		}
		return $result;
	}

	/**
	 * Current settings through get-options.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		return self::ability( 'wp-rocket/get-options', null );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the option is not readable through WP Rocket.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'setting-update' !== $this->mode ) {
			return null;
		}
		$option   = (string) $input['option'];
		$settings = self::settings();
		if ( ! $settings ) {
			// Found live: right after activation WP Rocket has not set up its settings yet.
			throw ToolError::of( ToolError::CONFLICT, 'WP Rocket has not set up its settings yet (open its settings screen once, or try again). Nothing was changed.' );
		}
		if ( ! array_key_exists( $option, $settings ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'WP Rocket does not let tools change "' . $option . '". Options: ' . implode( ', ', array_keys( $settings ) ) . '.' );
		}
		return array(
			'object_type' => 'wp_rocket_option',
			'object_id'   => $option,
			'payload'     => array(
				'option' => $option,
				'value'  => $settings[ $option ],
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$value = $payload['value'];
		self::ability(
			'wp-rocket/set-option',
			array(
				'option_name'  => (string) $payload['option'],
				'option_value' => is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : $value,
				'update_mode'  => 'replace',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		if ( 'settings' === $this->mode ) {
			return array( 'settings' => (object) self::settings() );
		}
		if ( 'setting-update' === $this->mode ) {
			$option = (string) $input['option'];
			AuditLog::target( 'wp_rocket_option', $option );
			$args = array(
				'option_name'  => $option,
				'option_value' => $input['value'],
			);
			if ( isset( $input['update_mode'] ) ) {
				$args['update_mode'] = (string) $input['update_mode'];
			}
			$result = self::ability( 'wp-rocket/set-option', $args );
			return array(
				'option'         => $option,
				'previous_value' => $result['previous_value'] ?? null,
				'new_value'      => $result['new_value'] ?? null,
			);
		}
		$out   = array( 'health' => (object) self::ability( 'wp-rocket/get-cache-health', null ) );
		$which = array_intersect_key( $input, array_flip( array( 'url', 'post_id', 'term_id', 'taxonomy' ) ) );
		if ( $which && function_exists( 'wp_get_ability' ) && wp_get_ability( 'wp-rocket/get-cache-status' ) ) {
			$out['page'] = (object) self::ability( 'wp-rocket/get-cache-status', $which );
		}
		return $out;
	}
}
