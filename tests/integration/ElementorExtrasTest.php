<?php
/**
 * Phase F2 (test matrix EX1–EX6): Elementor usage search, widget list by plugin, add-on extensions, add-on module
 * switches (HT Mega, Element Pack, Happy Addons) and Unlimited Elements widgets, against the real plugins in the
 * add-on CI jobs.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorExtrasTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			self::markTestSkipped( 'Elementor is not active in this run.' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * EX1: usage across pages — summary per type, and the pages of one widget.
	 */
	public function test_usage(): void {
		$heading = array(
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array( 'title' => 'Kuduy usage' ),
		);
		$first   = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'    => 'Kuduy usage one',
				'elements' => array(
					array(
						'elType'   => 'container',
						'elements' => array( $heading, $heading ),
					),
				),
			)
		);
		$second  = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'    => 'Kuduy usage two',
				'elements' => array(
					array(
						'elType'   => 'container',
						'elements' => array( $heading ),
					),
				),
			)
		);
		$summary = array_column( $this->ok( 'kuduy-elementor-usage', array( 'limit' => 1000 ) )['types'], null, 'type' );
		$this->assertGreaterThanOrEqual( 3, $summary['heading']['uses'] );
		$this->assertGreaterThanOrEqual( 2, $summary['heading']['pages'] );
		$this->assertSame( 'elementor', $summary['heading']['folder'] );

		$one   = $this->ok( 'kuduy-elementor-usage', array( 'widget' => 'heading' ) );
		$pages = array_column( $one['pages'], 'uses', 'post_id' );
		$this->assertSame( 2, $pages[ $first['id'] ] ?? null );
		$this->assertSame( 1, $pages[ $second['id'] ] ?? null );
		$this->assertSame( array( 'heading' ), array_column( $one['types'], 'type' ), 'Only the asked type is summarised.' );

		$this->fails( 'kuduy-elementor-usage', array( 'plugin' => 'no-such-addon-kuduy' ), 'unknown plugin' );

		wp_set_current_user( self::make_user( 'contributor' ) );
		$theirs = array_column( $this->ok( 'kuduy-elementor-usage', array( 'widget' => 'heading' ) )['pages'], 'post_id' );
		$this->assertNotContains( $first['id'], $theirs, 'Posts the user cannot edit are not counted.' );
	}

	/**
	 * EX2: the widget list filtered by plugin.
	 */
	public function test_widgets_by_plugin(): void {
		$core = $this->ok( 'kuduy-elementor-widgets', array( 'plugin' => 'elementor' ) )['widgets'];
		$this->assertNotEmpty( $core );
		$this->assertSame( array( 'elementor' ), array_values( array_unique( array_column( $core, 'source' ) ) ) );
		$all = $this->ok( 'kuduy-elementor-widgets' )['widgets'];
		foreach ( array_unique( array_column( $all, 'source' ) ) as $folder ) {
			if ( in_array( $folder, array( 'elementor', 'elementor-pro', 'unknown', 'theme' ), true ) ) {
				continue;
			}
			$expected = array_column( array_filter( $all, static fn( $w ) => $folder === $w['source'] ), 'name' );
			$rows     = $this->ok( 'kuduy-elementor-widgets', array( 'plugin' => $folder ) )['widgets'];
			$this->assertSame( $expected, array_column( $rows, 'name' ), $folder . ': the filter returns exactly that plugin\'s widgets' );
		}
	}

	/**
	 * EX3: extensions — an add-on that hooks into Elementor's elements is listed with what it extends.
	 */
	public function test_extensions(): void {
		$all = $this->ok( 'kuduy-elementor-extensions' )['plugins'];
		foreach ( $all as $row ) {
			$this->assertNotContains( $row['folder'], array( 'elementor', 'elementor-pro', 'kuduy-mcp' ) );
			$this->assertNotEmpty( $row['hooks'] );
		}
		if ( defined( 'HAPPY_ADDONS_VERSION' ) ) {
			$happy = array_column( $all, null, 'folder' )['happy-elementor-addons'] ?? null;
			$this->assertNotNull( $happy, 'Happy Addons extends Elementor elements (effects, wrapper link, …).' );
			$this->assertSame( array( 'happy-elementor-addons' ), array_column( $this->ok( 'kuduy-elementor-extensions', array( 'plugin' => 'Happy' ) )['plugins'], 'folder' ) );
		}
		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-elementor-extensions', array(), 'needs manage_options' );
	}

	/**
	 * EX4: module switches through each add-on's own save path, with undo.
	 */
	public function test_module_switches(): void {
		$cases = array();
		if ( class_exists( '\HTMegaOpt\Admin\Options_Field' ) ) {
			$cases[] = array( 'htmega', 'widgets' );
		}
		if ( class_exists( '\ElementPack\API\Controllers\Modules_Controller' ) ) {
			$cases[] = array( 'element-pack', 'core' );
		}
		if ( class_exists( '\Happy_Addons\Elementor\Classes\Widgets_Manager' ) ) {
			$cases[] = array( 'happy', 'widgets' );
		}
		if ( ! $cases ) {
			$this->markTestSkipped( 'None of HT Mega, Element Pack, Happy Addons is active in this run.' );
		}
		foreach ( $cases as [ $addon, $group ] ) {
			$list = $this->ok(
				'kuduy-elementor-addon-modules',
				array(
					'addon' => $addon,
					'group' => $group,
				)
			)['modules'];
			$this->assertNotEmpty( $list, $addon );
			$pick = null;
			foreach ( $list as $row ) {
				if ( $row['enabled'] && ! $row['locked'] && ! $row['always_on'] ) {
					$pick = $row['name'];
					break;
				}
			}
			$this->assertNotNull( $pick, $addon . ': an enabled switch to test with' );
			$before = array_column( $list, 'enabled', 'name' );

			$off = $this->ok(
				'kuduy-elementor-addon-module-set',
				array(
					'addon'   => $addon,
					'group'   => $group,
					'modules' => array( $pick => false ),
				)
			);
			$this->assertSame( array( $pick ), $off['changed'], $addon );
			$this->assertFalse( $off['modules'][0]['enabled'], $addon );
			$now = array_column(
				$this->ok(
					'kuduy-elementor-addon-modules',
					array(
						'addon' => $addon,
						'group' => $group,
					)
				)['modules'],
				'enabled',
				'name'
			);
			$this->assertFalse( $now[ $pick ], $addon . ': the add-on stored it' );
			unset( $now[ $pick ], $before[ $pick ] );
			$this->assertSame( $before, $now, $addon . ': no other switch changed' );

			$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
			$back = array_column(
				$this->ok(
					'kuduy-elementor-addon-modules',
					array(
						'addon' => $addon,
						'group' => $group,
					)
				)['modules'],
				'enabled',
				'name'
			);
			$this->assertTrue( $back[ $pick ], $addon . ': undo switches it on again' );

			$this->fails(
				'kuduy-elementor-addon-module-set',
				array(
					'addon'   => $addon,
					'group'   => $group,
					'modules' => array( 'no-such-kuduy-module' => true ),
				),
				'unknown module'
			);
		}//end foreach
		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-elementor-addon-modules', array( 'addon' => $cases[0][0] ), 'needs manage_options' );
	}

	/**
	 * EX5: Unlimited Elements widgets read through its own classes, templates not returned.
	 */
	public function test_unlimited_elements(): void {
		if ( ! class_exists( 'UniteCreatorAddons' ) ) {
			$this->markTestSkipped( 'Unlimited Elements is not active in this run.' );
		}
		$addon = new \UniteCreatorAddon();
		$addon->addSmall( 'Kuduy UE widget', 'kuduy_ue_test', 'Made by the test', 0, \GlobalsUC::ADDON_TYPE_ELEMENTOR );
		$list = array_column( $this->ok( 'kuduy-unlimited-elements-widgets', array( 'search' => 'Kuduy UE' ) )['widgets'], null, 'alias' );
		$this->assertArrayHasKey( 'kuduy_ue_test', $list );
		$this->assertSame( 'ucaddon_kuduy_ue_test', $list['kuduy_ue_test']['elementor'] );
		$one = $this->ok( 'kuduy-unlimited-elements-widgets', array( 'name' => 'ucaddon_kuduy_ue_test' ) )['widget'];
		$this->assertSame( 'Kuduy UE widget', $one['title'] );
		$this->assertArrayNotHasKey( 'templates', $one, 'Templates are code and are not returned.' );
		$this->assertStringNotContainsString( 'HTML', (string) wp_json_encode( $one ), 'The template text does not leak.' );
		$this->fails( 'kuduy-unlimited-elements-widgets', array( 'name' => 'no_such_widget' ), 'unknown widget' );
	}

	/**
	 * EX6: the tool list stays valid with every new tool (schemas checked by the call helper).
	 */
	public function test_tools_listed(): void {
		$names = array_map( static fn( $t ) => $t->name(), \Kuduy\Mcp\Mcp\ToolRegistry::create()->all() );
		foreach ( array( 'kuduy-elementor-usage', 'kuduy-elementor-extensions', 'kuduy-elementor-addon-modules', 'kuduy-elementor-addon-module-set', 'kuduy-unlimited-elements-widgets' ) as $tool ) {
			$this->assertContains( $tool, $names );
		}
	}
}
