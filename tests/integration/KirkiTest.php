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
		$this->assertStringContainsString( 'Changing styles', $status['not_supported'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertTrue( $integrations['kirki']['active'] );
		$this->assertSame( 'partial', $integrations['kirki']['support'], 'Read only is not full support.' );
		$this->assertTrue( $integrations['kirki']['tools']['available'] );
	}

	public function test_kk2_list_and_structure(): void {
		$this->assertContains( self::$page, array_column( $this->ok( 'kuduy-kirki-pages', array( 'search' => 'Kuduy kirki page' ) )['pages'], 'id' ) );

		$page = $this->ok( 'kuduy-kirki-get', array( 'id' => self::$page ) );
		$this->assertFalse( $page['read_only'], 'kuduy-kirki-edit can change it.' );
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

	/**
	 * A published Kirki page for the edit tests: blocks in the published data and one published version, as after
	 * Publish in the builder.
	 */
	private static function published_page(): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy kirki edit',
				'post_status' => 'publish',
				'post_author' => self::$admin,
			)
		);
		update_post_meta( $id, KIRKI_META_NAME_FOR_POST_EDITOR_MODE, 'kirki' );
		update_post_meta(
			$id,
			'kirki',
			array(
				'blocks' => array(
					'root'  => array(
						'id'         => 'root',
						'name'       => 'body',
						'parentId'   => null,
						'children'   => array( 'sec1' ),
						'properties' => array( 'tag' => 'div' ),
					),
					'sec1'  => array(
						'id'         => 'sec1',
						'name'       => 'section',
						'parentId'   => 'root',
						'children'   => array( 'head1', 'text1', 'link1' ),
						'properties' => array( 'tag' => 'section' ),
					),
					'head1' => array(
						'id'         => 'head1',
						'name'       => 'heading',
						'parentId'   => 'sec1',
						'children'   => array(),
						'properties' => array(
							'tag'      => 'h2',
							'contents' => array( 'Old heading' ),
						),
					),
					'text1' => array(
						'id'         => 'text1',
						'name'       => 'paragraph',
						'parentId'   => 'sec1',
						'children'   => array(),
						'properties' => array(
							'tag'      => 'p',
							'contents' => array( 'Intro ', array( 'id' => 'span1' ) ),
						),
					),
					'span1' => array(
						'id'         => 'span1',
						'name'       => 'span',
						'parentId'   => 'text1',
						'children'   => array(),
						'properties' => array(
							'tag'      => 'span',
							'contents' => array( 'bold' ),
						),
					),
					'link1' => array(
						'id'         => 'link1',
						'name'       => 'link-text',
						'parentId'   => 'sec1',
						'children'   => array(),
						'properties' => array(
							'tag'        => 'a',
							'contents'   => array( 'Read more' ),
							'attributes' => array( 'href' => 'https://example.com/old' ),
						),
					),
				),
			)
		);
		call_user_func( array( '\Kirki\App\Supports\Facades\Page', 'publish_stage_version' ), $id );
		return $id;
	}

	public function test_kk5_edit_as_unpublished_version_and_undo(): void {
		$id     = self::published_page();
		$before = get_post_meta( $id, 'kirki', true );
		$edit   = $this->ok(
			'kuduy-kirki-edit',
			array(
				'id'         => $id,
				'operations' => array(
					array(
						'op'    => 'text',
						'block' => 'head1',
						'text'  => 'New <b>heading</b>',
					),
					array(
						'op'    => 'attribute',
						'block' => 'link1',
						'name'  => 'href',
						'value' => 'https://example.com/new',
					),
					array(
						'op'    => 'attribute',
						'block' => 'link1',
						'name'  => 'data-kuduy',
						'value' => 'yes',
					),
					array(
						'op'    => 'duplicate',
						'block' => 'text1',
					),
					array(
						'op'        => 'move',
						'block'     => 'link1',
						'to_parent' => 'sec1',
						'to_index'  => 0,
					),
				),
			)
		);
		$this->assertFalse( $edit['published'] );
		$this->assertSame( 5, $edit['applied'] );
		$this->assertCount( 1, $edit['new_blocks'] );
		$this->assertSame( 8, $edit['block_count'], 'The duplicate copies the paragraph and its inline span.' );
		$this->assertEquals( $before, get_post_meta( $id, 'kirki', true ), 'Visitors still see the published blocks.' );

		$latest  = $this->ok(
			'kuduy-kirki-get',
			array(
				'id'                 => $id,
				'version'            => 'latest',
				'include_properties' => true,
			)
		);
		$section = $latest['root']['children'][0];
		$this->assertTrue( $latest['unpublished_changes'] );
		$this->assertSame( array( 'link1', 'head1', 'text1', $edit['new_blocks'][0] ), array_column( $section['children'], 'id' ) );
		$this->assertSame( 'New heading', $section['children'][1]['text'], 'Text is stored as plain text.' );
		$this->assertSame( 'https://example.com/new', $section['children'][0]['properties']['attributes']['href'] );
		$copy = $section['children'][3]['properties']['contents'][1]['id'];
		$this->assertNotSame( 'span1', $copy, 'The inline span of the copy has its own id.' );

		$this->fails(
			'kuduy-kirki-edit',
			array(
				'id'         => $id,
				'publish'    => true,
				'operations' => array(
					array(
						'op'    => 'remove',
						'block' => 'head1',
					),
				),
			),
			'publishing is refused while unpublished changes exist'
		);

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$this->assertFalse( $this->ok( 'kuduy-kirki-get', array( 'id' => $id ) )['unpublished_changes'], 'Undo removes the version the edit made.' );

		$pub = $this->ok(
			'kuduy-kirki-edit',
			array(
				'id'         => $id,
				'publish'    => true,
				'operations' => array(
					array(
						'op'    => 'remove',
						'block' => 'text1',
					),
				),
			)
		);
		$this->assertTrue( $pub['published'] );
		$blocks = get_post_meta( $id, 'kirki', true )['blocks'];
		$this->assertArrayNotHasKey( 'text1', $blocks );
		$this->assertArrayNotHasKey( 'span1', $blocks, 'Removing a block removes its inline elements.' );
		$this->assertSame( array( 'head1', 'link1' ), $blocks['sec1']['children'] );
		$this->assertFalse( $this->ok( 'kuduy-kirki-get', array( 'id' => $id ) )['unpublished_changes'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $pub['_snapshot_id'] ) );
		$this->assertEquals( $before, get_post_meta( $id, 'kirki', true ), 'Undoing a publish puts the earlier published blocks back.' );
		$this->assertSame( 'Old heading', $this->ok( 'kuduy-kirki-get', array( 'id' => $id ) )['root']['children'][0]['children'][0]['text'] );
	}

	public function test_kk6_edit_refusals(): void {
		$id     = self::published_page();
		$before = get_post_meta( $id, 'kirki', true );
		foreach ( array(
			'root block'      => array(
				'op'    => 'remove',
				'block' => 'root',
			),
			'unknown block'   => array(
				'op'    => 'remove',
				'block' => 'nope',
			),
			'event handler'   => array(
				'op'    => 'attribute',
				'block' => 'link1',
				'name'  => 'onclick',
				'value' => 'alert(1)',
			),
			'javascript url'  => array(
				'op'    => 'attribute',
				'block' => 'link1',
				'name'  => 'href',
				'value' => 'javascript:alert(1)',
			),
			'inline elements' => array(
				'op'    => 'text',
				'block' => 'text1',
				'text'  => 'x',
			),
			'into itself'     => array(
				'op'        => 'move',
				'block'     => 'sec1',
				'to_parent' => 'head1',
			),
		) as $why => $op ) {
			$this->fails(
				'kuduy-kirki-edit',
				array(
					'id'         => $id,
					'operations' => array(
						array(
							'op'    => 'text',
							'block' => 'head1',
							'text'  => 'Changed first',
						),
						$op,
					),
				),
				$why
			);
		}
		$this->assertFalse( $this->ok( 'kuduy-kirki-get', array( 'id' => $id ) )['unpublished_changes'], 'Nothing is saved when an operation fails.' );
		$this->assertEquals( $before, get_post_meta( $id, 'kirki', true ) );
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-kirki-edit',
			array(
				'id'         => $id,
				'operations' => array(
					array(
						'op'    => 'text',
						'block' => 'head1',
						'text'  => 'Author',
					),
				),
			),
			'an author may not edit a page of someone else'
		);
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
