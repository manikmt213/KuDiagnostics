<?php
/**
 * Kirki builder read tools on the real plugin (test matrix KK1–KK3): status, list, page structure, refusals.
 * Runs when Kirki 6 is installed (KUDUY_PLUGINS=kirki@…); skips otherwise. The fixture page is stored the way Kirki
 * stores a page (editor mode meta and the "kirki" data meta); the tools read it back through Kirki's own helper.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class KirkiTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	private static int $page;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'KIRKI_META_NAME_FOR_POST_EDITOR_MODE' ) || ! class_exists( '\Kirki\HelperFunctions' ) ) {
			self::markTestSkipped( 'The Kirki builder is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );

		self::$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy kirki page',
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
		update_post_meta( self::$page, KIRKI_META_NAME_FOR_POST_EDITOR_MODE, 'kirki' );
		update_post_meta(
			self::$page,
			'kirki',
			array(
				'blocks' => array(
					'root'  => array(
						'id'         => 'root',
						'name'       => 'body',
						'children'   => array( 'sec1' ),
						'properties' => array( 'tag' => 'div' ),
					),
					'sec1'  => array(
						'id'         => 'sec1',
						'name'       => 'section',
						'children'   => array( 'head1', 'text1' ),
						'properties' => array( 'tag' => 'section' ),
					),
					'head1' => array(
						'id'         => 'head1',
						'name'       => 'heading',
						'children'   => array(),
						'properties' => array(
							'tag'      => 'h2',
							'contents' => array( 'Kirki heading text' ),
						),
					),
					'text1' => array(
						'id'         => 'text1',
						'name'       => 'paragraph',
						'children'   => array(),
						'properties' => array(
							'tag'        => 'p',
							'contents'   => array( 'Kirki <strong>body</strong> text' ),
							'attributes' => array( 'data-kuduy' => 'yes' ),
						),
					),
				),
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_kk1_status_and_registry(): void {
		$status = $this->ok( 'kuduy-kirki-status' );
		$this->assertSame( KIRKI_VERSION, $status['version'] );
		$this->assertTrue( $status['can_build'] );
		$this->assertStringContainsString( 'Changing layouts', $status['not_supported'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertTrue( $integrations['kirki']['active'] );
		$this->assertSame( 'partial', $integrations['kirki']['support'], 'Read only is not full support.' );
		$this->assertTrue( $integrations['kirki']['tools']['available'] );
	}

	public function test_kk2_list_and_structure(): void {
		$this->assertContains( self::$page, array_column( $this->ok( 'kuduy-kirki-pages', array( 'search' => 'Kuduy kirki page' ) )['pages'], 'id' ) );

		$page = $this->ok( 'kuduy-kirki-get', array( 'id' => self::$page ) );
		$this->assertTrue( $page['read_only'] );
		$this->assertSame( 4, $page['block_count'] );
		$this->assertSame( 'body', $page['root']['name'] );
		$section = $page['root']['children'][0];
		$this->assertSame( 'section', $section['name'] );
		$this->assertSame( array( 'heading', 'paragraph' ), array_column( $section['children'], 'name' ) );
		$this->assertSame( 'h2', $section['children'][0]['tag'] );
		$this->assertSame( 'Kirki heading text', $section['children'][0]['text'] );
		$this->assertSame( 'Kirki body text', $section['children'][1]['text'], 'Text is returned without markup.' );
		$this->assertArrayNotHasKey( 'properties', $section['children'][1] );

		$full = $this->ok(
			'kuduy-kirki-get',
			array(
				'id'                 => self::$page,
				'include_properties' => true,
			)
		);
		$this->assertSame( 'yes', $full['root']['children'][0]['children'][1]['properties']['attributes']['data-kuduy'] );
	}

	/**
	 * Save in Kirki's builder adds an unpublished staged version (seen on Kirki 6.4.2 on a live site); visitors still
	 * see the published one. The fixture saves a new version through Kirki's own staging code.
	 */
	public function test_kk4_unpublished_version(): void {
		$this->assertFalse( $this->ok( 'kuduy-kirki-get', array( 'id' => self::$page ) )['unpublished_changes'] );
		\Kirki\Staging::save_page_staging_data_to_db(
			self::$page,
			array(
				'blocks' => array(
					'root'    => array(
						'id'         => 'root',
						'name'       => 'body',
						'parentId'   => false,
						'children'   => array( 'newhead' ),
						'properties' => array( 'tag' => 'div' ),
					),
					'newhead' => array(
						'id'         => 'newhead',
						'name'       => 'heading',
						'parentId'   => 'root',
						'children'   => array(),
						'properties' => array(
							'tag'      => 'h1',
							'contents' => array( 'Unpublished heading' ),
						),
					),
				),
			)
		);
		$published = $this->ok( 'kuduy-kirki-get', array( 'id' => self::$page ) );
		$this->assertSame( 'published', $published['version'] );
		$this->assertTrue( $published['unpublished_changes'] );
		$this->assertSame( 'Kirki heading text', $published['root']['children'][0]['children'][0]['text'], 'Visitors still see the published version.' );

		$latest = $this->ok(
			'kuduy-kirki-get',
			array(
				'id'      => self::$page,
				'version' => 'latest',
			)
		);
		$this->assertSame( 'latest', $latest['version'] );
		$this->assertSame( 2, $latest['block_count'] );
		$this->assertSame( 'Unpublished heading', $latest['root']['children'][0]['text'] );
	}

	public function test_kk3_refusals_and_nothing_changes(): void {
		$before = get_post_meta( self::$page );

		$plain = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not Kirki',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-kirki-get', array( 'id' => $plain ), 'not built with Kirki' );
		$this->assertNotContains( $plain, array_column( $this->ok( 'kuduy-kirki-pages' )['pages'], 'id' ) );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-kirki-get', array( 'id' => self::$page ), 'an author may not read someone else\'s page' );
		$this->assertNotContains( self::$page, array_column( $this->ok( 'kuduy-kirki-pages' )['pages'], 'id' ) );

		wp_set_current_user( self::$admin );
		$this->ok( 'kuduy-kirki-get', array( 'id' => self::$page ) );
		$this->assertEquals( $before, get_post_meta( self::$page ), 'Reading changes nothing.' );
	}
}
