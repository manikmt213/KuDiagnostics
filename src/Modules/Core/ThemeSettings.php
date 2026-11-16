<?php
/**
 * Tools: kuduy-theme-facts, kuduy-theme-settings, kuduy-theme-settings-update (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Theme settings through the WordPress Customizer API (upgrade plan phase D, D38). Classic themes such as Kadence,
 * GeneratePress and OceanWP store their settings differently (theme mods, one option array, several options), but
 * register them with WP_Customize_Manager on "customize_register", each with its own sanitize and validate
 * callbacks. (Astra builds its settings only inside the Customizer and has its own abilities: AstraAbilities.
 * Blocksy registers its options only when the manager exists at theme load; its registration file is included here.) So one adapter serves them: a manager is created for the request, the theme registers its
 * settings, values are read with WP_Customize_Setting::value() and written the way the Customizer publishes them:
 * set_post_value(), validate_setting_values(), then "customize_save", every setting's own save() (its sanitizer and
 * storage) and "customize_save_after", the hooks themes use to rebuild their CSS. No changeset post is created.
 * Only settings the theme (or a plugin) registered can be written; menus, widgets and custom CSS have their own tools.
 */
final class ThemeSettings implements Tool, Snapshottable {

	/**
	 * Setting id prefixes left to other tools (menus, widgets, custom CSS) or to core's own screens.
	 */
	private const SKIP = array( 'nav_menu', 'widget_', 'sidebars_widgets', 'custom_css', 'active_theme', 'old_sidebars_widgets_data' );

	private const MAX_SETTINGS = 50;

	/**
	 * The manager of this request. One per PHP process: themes include class files while registering.
	 *
	 * @var \WP_Customize_Manager|null
	 */
	private static ?\WP_Customize_Manager $manager = null;

	/**
	 * Mode.
	 *
	 * @param string $mode facts | settings | settings-update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-theme-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'facts'    => __( 'Theme facts', 'kuduy-mcp' ),
			'settings' => __( 'Theme settings', 'kuduy-mcp' ),
			default    => __( 'Change theme settings', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'facts'    => 'Facts about the active theme in one place: name, version, parent and child theme, block or classic theme, theme.json, supported features, menu locations, widget areas, template parts, and how its design is managed (Customizer settings with kuduy-theme-settings, or Global Styles and the Site Editor tools for block themes) with the Customizer panels and sections the theme registers.',
			'settings' => 'Lists the settings the active theme (and plugins) register in the Customizer — colours, typography, layout, header, footer, … for themes such as Kadence, GeneratePress and OceanWP: id, label, panel and section, control type, choices, default and current value. Filter with search (id, label, section) or section; page and per_page (max 100). Menus, widgets and custom CSS are not listed here.',
			default    => 'Changes 1–50 theme settings by id (from kuduy-theme-settings), the way the Customizer publishes them: every value goes through the theme\'s own sanitizer and validator, and the theme rebuilds its CSS. If any value is refused, nothing is saved. Changes the live site design at once. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = match ( $this->mode ) {
			'settings'        => array(
				'search'         => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'section'        => array(
					'type'        => 'string',
					'description' => 'Section id from kuduy-theme-facts.',
				),
				'include_values' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			) + Content::paging_input(),
			'settings-update' => array(
				'settings' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'maxProperties' => self::MAX_SETTINGS,
					'description'   => 'Setting id => new value, e.g. {"generate_settings[container_width]": 1140}.',
				),
			),
			default           => array(),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'settings-update' === $this->mode ? array( 'settings' ) : array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
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
		return 'settings-update' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'facts' === $this->mode ? 'edit_posts' : 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return 'facts' !== $this->mode && defined( 'ASTRA_THEME_VERSION' )
			? 'Astra builds its settings only inside the Customizer screen; they are read and changed with the abilities Astra provides (kuduy-astra-abilities).'
			: true;
	}

	/**
	 * A Customizer manager for this request with everything registered, as the Customizer screen builds it.
	 *
	 * @throws ToolError When the theme's registration fails outside the Customizer screen.
	 */
	private static function manager(): \WP_Customize_Manager {
		if ( null !== self::$manager ) {
			return self::$manager;
		}
		require_once ABSPATH . 'wp-includes/class-wp-customize-manager.php';
		// Menus and widgets have their own tools; without them the manager is far lighter.
		$none    = static fn(): array => array();
		add_filter( 'customize_loaded_components', $none, 999 );
		$manager = new \WP_Customize_Manager(
			array(
				'changeset_uuid'     => wp_generate_uuid4(),
				'settings_previewed' => false,
				'branching'          => false,
			)
		);
		remove_filter( 'customize_loaded_components', $none, 999 );
		// Themes and core read the manager from this global (core sets it the same way on Customizer requests).
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's own global for the Customizer manager.
		$GLOBALS['wp_customize'] = $manager;
		self::blocksy_before_register();
		// On the Customizer screen core's manager exists before themes load, so core's own settings (site title,
		// logo, …) are registered before any theme callback; themes rely on that. Register them first here too.
		remove_action( 'customize_register', array( $manager, 'register_controls' ) );
		$manager->register_controls();
		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		try {
			do_action( 'customize_register', $manager ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook.
		} catch ( \Throwable $e ) {
			// Some themes prepare their Customizer code only on the Customizer screen itself and fail here.
			throw new ToolError( 'The theme "' . esc_html( (string) get_stylesheet() ) . '" could not register its Customizer settings outside the Customizer screen, so they cannot be managed here.' . ( defined( 'ASTRA_THEME_VERSION' ) ? ' Astra has its own way: see kuduy-astra-abilities.' : '' ) );
		}
		self::$manager = $manager;
		return $manager;
	}

	/**
	 * Blocksy (tested with 2.1.61) includes its Customizer registration only when the manager already exists while
	 * the theme loads (inc/init.php: "if ( isset( $wp_customize ) ) require .../inc/customizer/init.php"). Here the
	 * manager is created later, so the same file is included now; it hooks "customize_register" and registers every
	 * Blocksy option as a theme mod setting with Blocksy's own sanitizer.
	 */
	private static function blocksy_before_register(): void {
		if ( ! function_exists( 'blocksy_get_options' ) || function_exists( 'blocksy_customizer_register_options' ) ) {
			return;
		}
		$file = get_template_directory() . '/inc/customizer/init.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Whether a setting is one these tools manage.
	 *
	 * @param \WP_Customize_Setting $setting Setting.
	 */
	private static function managed( \WP_Customize_Setting $setting ): bool {
		if ( ! in_array( $setting->type, array( 'theme_mod', 'option' ), true ) ) {
			return false;
		}
		foreach ( self::SKIP as $prefix ) {
			if ( str_starts_with( (string) $setting->id, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Managed settings by id.
	 *
	 * @return array<string, \WP_Customize_Setting>
	 */
	private static function managed_settings(): array {
		$out = array();
		foreach ( self::manager()->settings() as $id => $setting ) {
			if ( $setting instanceof \WP_Customize_Setting && self::managed( $setting ) ) {
				$out[ (string) $id ] = $setting;
			}
		}
		return $out;
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
		$settings = self::managed_settings();
		$roots    = array();
		foreach ( array_keys( (array) ( $input['settings'] ?? array() ) ) as $id ) {
			$setting = $settings[ (string) $id ] ?? null;
			if ( null === $setting ) {
				continue;
			}
			$base                                  = (string) $setting->id_data()['base'];
			$none                                  = new \stdClass();
			$now                                   = 'option' === $setting->type ? get_option( $base, $none ) : get_theme_mod( $base, $none );
			$roots[ $setting->type . ':' . $base ] = array(
				'type'    => $setting->type,
				'base'    => $base,
				'existed' => $now !== $none,
				'value'   => $now !== $none ? $now : null,
			);
		}
		return array(
			'object_type' => 'theme_settings',
			'object_id'   => (string) get_stylesheet(),
			'payload'     => array(
				'stylesheet' => (string) get_stylesheet(),
				'roots'      => array_values( $roots ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When another theme is active now.
	 */
	public function restore( array $payload ): void {
		if ( (string) ( $payload['stylesheet'] ?? '' ) !== get_stylesheet() ) {
			throw new ToolError( 'These settings belong to the theme "' . esc_html( (string) ( $payload['stylesheet'] ?? '' ) ) . '", which is not the active theme now. Nothing was restored.' );
		}
		$manager = self::manager();
		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save', $manager ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook.
		foreach ( (array) ( $payload['roots'] ?? array() ) as $root ) {
			$base = (string) ( $root['base'] ?? '' );
			if ( '' === $base ) {
				continue;
			}
			if ( 'option' === ( $root['type'] ?? '' ) ) {
				if ( empty( $root['existed'] ) ) {
					delete_option( $base );
				} else {
					update_option( $base, $root['value'] );
				}
			} elseif ( empty( $root['existed'] ) ) {
				remove_theme_mod( $base );
			} else {
				set_theme_mod( $base, $root['value'] );
			}
		}
		// Themes rebuild their generated CSS on this hook, as after a Customizer publish.
		self::saved( $manager );
	}

	/**
	 * After saving: "customize_save_after", the hook themes rebuild their generated CSS on. Blocksy reads theme mods
	 * through its own Database class, which keeps them for the whole request outside the admin, the Customizer and
	 * admin-ajax (Blocksy 2.1.62 inc/classes/database.php) - an MCP call is a REST request, so its CSS rebuild saw the
	 * old values (seen on a live site). Its public wipe_cache() is called first.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 */
	private static function saved( \WP_Customize_Manager $manager ): void {
		if ( function_exists( 'blocksy_manager' ) ) {
			$blocksy = call_user_func( 'blocksy_manager' );
			if ( is_object( $blocksy ) && isset( $blocksy->db ) && is_object( $blocksy->db ) && method_exists( $blocksy->db, 'wipe_cache' ) ) {
				$blocksy->db->wipe_cache();
			}
		}
		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save_after', $manager ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a setting is unknown, a value is refused or the theme cannot register its settings here.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'facts'    => self::facts(),
			'settings' => self::settings( $input ),
			default    => self::update( (array) $input['settings'] ),
		};
	}

	/**
	 * Facts about the active theme.
	 *
	 * @return array<string, mixed>
	 */
	private static function facts(): array {
		$theme    = wp_get_theme();
		$parent   = $theme->parent();
		$block    = wp_is_block_theme();
		$supports = array();
		foreach ( array( 'title-tag', 'post-thumbnails', 'custom-logo', 'custom-header', 'custom-background', 'menus', 'widgets', 'html5', 'editor-styles', 'wp-block-styles', 'align-wide', 'responsive-embeds', 'block-templates', 'block-template-parts', 'woocommerce' ) as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supports[] = $feature;
			}
		}
		$out = array(
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'stylesheet'     => (string) $theme->get_stylesheet(),
			'is_child'       => (bool) $parent,
			'parent'         => $parent ? array(
				'name'       => (string) $parent->get( 'Name' ),
				'version'    => (string) $parent->get( 'Version' ),
				'stylesheet' => (string) $parent->get_stylesheet(),
			) : null,
			'block_theme'    => $block,
			'theme_json'     => wp_theme_has_theme_json(),
			'supports'       => $supports,
			'menu_locations' => (object) get_registered_nav_menus(),
			'widget_areas'   => count( (array) ( $GLOBALS['wp_registered_sidebars'] ?? array() ) ),
			'template_parts' => $block ? count( get_block_templates( array(), 'wp_template_part' ) ) : 0,
			'managed_with'   => $block ? 'Global Styles and the Site Editor: kuduy-global-styles-get, kuduy-site-templates' : ( defined( 'ASTRA_THEME_VERSION' ) ? 'The abilities Astra provides: kuduy-astra-abilities' : 'Customizer settings: kuduy-theme-settings' ),
		);
		if ( current_user_can( 'edit_theme_options' ) && ! defined( 'ASTRA_THEME_VERSION' ) ) {
			$manager  = self::manager();
			$settings = self::managed_settings();
			$sections = array();
			foreach ( self::controls() as $id => $control ) {
				if ( isset( $settings[ $id ] ) ) {
					$sections[ $control['section'] ] = ( $sections[ $control['section'] ] ?? 0 ) + 1;
				}
			}
			$rows = array();
			foreach ( $sections as $id => $count ) {
				$section = $manager->get_section( (string) $id );
				$panel   = $section && '' !== (string) $section->panel ? $manager->get_panel( $section->panel ) : null;
				$rows[]  = array(
					'section'  => (string) $id,
					'title'    => $section ? wp_strip_all_tags( (string) $section->title ) : '',
					'panel'    => $panel ? wp_strip_all_tags( (string) $panel->title ) : '',
					'settings' => (int) $count,
				);
			}
			$out['customizer'] = array(
				'settings' => count( $settings ),
				'sections' => $rows,
			);
		}//end if
		return $out;
	}

	/**
	 * What edits each setting: setting id => label, section, control type and choices.
	 *
	 * @return array<string, array{label: string, section: string, type: string, choices: list<string>}>
	 */
	private static function controls(): array {
		$out = array();
		foreach ( self::manager()->controls() as $control ) {
			foreach ( (array) $control->settings as $setting ) {
				if ( $setting instanceof \WP_Customize_Setting && ! isset( $out[ (string) $setting->id ] ) ) {
					$out[ (string) $setting->id ] = array(
						'label'   => wp_strip_all_tags( (string) $control->label ),
						'section' => (string) $control->section,
						'type'    => (string) $control->type,
						'choices' => $control->choices ? array_map( 'strval', array_keys( $control->choices ) ) : array(),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Registered settings with their control.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function settings( array $input ): array {
		$manager  = self::manager();
		$controls = self::controls();
		$search   = strtolower( (string) ( $input['search'] ?? '' ) );
		$only     = (string) ( $input['section'] ?? '' );
		$values   = ! ( false === ( $input['include_values'] ?? true ) );
		$rows     = array();
		foreach ( self::managed_settings() as $id => $setting ) {
			if ( ! $setting->check_capabilities() ) {
				continue;
			}
			$control = $controls[ $id ] ?? null;
			$section = $control ? $manager->get_section( $control['section'] ) : null;
			$panel   = $section && '' !== (string) $section->panel ? $manager->get_panel( $section->panel ) : null;
			$row     = array(
				'id'      => $id,
				'stored'  => $setting->type,
				'label'   => $control ? $control['label'] : '',
				'section' => $control ? $control['section'] : '',
				'title'   => trim( ( $panel ? wp_strip_all_tags( (string) $panel->title ) . ' > ' : '' ) . ( $section ? wp_strip_all_tags( (string) $section->title ) : '' ) ),
				'control' => $control ? $control['type'] : '',
			);
			if ( '' !== $only && $row['section'] !== $only ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $id . ' ' . $row['label'] . ' ' . $row['title'] ), $search ) ) {
				continue;
			}
			if ( $control && $control['choices'] ) {
				$row['choices'] = $control['choices'];
			}
			if ( $values ) {
				$row['default'] = $setting->default;
				$row['value']   = $setting->value();
			}
			$rows[] = $row;
		}//end foreach
		$per  = (int) ( $input['per_page'] ?? 20 );
		$page = (int) ( $input['page'] ?? 1 );
		return array(
			'theme'    => (string) get_stylesheet(),
			'settings' => array_slice( $rows, ( $page - 1 ) * $per, $per ),
			'total'    => count( $rows ),
		);
	}

	/**
	 * Publish new values through the Customizer.
	 *
	 * @param array<string, mixed> $values Setting id => value.
	 * @return array<string, mixed>
	 * @throws ToolError When a setting is unknown or a value is refused.
	 */
	private static function update( array $values ): array {
		AuditLog::target( 'theme_settings', (string) get_stylesheet() );
		if ( ! $values || count( $values ) > self::MAX_SETTINGS ) {
			throw new ToolError( 'Give 1 to ' . (int) self::MAX_SETTINGS . ' settings.' );
		}
		$manager  = self::manager();
		$settings = self::managed_settings();
		$unknown  = array();
		foreach ( array_keys( $values ) as $id ) {
			$setting = $settings[ (string) $id ] ?? null;
			if ( null === $setting || ! $setting->check_capabilities() ) {
				$unknown[] = (string) $id;
			}
		}
		if ( $unknown ) {
			throw new ToolError( 'Not a setting the active theme registers (or not one your account may change): ' . esc_html( implode( ', ', array_slice( $unknown, 0, 10 ) ) ) . '. See kuduy-theme-settings. Nothing was changed.' );
		}
		foreach ( $values as $id => $value ) {
			$manager->set_post_value( (string) $id, $value );
		}
		$refused = array();
		foreach ( $manager->validate_setting_values( $values ) as $id => $validity ) {
			if ( is_wp_error( $validity ) ) {
				$refused[] = $id . ' (' . wp_strip_all_tags( implode( '; ', $validity->get_error_messages() ) ) . ')';
			}
		}
		if ( $refused ) {
			throw new ToolError( 'The theme refused: ' . esc_html( implode( ', ', array_slice( $refused, 0, 10 ) ) ) . '. Nothing was changed.' );
		}
		// Save as the Customizer does when it publishes: the save hooks around every setting's own save().
		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save', $manager ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook.
		foreach ( array_keys( $values ) as $id ) {
			$settings[ (string) $id ]->save();
		}
		self::saved( $manager );
		$now = array();
		foreach ( array_keys( $values ) as $id ) {
			$now[ (string) $id ] = $settings[ (string) $id ]->value();
		}
		return array(
			'theme'   => (string) get_stylesheet(),
			'changed' => array_map( 'strval', array_keys( $values ) ),
			'values'  => $now,
		);
	}
}
