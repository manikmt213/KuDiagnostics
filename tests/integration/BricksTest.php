<?php
/**
 * Bricks (test matrix BR1–BR4): read tool on the documented data model; Bricks' own abilities through the shared
 * builder tools (read, write with undo, PHP and site-wide abilities not run). Runs against a stand-in
 * (KUDUY_FAKES=bricks); skips otherwise. Not verified against Bricks itself.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BricksTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'kuduy_fake_bricks_can' ) ) {
			self::markTestSkipped( 'The Bricks stand-in is not loaded in this run (KUDUY_FAKES=bricks).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A page with Bricks elements: section > container > heading + text.
	 */
	private function page(): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy bricks page',
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
		update_post_meta(
			$id,
			'_bricks_page_content_2',
			array(
				array(
					'id'       => 'sec001',
					'name'     => 'section',
					'parent'   => 0,
					'children' => array( 'con001' ),
					'settings' => array(),
				),
				array(
					'id'       => 'con001',
					'name'     => 'container',
					'parent'   => 'sec001',
					'children' => array( 'hed001', 'txt001' ),
					'settings' => array(),
				),
				array(
					'id'       => 'hed001',
					'name'     => 'heading',
					'parent'   => 'con001',
					'children' => array(),
					'settings' => array(
						'text' => 'Bricks heading',
						'tag'  => 'h2',
					),
					'label'    => 'Hero title',
				),
				array(
					'id'       => 'txt001',
					'name'     => 'text-basic',
					'parent'   => 'con001',
					'children' => array(),
					'settings' => array( 'text' => '<p>Bricks <b>body</b></p>' ),
				),
			)
		);
		return $id;
	}

	public function test_br1_read_tool_on_the_documented_data_model(): void {
		$page = $this->page();
		$this->assertContains( $page, array_column( $this->ok( 'kuduy-bricks-pages', array( 'search' => 'Kuduy bricks page' ) )['pages'], 'id' ) );

		$got = $this->ok( 'kuduy-bricks-get', array( 'id' => $page ) );
		$this->assertTrue( $got['read_only'] );
		$this->assertSame( 4, $got['element_count'] );
		$this->assertSame( 'section', $got['elements'][0]['name'] );
		$leaves = $got['elements'][0]['children'][0]['children'];
		$this->assertSame( array( 'heading', 'text-basic' ), array_column( $leaves, 'name' ) );
		$this->assertSame( 'Hero title', $leaves[0]['label'] );
		$this->assertSame( 'Bricks body', $leaves[1]['text'] );
		$this->assertArrayNotHasKey( 'settings', $leaves[0] );
		$this->assertSame(
			'h2',
			$this->ok(
				'kuduy-bricks-get',
				array(
					'id'               => $page,
					'include_settings' => true,
				)
			)['elements'][0]['children'][0]['children'][0]['settings']['tag']
		);
		$this->fails(
			'kuduy-bricks-get',
			array(
				'id'   => $page,
				'area' => 'header',
			),
			'no header elements'
		);

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-bricks-get', array( 'id' => $page ), 'an author may not read someone else\'s page' );
	}

	public function test_br2_abilities_listed_and_classified(): void {
		$rows = array_column( $this->ok( 'kuduy-bricks-abilities' )['abilities'], null, 'name' );
		$this->assertSame( 'read', $rows['bricks/start-here']['kind'], 'Without annotations, start-here is read by its name.' );
		$this->assertSame( 'read', $rows['bricks/get-page-elements']['kind'] );
		$this->assertSame( 'kuduy-bricks-ability-run', $rows['bricks/set-page-elements']['run_with'] );
		$this->assertSame( 'not run here', $rows['bricks/set-global-settings']['run_with'] );
		$this->assertSame( 'not run here', $rows['bricks/execute-php']['run_with'] );
		$this->assertSame( 'not run here', $rows['bricks/delete-post']['run_with'], 'Takes a post, but deletes it: not on the page-level list.' );
		$this->assertStringContainsString( 'runs code', $rows['bricks/execute-php']['why_not'] );
		$this->assertSame( 'not run here', $rows['bricks/regenerate-css-files']['run_with'], 'Bricks declares no-input abilities with an object for properties.' );
		$one = $this->ok( 'kuduy-bricks-abilities', array( 'name' => 'bricks/regenerate-css-files' ) )['ability'];
		$this->assertSame( 'object', $one['input_schema']['type'] );
		$this->assertStringContainsString( 'live output is still inline', $one['description'], 'An unclosed tag in a description keeps the text.' );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertSame( 'partial', $integrations['bricks']['support'] );
	}

	public function test_br3_write_through_bricks_with_undo(): void {
		$page   = $this->page();
		$before = get_post_meta( $page, '_bricks_page_content_2', true );

		$elements = $this->ok(
			'kuduy-bricks-ability-get',
			array(
				'name'  => 'bricks/get-page-elements',
				'input' => array( 'postId' => $page ),
			)
		)['result']['elements'];
		$this->assertCount( 4, $elements );

		$set = $this->ok(
			'kuduy-bricks-ability-run',
			array(
				'name'  => 'bricks/set-page-elements',
				'input' => array(
					'postId'   => $page,
					'elements' => array(
						array(
							'id'       => 'new001',
							'name'     => 'heading',
							'parent'   => 0,
							'children' => array(),
							'settings' => array( 'text' => 'Replaced' ),
						),
					),
				),
			)
		);
		$this->assertSame( 1, $set['result']['saved'] );
		$this->assertCount( 1, get_post_meta( $page, '_bricks_page_content_2', true ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertEquals( $before, get_post_meta( $page, '_bricks_page_content_2', true ), 'Undo puts the Bricks elements back.' );
	}

	public function test_br4_refusals(): void {
		$page = $this->page();
		$this->fails( 'kuduy-bricks-ability-run', array( 'name' => 'bricks/execute-php' ), 'PHP abilities are never run' );
		$this->fails( 'kuduy-bricks-ability-run', array( 'name' => 'bricks/set-global-settings' ), 'site-wide settings are not changed' );
		$this->assertFalse( get_option( 'kuduy_fake_bricks_php' ) );
		$this->assertFalse( get_option( 'kuduy_fake_bricks_global' ) );
		$this->fails(
			'kuduy-bricks-ability-run',
			array(
				'name'  => 'bricks/set-page-elements',
				'input' => array(
					'postId'   => $page,
					'elements' => array( array( 'id' => 'x' ) ),
				),
			),
			'Bricks refuses invalid elements'
		);
		$this->assertCount( 4, get_post_meta( $page, '_bricks_page_content_2', true ) );
		$this->fails(
			'kuduy-bricks-ability-run',
			array(
				'name'  => 'bricks/delete-post',
				'input' => array( 'postId' => $page ),
			),
			'deleting is not a page-level change'
		);
		$this->assertFalse( get_option( 'kuduy_fake_bricks_deleted' ) );
		$this->fails(
			'kuduy-bricks-ability-run',
			array(
				'name'  => 'bricks/set-page-elements',
				'input' => array( 'elements' => array() ),
			),
			'the page must be named by its id so it can be undone'
		);

		$editor = self::make_user( 'editor' );
		wp_set_current_user( $editor );
		$this->fails( 'kuduy-bricks-abilities', array(), 'listing abilities needs manage_options' );
	}
}
