<?php
/**
 * Theme facts and Customizer settings (upgrade plan phase D, test matrix TH1–TH4) on whichever theme the run has
 * active: the default block theme in the plain runs, Astra, Kadence, Blocksy, GeneratePress or OceanWP with
 * KUDUY_THEME=slug@version.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ThemeSettingsTest extends TestCase {

	use CallsTools;

	/**
	 * One known setting per tested theme: id, a new value, how the theme stores it (option or theme_mod) and the
	 * key inside the stored root ('' when the root is the value itself).
	 *
	 * @var array<string, array{0: string, 1: mixed, 2: string, 3: string}>
	 */
	private const KNOWN = array(
		'generatepress' => array( 'generate_settings[container_width]', 1111, 'option', 'container_width' ),
		'oceanwp'       => array( 'ocean_main_container_width', 1111, 'theme_mod', '' ),
		'kadence'       => array( 'scroll_up', true, 'theme_mod', '' ),
	);

	private static int $admin;

	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Every managed setting of the active theme.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all_settings(): array {
		$out  = array();
		$page = 1;
		do {
			$result = $this->ok(
				'kuduy-theme-settings',
				array(
					'per_page' => 100,
					'page'     => $page,
				)
			);
			foreach ( $result['settings'] as $row ) {
				$out[ $row['id'] ] = $row;
			}
			++$page;
			$more = $result['settings'] && count( $out ) < $result['total'];
		} while ( $more );
		return $out;
	}

	public function test_th1_facts(): void {
		$theme = wp_get_theme();
		$facts = $this->ok( 'kuduy-theme-facts' );
		$this->assertSame( $theme->get( 'Name' ), $facts['name'] );
		$this->assertSame( $theme->get( 'Version' ), $facts['version'] );
		$this->assertSame( get_stylesheet(), $facts['stylesheet'] );
		$this->assertSame( wp_is_block_theme(), $facts['block_theme'] );
		$this->assertFalse( $facts['is_child'] );
		$this->assertIsArray( $facts['supports'] );
		if ( ! defined( 'ASTRA_THEME_VERSION' ) ) {
			$this->assertGreaterThan( 0, $facts['customizer']['settings'] );
		}
		$this->assertStringContainsString( wp_is_block_theme() ? 'kuduy-global-styles-get' : ( defined( 'ASTRA_THEME_VERSION' ) ? 'kuduy-astra-abilities' : 'kuduy-theme-settings' ), $facts['managed_with'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		if ( isset( $integrations[ get_stylesheet() ] ) ) {
			$this->assertTrue( $integrations[ get_stylesheet() ]['active'], 'The registry sees the active theme.' );
		}

		wp_set_current_user( self::$editor );
		$this->assertArrayNotHasKey( 'customizer', $this->ok( 'kuduy-theme-facts' ), 'Customizer details need edit_theme_options.' );
	}

	public function test_th2_core_setting_round_trip_with_undo(): void {
		if ( defined( 'ASTRA_THEME_VERSION' ) ) {
			$this->markTestSkipped( 'Astra is managed with its own abilities (AstraAbilitiesTest).' );
		}
		$settings = $this->all_settings();
		$this->assertArrayHasKey( 'blogname', $settings, 'Core registers the site title in the Customizer.' );
		$this->assertSame( get_option( 'blogname' ), $settings['blogname']['value'] );
		foreach ( array_keys( $settings ) as $id ) {
			$this->assertDoesNotMatchRegularExpression( '/^(nav_menu|widget_|sidebars_widgets|custom_css)/', $id, 'Menus, widgets and custom CSS have their own tools.' );
		}
		$this->assertSame( array( 'blogname' ), array_column( $this->ok( 'kuduy-theme-settings', array( 'search' => 'blogname' ) )['settings'], 'id' ) );

		$before = get_option( 'blogname' );
		$update = $this->ok( 'kuduy-theme-settings-update', array( 'settings' => array( 'blogname' => 'Kuduy Theme Title' ) ) );
		$this->assertSame( array( 'blogname' ), $update['changed'] );
		$this->assertSame( 'Kuduy Theme Title', get_bloginfo( 'name' ), 'The value is stored.' );
		$this->assertSame( 'Kuduy Theme Title', $update['values']['blogname'] );
		$this->assertSame( 0, (int) ( wp_count_posts( 'customize_changeset' )->draft ?? 0 ), 'No draft changeset is left behind.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $update['_snapshot_id'] ) );
		$this->assertSame( $before, get_option( 'blogname' ) );

		$this->fails( 'kuduy-theme-settings-update', array( 'settings' => array( 'no_such_setting_kuduy' => 1 ) ), 'not a registered setting' );
		$this->fails( 'kuduy-theme-settings-update', array( 'settings' => array( 'nav_menu_locations[primary]' => 1 ) ), 'menus have their own tools' );
		$this->fails(
			'kuduy-theme-settings-update',
			array(
				'settings' => array(
					'blogname'              => 'Should not be saved',
					'no_such_setting_kuduy' => 1,
				),
			),
			'one unknown setting saves nothing'
		);
		$this->assertNotSame( 'Should not be saved', get_option( 'blogname' ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-theme-settings', array(), 'an editor may not read theme settings' );
		$this->fails( 'kuduy-theme-settings-update', array( 'settings' => array( 'blogname' => 'Editor title' ) ), 'an editor may not change theme settings' );
		$this->assertNotSame( 'Editor title', get_option( 'blogname' ) );
	}

	/**
	 * What WordPress stores for the root of a setting id ("generate_settings[container_width]" => the whole
	 * generate_settings option), read straight from the database API. Undo is checked against this, not through
	 * the tools: WP_Customize_Setting keeps multidimensional roots in a static cache for the PHP process, and all
	 * tests share one process, while every real tool call is its own request.
	 *
	 * @param string $id     Setting id.
	 * @param string $stored option | theme_mod.
	 * @return mixed
	 */
	private function root( string $id, string $stored ) {
		$base = (string) strtok( $id, '[' );
		return 'option' === $stored ? get_option( $base, null ) : get_theme_mod( $base, null );
	}

	public function test_th3_theme_choice_setting_round_trip_with_undo(): void {
		if ( wp_is_block_theme() || defined( 'ASTRA_THEME_VERSION' ) ) {
			$this->markTestSkipped( 'Block theme or Astra in this run: its design is in Global Styles (KUDUY_THEME selects a classic theme).' );
		}
		// A setting of the theme itself with a list of choices: set it to another choice, read it back, undo.
		$pick = null;
		foreach ( $this->all_settings() as $id => $row ) {
			$choices = $row['choices'] ?? array();
			if ( count( $choices ) < 2 || ! is_scalar( $row['value'] ) || in_array( $id, array( 'show_on_front', 'page_on_front', 'page_for_posts' ), true ) || ! in_array( $row['control'], array( 'select', 'radio' ), true ) ) {
				continue;
			}
			$other = array_values( array_diff( $choices, array( (string) $row['value'] ) ) );
			if ( $other ) {
				$pick = array( $id, $row['stored'], $other[0] );
				break;
			}
		}
		if ( null === $pick ) {
			$this->markTestSkipped( get_stylesheet() . ' registers no plain select or radio setting (its controls are its own types); see TH4.' );
		}
		[ $id, $stored, $new ] = $pick;
		$before                = $this->root( $id, $stored );

		$update = $this->ok( 'kuduy-theme-settings-update', array( 'settings' => array( $id => $new ) ) );
		$this->assertEquals( $new, $update['values'][ $id ], $id );
		$this->assertEquals( $new, $this->ok( 'kuduy-theme-settings', array( 'search' => $id ) )['settings'][0]['value'], 'A later read returns the stored value.' );
		$this->assertNotEquals( $before, $this->root( $id, $stored ), 'The theme\'s storage changed: ' . $id );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $update['_snapshot_id'] ) );
		$this->assertEquals( $before, $this->root( $id, $stored ), 'Undo puts the stored value back exactly: ' . $id );
	}

	public function test_th4_known_setting_lands_where_the_theme_stores_it(): void {
		$known = self::KNOWN[ get_stylesheet() ] ?? null;
		if ( null === $known ) {
			$this->markTestSkipped( 'No known setting recorded for the theme "' . get_stylesheet() . '".' );
		}
		[ $id, $value, $type, $key ] = $known;
		$stored                      = function () use ( $id, $type, $key ) {
			$root = $this->root( $id, $type );
			return '' === $key ? $root : ( is_array( $root ) ? ( $root[ $key ] ?? null ) : null );
		};
		$before                      = $this->root( $id, $type );

		$update = $this->ok( 'kuduy-theme-settings-update', array( 'settings' => array( $id => $value ) ) );
		$this->assertEquals( $value, $stored(), 'The value is in the theme\'s own storage.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $update['_snapshot_id'] ) );
		$this->assertEquals( $before, $this->root( $id, $type ), 'Undo puts the stored value back exactly.' );
	}
}
