<?php
/**
 * Tools: kuduy-generateblocks-status, kuduy-generateblocks-settings-update, kuduy-kadence-blocks-status,
 * kuduy-kadence-blocks-settings-update, kuduy-spectra-status, kuduy-spectra-block-toggle.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * What each block plugin manages itself (phase C2, decision D43), through the plugin's own save path, read from
 * its source (`docs/plans/phase-c2-block-plugins.md`):
 *
 * - GenerateBlocks 2.5.1: settings in the `generateblocks` option, saved by its REST route
 *   `generateblocks/v1/settings` (manage_options; only keys of generateblocks_get_option_defaults(), its sanitizers).
 * - Kadence Blocks 3.9.1: settings registered with register_setting( show_in_rest ), saved through WordPress'
 *   `/wp/v2/settings` like its own settings screen; keys holding API keys or secrets are never returned or changed.
 *   The blocks it hides from the inserter (`kt_blocks_unregistered_blocks`) are reported; its dashboard no longer
 *   offers hiding them, so neither does this tool.
 * - Spectra Legacy 2.20.6: blocks switched on or off in `_uagb_blocks` (slug => slug or "disabled") through
 *   UAGB_Admin_Helper::update_admin_settings_option(), as its "Blocks" dashboard does, then its CSS file is rebuilt
 *   when the plugin generates one combined stylesheet.
 *
 * Blocks of all three are placed and edited with the kuduy-block-* tools.
 */
final class BlockPluginTools implements Tool, Snapshottable {

	/**
	 * Kadence settings whose values are secrets or credentials: never returned, never changed here.
	 */
	/**
	 * Spectra Legacy settings its Settings screen saves one option each (admin-core/ajax/common-settings.php, 2.20.6):
	 * option => [allowed values or a kind, default]. Kinds: int (whole number of pixels), padding (int or "default"),
	 * page (a published page id), key (a public reCAPTCHA site key). Secrets, social logins, Instagram accounts, font
	 * lists and global block styles (which also writes files) are not offered.
	 *
	 * @var array<string, array{0: list<string>|string, 1: string}>
	 */
	private const SPECTRA_SETTINGS = array(
		'uag_enable_animations_extension'   => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_enable_dynamic_content'        => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_dynamic_content_mode'          => array( array( 'popup', 'sidebar' ), 'popup' ),
		'uag_enable_block_condition'        => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_enable_block_responsive'       => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_enable_masonry_gallery'        => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_enable_quick_action_sidebar'   => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_enable_templates_button'       => array( array( 'yes', 'no' ), 'yes' ),
		'uag_enable_on_page_css_button'     => array( array( 'yes', 'no' ), 'yes' ),
		'uag_enable_legacy_blocks'          => array( array( 'yes', 'no' ), 'no' ),
		'uag_collapse_panels'               => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_copy_paste'                    => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_auto_block_recovery'           => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_btn_inherit_from_theme'        => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_load_font_awesome_5'           => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_load_select_font_globally'     => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_load_fse_font_globally'        => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_load_gfonts_locally'           => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'uag_preload_local_fonts'           => array( array( 'enabled', 'disabled' ), 'disabled' ),
		'_uagb_allow_file_generation'       => array( array( 'enabled', 'disabled' ), 'enabled' ),
		'uag_content_width'                 => array( 'int', '' ),
		'uag_container_global_padding'      => array( 'padding', 'default' ),
		'uag_container_global_elements_gap' => array( 'int', '20' ),
		'uag_blocks_editor_spacing'         => array( 'int', '0' ),
		'uag_visibility_mode'               => array( array( 'disabled', 'comingsoon', 'maintenance' ), 'disabled' ),
		'uag_visibility_page'               => array( 'page', '' ),
		'uag_recaptcha_site_key_v2'         => array( 'key', '' ),
		'uag_recaptcha_site_key_v3'         => array( 'key', '' ),
	);

	private const KADENCE_SECRET = '/secret|_api|api_key|api_base|_cloud|prophecy|turnstile_site|license/';

	/**
	 * Plugin and mode.
	 *
	 * @param string $plugin generateblocks | kadence-blocks | spectra.
	 * @param string $mode   status | settings-update | block-toggle.
	 */
	public function __construct( private string $plugin, private string $mode ) {}

	/**
	 * Display name.
	 */
	private function label(): string {
		return match ( $this->plugin ) {
			'generateblocks' => 'GenerateBlocks',
			'kadence-blocks' => 'Kadence Blocks',
			default          => 'Spectra Legacy',
		};
	}

	/**
	 * Block name namespace of the plugin.
	 */
	private function prefix(): string {
		return match ( $this->plugin ) {
			'generateblocks' => 'generateblocks/',
			'kadence-blocks' => 'kadence/',
			default          => 'uagb/',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->plugin . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			/* translators: %s: plugin name. */
			'status'          => sprintf( __( '%s status and settings', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: plugin name. */
			'settings-update' => sprintf( __( 'Change %s settings', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: plugin name. */
			default           => sprintf( __( 'Switch %s blocks on or off', 'kuduy-mcp' ), $this->label() ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$b = $this->label();
		return match ( $this->name() ) {
			'kuduy-generateblocks-status'          => 'GenerateBlocks on this site: version, whether GenerateBlocks Pro is active, its settings (container width, responsive preview sync, Google fonts, whether the older version-1 blocks are offered) and its registered blocks. Place and edit its blocks with kuduy-block-schema and kuduy-block-edit.',
			'kuduy-generateblocks-settings-update' => 'Changes GenerateBlocks settings through its own settings route, which checks and cleans every value: container_width (pixels), sync_responsive_previews, disable_google_fonts (true/false; Pro may add more keys, see kuduy-generateblocks-status). Unknown keys are refused. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.',
			'kuduy-kadence-blocks-status'          => 'Kadence Blocks on this site: version, whether Kadence Blocks Pro is active, its registered blocks, the blocks it hides from the inserter, and its settings (block defaults, visibility, colour palette, global settings, …) with type and value. Settings holding API keys or secrets show only whether they are set. Place and edit its blocks with kuduy-block-schema and kuduy-block-edit.',
			'kuduy-kadence-blocks-settings-update' => 'Changes Kadence Blocks settings the way its settings screen saves them (WordPress settings API with Kadence\'s own sanitizers): give settings as key => value with keys from kuduy-kadence-blocks-status. Several are JSON text (e.g. kadence_blocks_config_blocks, kadence_blocks_colors): send the complete JSON string. API keys and secrets are not changed here. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.',
			'kuduy-spectra-status'                 => 'Spectra Legacy (the older Spectra plugin, ultimate-addons-for-gutenberg) on this site: version, whether Spectra Pro is active, every Spectra block with whether it is switched on, its settings (extensions such as animations, dynamic content, display and responsive conditions, masonry gallery, global block styles; editor options; fonts; container width, padding and gap; coming soon / maintenance mode and its page; CSS file generation; public reCAPTCHA site keys) and how many popups exist (kuduy-spectra-popups). Not Spectra Blocks, which is a different plugin.',
			'kuduy-spectra-settings-update'        => 'Changes Spectra Legacy settings the way its Settings screen saves them, one option each: give settings as option => value with option names from kuduy-spectra-status (e.g. {"uag_enable_block_condition":"enabled","uag_content_width":"1200"}). Switches take "enabled"/"disabled" (or "yes"/"no" where the status shows them); uag_visibility_mode takes disabled, comingsoon or maintenance and sends visitors who are not logged in to uag_visibility_page (a published page id; maintenance also answers 503) — set the page first. reCAPTCHA secret keys, social login and Instagram settings, global block styles and font lists are not changed here. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.',
			default                                => 'Switches ' . $b . ' blocks on or off, as its Blocks dashboard does: enable and disable are lists of block names (e.g. "uagb/advanced-heading" or "advanced-heading", see kuduy-spectra-status). A switched-off block disappears from the inserter; blocks already on pages keep their content. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'status' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			);
		}
		if ( 'block-toggle' === $this->mode ) {
			$list = array(
				'type'     => 'array',
				'maxItems' => 100,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 100,
					'pattern'   => '^(uagb/)?[a-z0-9-]+$',
				),
			);
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'enable'  => $list,
					'disable' => $list,
				),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'maxProperties' => 30,
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
				'version'      => array( 'type' => 'string' ),
				'pro'          => array( 'type' => 'boolean' ),
				'settings'     => array( 'type' => 'object' ),
				'blocks'       => array( 'type' => 'array' ),
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
		return 'status' === $this->mode ? Content::read_only() : array(
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
		return 'status' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'status' === $this->mode ? 'edit_posts' : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$on = match ( $this->plugin ) {
			'generateblocks' => defined( 'GENERATEBLOCKS_VERSION' ),
			'kadence-blocks' => defined( 'KADENCE_BLOCKS_VERSION' ),
			default          => defined( 'UAGB_VER' ) && class_exists( '\UAGB_Admin_Helper' ),
		};
		return $on ? true : $this->label() . ' is not active on this site.';
	}

	/**
	 * Options a change touches, for undo.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return list<string>
	 */
	private function options( array $input ): array {
		return match ( $this->plugin ) {
			'generateblocks' => array( 'generateblocks' ),
			'kadence-blocks' => array_map( 'strval', array_keys( (array) ( $input['settings'] ?? array() ) ) ),
			default          => 'settings-update' === $this->mode
				? array_values( array_intersect( array_merge( array_map( 'strval', array_keys( (array) ( $input['settings'] ?? array() ) ) ), array( 'uag_btn_inherit_from_theme_fallback' ) ), array_merge( array_keys( self::SPECTRA_SETTINGS ), array( 'uag_btn_inherit_from_theme_fallback' ) ) ) )
				: array( '_uagb_blocks' ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'status' === $this->mode ) {
			return null;
		}
		$saved = array();
		foreach ( $this->options( $input ) as $option ) {
			$value            = get_option( $option, null );
			$saved[ $option ] = array(
				'exists' => null !== $value,
				'value'  => $value,
			);
		}
		return array(
			'object_type' => 'option',
			'object_id'   => $this->plugin,
			'payload'     => array( 'options' => $saved ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		foreach ( (array) ( $payload['options'] ?? array() ) as $option => $state ) {
			$state = (array) $state;
			if ( empty( $state['exists'] ) ) {
				delete_option( (string) $option );
			} else {
				update_option( (string) $option, $state['value'] );
			}
		}
		if ( 'spectra' === $this->plugin ) {
			self::spectra_rebuild_css();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a setting or block is refused.
	 */
	public function execute( array $input ): array {
		return match ( $this->name() ) {
			'kuduy-generateblocks-status'          => $this->generateblocks_status(),
			'kuduy-generateblocks-settings-update' => $this->generateblocks_update( (array) $input['settings'] ),
			'kuduy-spectra-settings-update'        => $this->spectra_update( (array) $input['settings'] ),
			'kuduy-kadence-blocks-status'          => $this->kadence_status(),
			'kuduy-kadence-blocks-settings-update' => $this->kadence_update( (array) $input['settings'] ),
			'kuduy-spectra-status'                 => $this->spectra_status(),
			default                                => $this->spectra_toggle( (array) ( $input['enable'] ?? array() ), (array) ( $input['disable'] ?? array() ) ),
		};
	}

	/**
	 * Registered blocks of the plugin.
	 *
	 * @return list<array{name: string, title: string}>
	 */
	private function registered(): array {
		$out = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( str_starts_with( (string) $name, $this->prefix() ) ) {
				$out[] = array(
					'name'  => (string) $name,
					'title' => (string) $type->title,
				);
			}
		}
		return $out;
	}

	/**
	 * GenerateBlocks settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function generateblocks_settings(): array {
		$defaults = function_exists( 'generateblocks_get_option_defaults' ) ? (array) generateblocks_get_option_defaults() : array();
		return wp_parse_args( array_intersect_key( (array) get_option( 'generateblocks', array() ), $defaults ), $defaults );
	}

	/**
	 * GenerateBlocks status.
	 *
	 * @return array<string, mixed>
	 */
	private function generateblocks_status(): array {
		return array(
			'version'  => (string) constant( 'GENERATEBLOCKS_VERSION' ),
			'pro'      => defined( 'GENERATEBLOCKS_PRO_VERSION' ),
			'settings' => (object) ( self::generateblocks_settings() + array( 'gb_use_v1_blocks' => (bool) get_option( 'gb_use_v1_blocks', false ) ) ),
			'blocks'   => $this->registered(),
		);
	}

	/**
	 * Change GenerateBlocks settings through its REST route.
	 *
	 * @param array<string, mixed> $settings New values.
	 * @return array<string, mixed>
	 * @throws ToolError When a key is unknown or GenerateBlocks refuses.
	 */
	private function generateblocks_update( array $settings ): array {
		$known   = array_keys( self::generateblocks_settings() );
		$unknown = array_diff( array_keys( $settings ), $known );
		if ( $unknown ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'GenerateBlocks has no setting ' . implode( ', ', $unknown ) . '. Its settings: ' . implode( ', ', $known ) . '. Nothing was changed.' );
		}
		AuditLog::target( 'option', 'generateblocks' );
		CoreRest::call( 'POST', '/generateblocks/v1/settings', array( 'settings' => $settings ) );
		return array(
			'changed'  => array_map( 'strval', array_keys( $settings ) ),
			'settings' => (object) self::generateblocks_settings(),
		);
	}

	/**
	 * Kadence Blocks settings registered for the settings API, keyed by option name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function kadence_registered(): array {
		$out = array();
		foreach ( get_registered_settings() as $name => $args ) {
			if ( str_starts_with( (string) $name, 'kadence_blocks_' ) && ! empty( $args['show_in_rest'] ) ) {
				$out[ (string) $name ] = (array) $args;
			}
		}
		return $out;
	}

	/**
	 * Kadence Blocks status.
	 *
	 * @return array<string, mixed>
	 */
	private function kadence_status(): array {
		$settings = array();
		foreach ( self::kadence_registered() as $name => $args ) {
			$value             = get_option( $name, $args['default'] ?? null );
			$secret            = (bool) preg_match( self::KADENCE_SECRET, $name );
			$settings[ $name ] = array(
				'type'        => (string) ( $args['type'] ?? 'string' ),
				'description' => (string) ( $args['description'] ?? '' ),
			) + ( $secret ? array(
				'write_only' => true,
				'has_value'  => '' !== (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ),
			) : array( 'value' => $value ) );
		}
		$hidden = get_option( 'kt_blocks_unregistered_blocks', array() );
		return array(
			'version'  => (string) constant( 'KADENCE_BLOCKS_VERSION' ),
			'pro'      => defined( 'KBP_VERSION' ),
			'settings' => (object) $settings,
			'blocks'   => $this->registered(),
			'hidden'   => array_values( array_map( 'strval', is_array( $hidden ) ? $hidden : array() ) ),
		);
	}

	/**
	 * Change Kadence Blocks settings through /wp/v2/settings.
	 *
	 * @param array<string, mixed> $settings New values.
	 * @return array<string, mixed>
	 * @throws ToolError When a key is unknown or secret, or WordPress refuses.
	 */
	private function kadence_update( array $settings ): array {
		$registered = self::kadence_registered();
		$params     = array();
		foreach ( $settings as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $registered[ $name ] ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Kadence Blocks has no setting ' . $name . ' (see kuduy-kadence-blocks-status). Nothing was changed.' );
			}
			if ( preg_match( self::KADENCE_SECRET, $name ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, $name . ' holds an API key or secret; enter it in Kadence Blocks\' settings screen. Nothing was changed.' );
			}
			$rest_name            = (string) ( is_array( $registered[ $name ]['show_in_rest'] ) && isset( $registered[ $name ]['show_in_rest']['name'] ) ? $registered[ $name ]['show_in_rest']['name'] : $name );
			$params[ $rest_name ] = $value;
		}
		AuditLog::target( 'option', 'kadence_blocks' );
		CoreRest::call( 'POST', '/wp/v2/settings', $params );
		$out = array();
		foreach ( array_keys( $settings ) as $name ) {
			$out[ (string) $name ] = get_option( (string) $name );
		}
		return array(
			'changed'  => array_keys( $settings ),
			'settings' => (object) $out,
		);
	}

	/**
	 * Spectra Legacy blocks with their switch, from the plugin's own block list.
	 *
	 * @return array<string, array{name: string, title: string, enabled: bool}>
	 */
	private static function spectra_blocks(): array {
		$saved = \UAGB_Admin_Helper::get_admin_settings_option( '_uagb_blocks', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$info  = class_exists( '\UAGB_Block_Module' ) ? (array) \UAGB_Block_Module::get_blocks_info() : array();
		$out   = array();
		foreach ( $info as $name => $data ) {
			$data = (array) $data;
			if ( ! empty( $data['is_child'] ) || ( isset( $data['is_active'] ) && ! $data['is_active'] ) ) {
				continue;
			}
			$slug         = str_replace( 'uagb/', '', (string) $name );
			$out[ $slug ] = array(
				'name'    => (string) $name,
				'title'   => (string) ( $data['title'] ?? $slug ),
				'enabled' => 'disabled' !== ( $saved[ $slug ] ?? $slug ),
			);
		}
		return $out;
	}

	/**
	 * Spectra Legacy status.
	 *
	 * @return array<string, mixed>
	 */
	private function spectra_status(): array {
		$extensions = array();
		foreach ( array( 'uag_enable_animations_extension', 'uag_enable_dynamic_content', 'uag_enable_block_condition', 'uag_enable_block_responsive', 'uag_enable_masonry_gallery', 'uag_enable_gbs_extension' ) as $option ) {
			$extensions[ $option ] = (string) \UAGB_Admin_Helper::get_admin_settings_option( $option, 'enabled' );
		}
		foreach ( self::SPECTRA_SETTINGS as $option => $rule ) {
			$extensions[ $option ] = (string) \UAGB_Admin_Helper::get_admin_settings_option( $option, $rule[1] );
		}
		$popups = wp_count_posts( 'spectra-popup' );
		return array(
			'version'  => (string) constant( 'UAGB_VER' ),
			'pro'      => defined( 'SPECTRA_PRO_VER' ),
			'settings' => (object) ( $extensions + array( 'popups' => post_type_exists( 'spectra-popup' ) ? (int) array_sum( (array) $popups ) : 0 ) ),
			'blocks'   => array_values( self::spectra_blocks() ),
		);
	}

	/**
	 * Switch Spectra Legacy blocks on or off.
	 *
	 * @param list<string> $enable  Names to switch on.
	 * @param list<string> $disable Names to switch off.
	 * @return array<string, mixed>
	 * @throws ToolError When a name is unknown or appears in both lists.
	 */
	private function spectra_toggle( array $enable, array $disable ): array {
		$slug    = static fn( string $name ): string => str_replace( 'uagb/', '', $name );
		$enable  = array_map( $slug, $enable );
		$disable = array_map( $slug, $disable );
		if ( ! $enable && ! $disable ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give enable and/or disable (lists of block names).' );
		}
		$both = array_intersect( $enable, $disable );
		if ( $both ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, implode( ', ', $both ) . ' is in both enable and disable.' );
		}
		$blocks  = self::spectra_blocks();
		$unknown = array_diff( array_merge( $enable, $disable ), array_keys( $blocks ) );
		if ( $unknown ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Spectra Legacy has no switchable block ' . implode( ', ', $unknown ) . ' (see kuduy-spectra-status). Nothing was changed.' );
		}
		$saved = \UAGB_Admin_Helper::get_admin_settings_option( '_uagb_blocks', array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array_keys( $blocks ) as $key ) {
			$saved[ $key ] = $saved[ $key ] ?? $key;
		}
		foreach ( $enable as $key ) {
			$saved[ $key ] = $key;
		}
		foreach ( $disable as $key ) {
			$saved[ $key ] = 'disabled';
		}
		AuditLog::target( 'option', '_uagb_blocks' );
		\UAGB_Admin_Helper::update_admin_settings_option( '_uagb_blocks', array_map( 'sanitize_text_field', $saved ) );
		self::spectra_rebuild_css();
		$now = self::spectra_blocks();
		return array(
			'changed' => array_map( static fn( string $key ): string => 'uagb/' . $key, array_merge( $enable, $disable ) ),
			'blocks'  => array_values( array_filter( $now, static fn( array $b ): bool => in_array( str_replace( 'uagb/', '', $b['name'] ), array_merge( $enable, $disable ), true ) ) ),
		);
	}

	/**
	 * Change Spectra Legacy settings, one option each, as its Settings screen does.
	 *
	 * @param array<string, mixed> $settings Option => value.
	 * @return array<string, mixed>
	 * @throws ToolError When an option or value is refused; nothing is saved then.
	 */
	private function spectra_update( array $settings ): array {
		$clean = array();
		foreach ( $settings as $option => $value ) {
			$option = (string) $option;
			if ( ! isset( self::SPECTRA_SETTINGS[ $option ] ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Spectra Legacy setting ' . $option . ' cannot be changed here (see kuduy-spectra-status for the options). Nothing was changed.' );
			}
			$rule  = self::SPECTRA_SETTINGS[ $option ][0];
			$value = is_bool( $value ) ? ( $value ? 'enabled' : 'disabled' ) : ( is_scalar( $value ) ? trim( (string) $value ) : null );
			$ok    = match ( true ) {
				null === $value                                 => false,
				is_array( $rule )                               => in_array( $value, $rule, true ),
				'int' === $rule                                 => '' === $value || ( ctype_digit( $value ) && (int) $value <= 10000 ),
				'padding' === $rule                             => 'default' === $value || ( ctype_digit( $value ) && (int) $value <= 1000 ),
				'page' === $rule                                => '' === $value || ( ctype_digit( $value ) && 'page' === get_post_type( (int) $value ) && 'publish' === get_post_status( (int) $value ) ),
				default                                         => strlen( $value ) <= 200 && (bool) preg_match( '/^[A-Za-z0-9_-]*$/', $value ),
			};
			if ( ! $ok ) {
				$allowed = is_array( $rule ) ? implode( ', ', $rule ) : match ( $rule ) {
					'int'     => 'a whole number of pixels (or empty)',
					'padding' => '"default" or a whole number of pixels',
					'page'    => 'the id of a published page (or empty)',
					default   => 'a reCAPTCHA site key (letters, digits, - and _)',
				};
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Spectra Legacy setting ' . $option . ' takes ' . $allowed . '. Nothing was changed.' );
			}
			$clean[ $option ] = (string) $value;
		}//end foreach
		$mode = $clean['uag_visibility_mode'] ?? (string) \UAGB_Admin_Helper::get_admin_settings_option( 'uag_visibility_mode', 'disabled' );
		$page = $clean['uag_visibility_page'] ?? (string) \UAGB_Admin_Helper::get_admin_settings_option( 'uag_visibility_page', '' );
		if ( 'disabled' !== $mode && '' === $page ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Coming soon / maintenance mode needs uag_visibility_page (a published page) first. Nothing was changed.' );
		}
		AuditLog::target( 'option', implode( ',', array_keys( $clean ) ) );
		foreach ( $clean as $option => $value ) {
			if ( 'uag_btn_inherit_from_theme' === $option && false !== get_option( 'uag_btn_inherit_from_theme_fallback' ) ) {
				// As Spectra's own handler: the user's choice replaces the fallback kept for older sites.
				\UAGB_Admin_Helper::delete_admin_settings_option( 'uag_btn_inherit_from_theme_fallback' );
			}
			\UAGB_Admin_Helper::update_admin_settings_option( $option, sanitize_text_field( $value ) );
		}
		self::spectra_rebuild_css();
		$out = array();
		foreach ( array_keys( $clean ) as $option ) {
			$out[ $option ] = (string) \UAGB_Admin_Helper::get_admin_settings_option( $option, self::SPECTRA_SETTINGS[ $option ][1] );
		}
		return array(
			'changed'  => array_keys( $clean ),
			'settings' => (object) $out,
		);
	}

	/**
	 * Rebuild Spectra's combined stylesheet when it generates one, as its dashboard does after a change.
	 */
	private static function spectra_rebuild_css(): void {
		if ( class_exists( '\UAGB_Helper' ) && 'disabled' === \UAGB_Helper::$file_generation ) {
			\UAGB_Admin_Helper::create_specific_stylesheet();
		}
	}
}
