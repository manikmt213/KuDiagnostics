<?php
/**
 * Tools: kuduy-elementor-addon-modules, kuduy-elementor-addon-module-set.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Widget, extension and feature switches of Elementor add-ons, each through the add-on's own save path (phase F2, D68;
 * sources of HT Mega 3.2.9, Element Pack Lite 10.0.12 and Happy Addons 3.52.0).
 *
 * - HT Mega: the field list of HTMegaOpt\Admin\Options_Field::get_registered_settings(), sections htmega_element_tabs
 *   (widgets) and htmega_advance_element_tabs (extensions), values on/off; saved through its REST route
 *   POST htmegaopt/v1/settings (manage_options + its nonce), which stores exactly the section it receives, so every
 *   switch of the section is sent with its current value. Pro items (its save skips them) and the modules with nested
 *   settings (theme builder, mega menu, …) are left to HT Mega's screen.
 * - Element Pack: its REST routes GET/POST element-pack/v1/modules, tiers core, third_party, extensions; a tier is
 *   saved as a whole {slug: on|off} map, as its screen does.
 * - Happy Addons: inactive lists through Widgets_Manager::save_inactive_widgets(),
 *   Extensions_Manager::save_inactive_features() and save_inactive_extensions(); always-on features stay on.
 *
 * Undo saves the previous switches back through the same path.
 */
final class AddonModules implements Tool, Snapshottable {

	/**
	 * Add-ons and their groups.
	 */
	private const GROUPS = array(
		'htmega'       => array( 'widgets', 'extensions' ),
		'element-pack' => array( 'core', 'third_party', 'extensions' ),
		'happy'        => array( 'widgets', 'features', 'extensions' ),
	);

	/**
	 * HT Mega sections by group.
	 */
	private const HTMEGA = array(
		'widgets'    => 'htmega_element_tabs',
		'extensions' => 'htmega_advance_element_tabs',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode addon-modules | addon-module-set.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'addon-modules' === $this->mode ? __( 'Elementor add-on switches', 'kuduy-mcp' ) : __( 'Switch Elementor add-on widgets on or off', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$groups = 'HT Mega (addon "htmega": widgets, extensions), Element Pack (addon "element-pack": core, third_party, extensions) and Happy Addons (addon "happy": widgets, features, extensions)';
		return 'addon-modules' === $this->mode
			? 'Lists the widgets, extensions and features an Elementor add-on can switch on or off, with their current state: ' . $groups . '. Each item: name, title, group, enabled, and whether it is locked (Pro only) or always on. Switched-off widgets disappear from the Elementor panel and stop loading their files.'
			: 'Switches widgets, extensions or features of an Elementor add-on on or off (modules: {"name": true|false}) through the add-on\'s own save path: ' . $groups . '. Pro-locked items cannot be switched on and always-on features cannot be switched off. A widget switched off no longer renders where it is used (check with kuduy-elementor-usage first). Needs the admin access level; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = array(
			'addon' => array(
				'type' => 'string',
				'enum' => array_keys( self::GROUPS ),
			),
			'group' => array(
				'type' => 'string',
				'enum' => array( 'widgets', 'extensions', 'features', 'core', 'third_party' ),
			),
		);
		if ( 'addon-module-set' === $this->mode ) {
			$props['modules'] = array(
				'type'                 => 'object',
				'minProperties'        => 1,
				'maxProperties'        => 200,
				'additionalProperties' => array( 'type' => 'boolean' ),
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'addon-module-set' === $this->mode ? array( 'addon', 'group', 'modules' ) : array( 'addon' ),
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
				'addon'        => array( 'type' => 'string' ),
				'modules'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'addon-modules' === $this->mode ) {
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
		return 'addon-modules' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
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
		return self::active( 'htmega' ) || self::active( 'element-pack' ) || self::active( 'happy' ) ? true : 'None of HT Mega, Element Pack or Happy Addons is active on this site.';
	}

	/**
	 * Whether an add-on and the API used here are present.
	 *
	 * @param string $addon Add-on key.
	 */
	private static function active( string $addon ): bool {
		return match ( $addon ) {
			'htmega'       => class_exists( '\HTMegaOpt\Admin\Options_Field' ),
			'element-pack' => class_exists( '\ElementPack\API\Controllers\Modules_Controller' ),
			default        => class_exists( '\Happy_Addons\Elementor\Classes\Widgets_Manager' ) && class_exists( '\Happy_Addons\Elementor\Classes\Extensions_Manager' ),
		};
	}

	/**
	 * Check the add-on and group.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: list<string>}
	 * @throws ToolError When the add-on is not active or the group does not belong to it.
	 */
	private static function target( array $input ): array {
		$addon = (string) $input['addon'];
		if ( ! self::active( $addon ) ) {
			throw ToolError::of(
				ToolError::PLUGIN_NOT_ACTIVE,
				match ( $addon ) {
				'htmega'       => 'HT Mega',
				'element-pack' => 'Element Pack',
				default        => 'Happy Addons',
				} . ' is not active on this site (or its version has no settings API this tool knows).'
			);
		}
		if ( isset( $input['group'] ) && ! in_array( $input['group'], self::GROUPS[ $addon ], true ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Group "' . (string) $input['group'] . '" does not exist for ' . $addon . '; its groups: ' . implode( ', ', self::GROUPS[ $addon ] ) . '.' );
		}
		return array( $addon, isset( $input['group'] ) ? array( (string) $input['group'] ) : self::GROUPS[ $addon ] );
	}

	/**
	 * Items of one group: name => [title, enabled, locked, always_on].
	 *
	 * @param string $addon Add-on key.
	 * @param string $group Group.
	 * @return array<string, array{title: string, enabled: bool, locked: bool, always_on: bool}>
	 * @throws ToolError When the add-on's API answers with an error.
	 */
	private static function items( string $addon, string $group ): array {
		$out = array();
		if ( 'htmega' === $addon ) {
			$fields = (array) ( \HTMegaOpt\Admin\Options_Field::instance()->get_registered_settings()[ self::HTMEGA[ $group ] ] ?? array() );
			$stored = get_option( self::HTMEGA[ $group ], array() );
			$stored = is_array( $stored ) ? $stored : array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || 'element' !== ( $field['type'] ?? '' ) || '' === (string) ( $field['id'] ?? '' ) ) {
					continue;
				}
				$id         = (string) $field['id'];
				$out[ $id ] = array(
					'title'     => wp_strip_all_tags( (string) ( $field['name'] ?? $id ) ),
					'enabled'   => 'on' === ( $stored[ $id ] ?? ( $field['default'] ?? 'off' ) ),
					'locked'    => ! empty( $field['is_pro'] ),
					'always_on' => false,
				);
			}
			return $out;
		}
		if ( 'element-pack' === $addon ) {
			$response = rest_do_request( new \WP_REST_Request( 'GET', '/element-pack/v1/modules' ) );
			if ( $response->is_error() ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'Element Pack refused: ' . wp_strip_all_tags( (string) $response->as_error()->get_error_message() ) );
			}
			$data = (array) $response->get_data();
			$data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
			foreach ( (array) ( $data['tiers'][ $group ]['modules'] ?? array() ) as $module ) {
				$module                          = (array) $module;
				$out[ (string) $module['name'] ] = array(
					'title'     => wp_strip_all_tags( (string) ( $module['label'] ?? $module['name'] ) ),
					'enabled'   => (bool) ( $module['enabled'] ?? false ),
					'locked'    => (bool) ( $module['is_locked'] ?? false ),
					'always_on' => false,
				);
			}
			return $out;
		}
		$pro                         = function_exists( 'ha_has_pro' ) && ha_has_pro();
		[ $map, $inactive, $always ] = match ( $group ) {
			'widgets'  => array( \Happy_Addons\Elementor\Classes\Widgets_Manager::get_widgets_map(), \Happy_Addons\Elementor\Classes\Widgets_Manager::get_inactive_widgets(), array() ),
			'features' => array( \Happy_Addons\Elementor\Classes\Extensions_Manager::get_features_map(), \Happy_Addons\Elementor\Classes\Extensions_Manager::get_inactive_features(), \Happy_Addons\Elementor\Classes\Extensions_Manager::get_always_on_features() ),
			default    => array( \Happy_Addons\Elementor\Classes\Extensions_Manager::get_extensions_map(), \Happy_Addons\Elementor\Classes\Extensions_Manager::get_inactive_extensions(), array() ),
		};
		$inactive = is_array( $inactive ) ? array_map( 'strval', $inactive ) : array();
		unset( $map[ \Happy_Addons\Elementor\Classes\Widgets_Manager::get_base_widget_key() ] );
		foreach ( (array) $map as $key => $entry ) {
			$entry                = (array) $entry;
			$out[ (string) $key ] = array(
				'title'     => wp_strip_all_tags( (string) ( $entry['title'] ?? $key ) ),
				'enabled'   => ! in_array( (string) $key, $inactive, true ),
				'locked'    => ! empty( $entry['is_pro'] ) && ! $pro,
				'always_on' => in_array( (string) $key, $always, true ),
			);
		}
		return $out;
	}

	/**
	 * Save one group's switches through the add-on's own path.
	 *
	 * @param string              $addon   Add-on key.
	 * @param string              $group   Group.
	 * @param array<string, bool> $enabled Every item of the group => on.
	 * @throws ToolError When the add-on refuses.
	 */
	private static function save( string $addon, string $group, array $enabled ): void {
		if ( 'htmega' === $addon ) {
			$section  = self::HTMEGA[ $group ];
			$stored   = get_option( $section, array() );
			$settings = is_array( $stored ) ? $stored : array();
			foreach ( $enabled as $id => $on ) {
				$settings[ $id ] = $on ? 'on' : 'off';
			}
			$settings['verifynonce'] = wp_create_nonce( 'htmegaopt_verifynonce' );
			$request                 = new \WP_REST_Request( 'POST', '/htmegaopt/v1/settings' );
			$request->set_body_params(
				array(
					'section'  => $section,
					'settings' => $settings,
				)
			);
			$response = rest_do_request( $request );
			if ( $response->is_error() || 200 !== $response->get_status() ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'HT Mega did not save its settings' . ( $response->is_error() ? ': ' . wp_strip_all_tags( (string) $response->as_error()->get_error_message() ) : ' (status ' . $response->get_status() . ').' ) );
			}
			return;
		}//end if
		if ( 'element-pack' === $addon ) {
			$request = new \WP_REST_Request( 'POST', '/element-pack/v1/modules' );
			$request->set_body_params(
				array(
					'tier'    => $group,
					'modules' => array_map( static fn( bool $on ): string => $on ? 'on' : 'off', $enabled ),
				)
			);
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'Element Pack refused: ' . wp_strip_all_tags( (string) $response->as_error()->get_error_message() ) );
			}
			return;
		}
		$inactive = array_map( 'strval', array_keys( array_filter( $enabled, static fn( bool $on ): bool => ! $on ) ) );
		match ( $group ) {
			'widgets'  => \Happy_Addons\Elementor\Classes\Widgets_Manager::save_inactive_widgets( $inactive ),
			'features' => \Happy_Addons\Elementor\Classes\Extensions_Manager::save_inactive_features( $inactive ),
			default    => \Happy_Addons\Elementor\Classes\Extensions_Manager::save_inactive_extensions( $inactive ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'addon-module-set' !== $this->mode || ! isset( $input['addon'], $input['group'] ) || ! self::active( (string) $input['addon'] ) || ! in_array( $input['group'], self::GROUPS[ (string) $input['addon'] ] ?? array(), true ) ) {
			return null;
		}
		$state = array();
		foreach ( self::items( (string) $input['addon'], (string) $input['group'] ) as $name => $item ) {
			$state[ $name ] = $item['enabled'];
		}
		return array(
			'object_type' => 'addon_modules',
			'object_id'   => (string) $input['addon'] . ':' . (string) $input['group'],
			'payload'     => array(
				'addon'   => (string) $input['addon'],
				'group'   => (string) $input['group'],
				'enabled' => $state,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the add-on is gone or refuses.
	 */
	public function restore( array $payload ): void {
		$addon = (string) ( $payload['addon'] ?? '' );
		if ( ! self::active( $addon ) ) {
			throw new ToolError( 'The add-on of this snapshot is no longer active.' );
		}
		self::save( $addon, (string) $payload['group'], array_map( 'boolval', (array) $payload['enabled'] ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the add-on, group or a module is unknown, a switch is not allowed, or the add-on refuses.
	 */
	public function execute( array $input ): array {
		[ $addon, $groups ] = self::target( $input );
		if ( 'addon-modules' === $this->mode ) {
			$rows = array();
			foreach ( $groups as $group ) {
				foreach ( self::items( $addon, $group ) as $name => $item ) {
					$rows[] = array( 'name' => $name ) + $item + array( 'group' => $group );
				}
			}
			return array(
				'addon'   => $addon,
				'modules' => $rows,
			);
		}
		$group   = $groups[0];
		$items   = self::items( $addon, $group );
		$enabled = array();
		foreach ( $items as $name => $item ) {
			$enabled[ $name ] = $item['enabled'];
		}
		$changed = array();
		foreach ( (array) $input['modules'] as $name => $on ) {
			$name = (string) $name;
			if ( ! isset( $items[ $name ] ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, '"' . $name . '" is not a ' . $group . ' item of ' . $addon . ' (see kuduy-elementor-addon-modules). Nothing was changed.' );
			}
			if ( $on && $items[ $name ]['locked'] ) {
				throw ToolError::of( ToolError::LICENSE_REQUIRED, '"' . $name . '" needs the Pro version of the add-on. Nothing was changed.' );
			}
			if ( ! $on && $items[ $name ]['always_on'] ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, '"' . $name . '" is always on in this add-on. Nothing was changed.' );
			}
			if ( (bool) $on !== $enabled[ $name ] ) {
				$changed[] = $name;
			}
			$enabled[ $name ] = (bool) $on;
		}
		AuditLog::target( 'addon_modules', $addon . ':' . $group );
		if ( $changed ) {
			self::save( $addon, $group, $enabled );
		}
		$after = self::items( $addon, $group );
		foreach ( $changed as $name ) {
			// HT Mega's route answers 200 with no body when its own checks stop the save; read back to be sure.
			if ( ( $after[ $name ]['enabled'] ?? null ) !== $enabled[ $name ] ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, $addon . ' did not store the change of "' . $name . '". Check its settings screen.' );
			}
		}
		$rows = array();
		foreach ( $after as $name => $item ) {
			if ( array_key_exists( $name, (array) $input['modules'] ) ) {
				$rows[] = array( 'name' => $name ) + $item + array( 'group' => $group );
			}
		}
		return array(
			'addon'   => $addon,
			'modules' => $rows,
			'changed' => $changed,
		);
	}
}
