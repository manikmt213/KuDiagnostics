<?php
/**
 * Phase R (test matrix TB1–TB3): Themify Builder tools against the real Themify Builder 7.8.5, with the Builder
 * Mosaic stand-in (job "+ Themify Builder, Mosaic (stand-in)").
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ThemifyBuilderTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		if ( ! class_exists( 'ThemifyBuilder_Data_Manager' ) ) {
			$this->markTestSkipped( 'Themify Builder is not active in this run.' );
		}
		wp_set_current_user( self::$admin );
	}

	/**
	 * A page with a row, a column, a text module and a Mosaic module, saved through Themify Builder.
	 */
	private static function page(): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy Themify page',
				'post_status' => 'publish',
			)
		);
		\ThemifyBuilder_Data_Manager::save_data(
			array(
				array(
					'element_id' => 'kr1',
					'cols'       => array(
						array(
							'element_id' => 'kc1',
							'grid_class' => 'col-full',
							'modules'    => array(
								array(
									'element_id'   => 'km1',
									'mod_name'     => 'text',
									'mod_settings' => array(
										'content_text' => '<p>Hello Kuduy</p>',
										'font_color'   => '#000000',
									),
								),
								array(
									'element_id'   => 'km2',
									'mod_name'     => 'mosaic',
									'mod_settings' => array(
										'mod_title' => 'Latest',
										'tiled_posts_display' => 'posts',
									),
								),
							),
						),
					),
				),
			),
			$id,
			'backend'
		);
		return $id;
	}

	/**
	 * TB1: the layout comes back as a tree, and one element with all its settings.
	 */
	public function test_read(): void {
		$id   = self::page();
		$tree = $this->ok( 'kuduy-themify-builder-get', array( 'post_id' => $id ) );
		$row  = $tree['rows'][0];
		$this->assertSame( 'row', $row['kind'] );
		$col = $row['children'][0];
		$this->assertSame( 'column', $col['kind'] );
		$this->assertSame( array( 'text', 'mosaic' ), array_column( $col['children'], 'mod_name' ) );
		$this->assertSame( 'Hello Kuduy', $col['children'][0]['preview'] );
		$this->assertSame( 'posts', $col['children'][1]['source'] );

		$one = $this->ok(
			'kuduy-themify-builder-get',
			array(
				'post_id'    => $id,
				'element_id' => 'km1',
			)
		);
		$this->assertSame( '#000000', $one['element']['mod_settings']['font_color'] );
	}

	/**
	 * TB2: one module's settings change through Themify Builder's save, a key is removed, and undo restores all.
	 */
	public function test_module_update_and_undo(): void {
		$id      = self::page();
		$changed = $this->ok(
			'kuduy-themify-builder-module-update',
			array(
				'post_id'    => $id,
				'element_id' => 'km1',
				'settings'   => array(
					'content_text' => '<p>Changed</p>',
					'font_color'   => null,
				),
			)
		);
		$this->assertSame( '<p>Changed</p>', $changed['element']['mod_settings']['content_text'] );
		$this->assertArrayNotHasKey( 'font_color', $changed['element']['mod_settings'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$back = $this->ok(
			'kuduy-themify-builder-get',
			array(
				'post_id'    => $id,
				'element_id' => 'km1',
			)
		);
		$this->assertSame( '<p>Hello Kuduy</p>', $back['element']['mod_settings']['content_text'] );
		$this->assertSame( '#000000', $back['element']['mod_settings']['font_color'] );

		$this->fails(
			'kuduy-themify-builder-module-update',
			array(
				'post_id'    => $id,
				'element_id' => 'kc1',
				'settings'   => array( 'x' => 1 ),
			),
			'a column is not a module'
		);
		$this->fails(
			'kuduy-themify-builder-module-update',
			array(
				'post_id'    => $id,
				'element_id' => 'nope',
				'settings'   => array( 'x' => 1 ),
			),
			'unknown element'
		);
	}

	/**
	 * TB3: Mosaic's sources are listed and a Mosaic module only takes a source Mosaic has on the site.
	 */
	public function test_mosaic(): void {
		if ( ! class_exists( 'Builder_Mosaic' ) ) {
			$this->markTestSkipped( 'Builder Mosaic (stand-in) is not loaded in this run.' );
		}
		$sources = $this->ok( 'kuduy-mosaic-sources' );
		$this->assertContains( 'posts', $sources['sources'] );
		$this->assertNotContains( 'wc', $sources['sources'], 'WooCommerce is not active, so Mosaic offers no products.' );

		$id = self::page();
		$this->fails(
			'kuduy-themify-builder-module-update',
			array(
				'post_id'    => $id,
				'element_id' => 'km2',
				'settings'   => array( 'tiled_posts_display' => 'wc' ),
			),
			'source not available'
		);
		$ok = $this->ok(
			'kuduy-themify-builder-module-update',
			array(
				'post_id'    => $id,
				'element_id' => 'km2',
				'settings'   => array( 'tiled_posts_display' => 'rss' ),
			)
		);
		$this->assertSame( 'rss', $ok['element']['mod_settings']['tiled_posts_display'] );
	}
}
