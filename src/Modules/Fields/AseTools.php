<?php
/**
 * Tools: kuduy-ase-settings, kuduy-ase-settings-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Fields;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Admin and Site Enhancements (phase E5, D58; read on ASE 9.3.3). ASE keeps every setting in one option
 * (ASENHA_SLUG_U, "admin_site_enhancements"). Its sanitizer (Settings_Sanitization::sanitize_for_options) takes the
 * whole settings form, turns each module switch from "on" into true, sets every switch missing from the form to false
 * and relies on globals its settings screen sets up, so calling it with a few changes would switch other modules off.
 * This tool therefore changes only the module switches ASE's sanitizer defines (the list below, from 9.3.3), storing
 * true or false exactly as the sanitizer would, and leaves every other value untouched. Module settings (texts,
 * lists, code) stay in ASE's screen. Switches that would cut KuDiagnostics off or weaken the site are refused: the
 * custom login URL (KuDiagnostics' OAuth sign-in runs through wp-login.php), disabling the REST API, head/body/footer
 * code insertion, disabling all updates (including KuDiagnostics' own) and skipping SSL checks for outgoing mail.
 * Snapshot first; undo puts the previous switches back.
 */
final class AseTools implements Tool, Snapshottable {

	/**
	 * Module switches ASE 9.3.3's sanitizer defines.
	 */
	private const MODULES = array( 'admin_menu_organizer_sticky_collapse_menu', 'change_login_url', 'clean_media_filenames', 'contact_form', 'contact_form_disable_antispam', 'contact_form_use_theme_styles', 'content_order', 'custom_admin_footer_text', 'custom_nav_menu_items_new_tab', 'customize_admin_menu', 'disable_admin_email_notification_after_password_change', 'disable_all_updates', 'disable_application_passwords', 'disable_author_archives', 'disable_block_widgets', 'disable_code_unslash', 'disable_comments', 'disable_dashboard_widgets', 'disable_embeds', 'disable_emoji_support', 'disable_feed_generator_tag', 'disable_feeds', 'disable_frontend_dashicons', 'disable_gutenberg', 'disable_gutenberg_frontend_styles', 'disable_head_generator_tag', 'disable_head_rsd_tag', 'disable_head_shortlink_tag', 'disable_head_wlwmanifest_tag', 'disable_jquery_migrate', 'disable_lazy_load', 'disable_media_replacement_cache_busting', 'disable_plugin_theme_editor', 'disable_resource_version_number', 'disable_rest_api', 'disable_site_admin_email_verification_screen', 'disable_smaller_components', 'disable_user_account', 'disable_user_email_notification_after_password_change', 'disable_welcome_panel_in_dashboard', 'disable_xmlrpc', 'display_active_plugins_first', 'display_system_summary', 'enable_avif_upload', 'enable_custom_admin_css', 'enable_custom_body_class', 'enable_custom_frontend_css', 'enable_duplication', 'enable_external_permalinks', 'enable_heartbeat_control', 'enable_last_login_column', 'enable_login_logout_menu', 'enable_media_replacement', 'enable_missed_schedule_posts_auto_publish', 'enable_navigation_menu_duplicator', 'enable_password_protection', 'enable_revisions_control', 'enable_svg_upload', 'enhance_list_tables', 'external_links_new_tab', 'hide_ab_comments_menu', 'hide_ab_customize_menu', 'hide_ab_howdy', 'hide_ab_new_content_menu', 'hide_ab_site_menu', 'hide_ab_updates_menu', 'hide_ab_wp_logo_menu', 'hide_admin_bar', 'hide_admin_bar_always_show_for_admins', 'hide_admin_notices', 'hide_comments_column', 'hide_date_column', 'hide_help_drawer', 'hide_modify_elements', 'hide_post_tags_column', 'image_sizes_panel', 'image_upload_control', 'insert_head_body_footer_code', 'limit_login_attempts', 'login_id_type_restriction', 'maintenance_mode', 'manage_ads_appads_txt', 'manage_robots_txt', 'media_files_visibility_control', 'media_library_infinite_scrolling', 'multiple_user_roles', 'obfuscate_author_slugs', 'obfuscate_email_address', 'obfuscate_email_address_builder_safe_mode', 'password_policy', 'plugins_themes_rollback', 'redirect_404_to_homepage', 'redirect_after_login', 'redirect_after_logout', 'registration_date_column', 'search_engine_visibility_status', 'show_custom_taxonomy_filters', 'show_excerpt_column', 'show_featured_image_column', 'show_file_size_column', 'show_id_column', 'show_id_in_action_row', 'show_last_modified_column', 'site_identity_on_login', 'smtp_bypass_ssl_verification', 'smtp_debug', 'smtp_email_delivery', 'smtp_force_from', 'smtp_force_ipv4', 'various_admin_ui_enhancements', 'view_admin_as_role', 'wider_admin_menu' );

	/**
	 * Switches this tool never changes, with the reason.
	 */
	private const REFUSED = array(
		'change_login_url'             => 'it moves the login page, which KuDiagnostics\' OAuth sign-in uses',
		'disable_rest_api'             => 'it would cut KuDiagnostics (a REST API) off',
		'insert_head_body_footer_code' => 'it inserts code into every page',
		'disable_all_updates'          => 'it stops all updates, KuDiagnostics\' own included',
		'smtp_bypass_ssl_verification' => 'it skips the SSL check of the mail server',
	);

	/**
	 * Setting names that hold secrets (never shown).
	 */
	private const SECRET = '/pass|secret|key|token|auth|license/i';

	/**
	 * Constructor.
	 *
	 * @param string $mode settings | settings-update.
	 */
	public function __construct( private string $mode ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-ase-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'settings' === $this->mode ? 'ASE: modules and settings' : 'ASE: switch modules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'settings' === $this->mode
			? 'Admin and Site Enhancements: every module switch (on/off, with the ones KuDiagnostics will not change and why) and the other stored settings (secrets never shown).'
			: 'Switches Admin and Site Enhancements modules on or off: modules as {"enable_duplication": true, "disable_comments": false}, names from kuduy-ase-settings. Stored as ASE\'s own settings screen stores them; other ASE settings are not touched (set them in ASE). Refused: change_login_url, disable_rest_api, insert_head_body_footer_code, disable_all_updates, smtp_bypass_ssl_verification. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return 'settings' === $this->mode
			? array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			)
			: array(
				'type'                 => 'object',
				'properties'           => array(
					'modules' => array(
						'type'                 => 'object',
						'minProperties'        => 1,
						'maxProperties'        => 50,
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
				),
				'required'             => array( 'modules' ),
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
				'modules'      => array( 'type' => 'array' ),
				'settings'     => array( 'type' => 'object' ),
				'changed'      => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'settings' === $this->mode,
			'destructiveHint' => 'settings' !== $this->mode,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'settings' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
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
		return defined( 'ASENHA_SLUG_U' ) ? true : 'Admin and Site Enhancements is not active on this site.';
	}

	/**
	 * ASE's option name.
	 */
	private static function option(): string {
		return (string) constant( 'ASENHA_SLUG_U' );
	}

	/**
	 * Stored settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$all = get_option( self::option(), array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'settings-update' !== $this->mode ) {
			return null;
		}
		$all = self::stored();
		$old = array();
		foreach ( array_keys( (array) $input['modules'] ) as $key ) {
			$old[ (string) $key ] = array_key_exists( (string) $key, $all ) ? $all[ (string) $key ] : null;
		}
		return array(
			'object_type' => 'option',
			'object_id'   => self::option(),
			'payload'     => array( 'modules' => $old ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$all = self::stored();
		foreach ( (array) $payload['modules'] as $key => $value ) {
			if ( null === $value ) {
				unset( $all[ $key ] );
			} else {
				$all[ $key ] = $value;
			}
		}
		update_option( self::option(), $all );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a module is unknown or refused.
	 */
	public function execute( array $input ): array {
		$all = self::stored();
		if ( 'settings' === $this->mode ) {
			$modules = array();
			foreach ( self::MODULES as $key ) {
				$row = array(
					'module' => $key,
					'on'     => ! empty( $all[ $key ] ),
				);
				if ( isset( self::REFUSED[ $key ] ) ) {
					$row['not_changed_here'] = self::REFUSED[ $key ];
				}
				$modules[] = $row;
			}
			$settings = array();
			foreach ( $all as $key => $value ) {
				if ( in_array( $key, self::MODULES, true ) ) {
					continue;
				}
				$settings[ $key ] = preg_match( self::SECRET, (string) $key ) && '' !== $value && null !== $value ? '[hidden]' : $value;
			}
			return array(
				'modules'  => $modules,
				'settings' => (object) $settings,
			);
		}//end if
		$changes = (array) $input['modules'];
		foreach ( $changes as $key => $on ) {
			if ( ! in_array( $key, self::MODULES, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'ASE has no module switch "' . $key . '" (see kuduy-ase-settings). Nothing was changed.' );
			}
			if ( isset( self::REFUSED[ $key ] ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'Module ' . $key . ' is not changed through KuDiagnostics: ' . self::REFUSED[ $key ] . '. Switch it in ASE itself if that is wanted. Nothing was changed.' );
			}
		}
		AuditLog::target( 'option', self::option() );
		foreach ( $changes as $key => $on ) {
			$all[ $key ] = (bool) $on;
		}
		update_option( self::option(), $all );
		return array( 'changed' => (object) array_map( 'boolval', $changes ) );
	}
}
