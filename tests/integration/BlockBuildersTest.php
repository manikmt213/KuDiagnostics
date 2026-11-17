<?php
/**
 * Divi 5 and Etch read tools (test matrix BK1–BK3): pages saved as WordPress blocks, read with parse_blocks().
 * Runs with the block-type stand-in (KUDUY_FAKES=blockbuilders); skips otherwise. Not verified against the builders.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BlockBuildersTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	private static int $divi;

	private static int $divi4;

	private static int $etch;

	private static int $internal;

	public static function setUpBeforeClass(): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'divi/section' ) ) {
			self::markTestSkipped( 'The Divi / Etch block stand-in is not loaded in this run (KUDUY_FAKES=blockbuilders).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		$make         = static fn( string $title, string $content ): int => (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => $title,
					'post_status'  => 'draft',
					'post_author'  => self::$admin,
					'post_content' => $content,
				)
			)
		);
		// As Divi 5.19.0 saved a page built in its Visual Builder (owner's site, 2026-11-16), plus a text module.
		self::$divi = $make(
			'Kuduy divi page',
			'<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}},"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.0.0-public-alpha.18.2"} -->' . "\n"
			. '<!-- wp:divi/row {"module":{"meta":{"adminLabel":{"desktop":{"value":"Row"}}}},"builderVersion":"5.0.0-public-alpha.18.2"} -->' . "\n"
			. '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.0.0-public-alpha.18.2"} -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Divi text value"}}}} /-->' . "\n"
			. '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Divi live check heading"}}},"builderVersion":"5.19.0"} /-->' . "\n\n"
			. '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Live check button"}}}},"builderVersion":"5.19.0"} /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n" . '<!-- /wp:divi/row -->' . "\n" . '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
		);
		// Divi 5 keeps an internal post with module markup (_et_pb_speculation, "Visual Builder Asset Prerender").
		register_post_type( 'kuduy_internal', array( 'public' => false ) );
		self::$internal = (int) wp_insert_post(
			array(
				'post_type'    => 'kuduy_internal',
				'post_title'   => 'Kuduy internal',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:divi/section /-->',
			)
		);
		self::$divi4    = $make( 'Kuduy divi4 page', '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Old Divi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' );
		self::$etch     = $make(
			'Kuduy etch page',
			'<!-- wp:etch/element {"tag":"section","attributes":{"class":"hero"}} --><section class="hero"><!-- wp:etch/text --><p>Etch text</p><!-- /wp:etch/text --></section><!-- /wp:etch/element -->'
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_bk1_divi_pages_and_structure(): void {
		$pages = array_column( $this->ok( 'kuduy-divi-pages' )['pages'], null, 'id' );
		$this->assertSame( 'blocks', $pages[ self::$divi ]['format'] );
		$this->assertSame( 'divi4', $pages[ self::$divi4 ]['format'], 'Divi 4 pages are listed as such.' );
		$this->assertArrayNotHasKey( self::$etch, $pages );
		$this->assertArrayNotHasKey( self::$internal, $pages, 'Post types without an admin screen are the builder\'s own, not pages.' );

		$page = $this->ok( 'kuduy-divi-get', array( 'id' => self::$divi ) );
		$this->assertFalse( $page['read_only'], 'Divi 5 pages are changed with kuduy-block-edit.' );
		$this->assertSame( 7, $page['count'] );
		$this->assertSame( 'divi/placeholder', $page['blocks'][0]['name'] );
		$modules = $page['blocks'][0]['children'][0]['children'][0]['children'][0]['children'];
		$text    = $modules[0];
		$this->assertSame( 'divi/text', $text['name'] );
		$this->assertSame( 'Divi text value', $text['text'], 'Divi 5 keeps module text in attributes, not in the block HTML.' );
		$this->assertArrayNotHasKey( 'attributes', $text );
		$this->assertSame( array( 'divi/heading', 'Divi live check heading' ), array( $modules[1]['name'], $modules[1]['text'] ) );
		$this->assertSame( array( 'divi/button', 'Live check button' ), array( $modules[2]['name'], $modules[2]['text'] ) );
		$this->assertArrayNotHasKey( 'text', $page['blocks'][0]['children'][0], 'An admin label is not page text.' );

		$full = $this->ok(
			'kuduy-divi-get',
			array(
				'id'                 => self::$divi,
				'include_attributes' => true,
			)
		);
		$this->assertSame( 'Divi text value', $full['blocks'][0]['children'][0]['children'][0]['children'][0]['children'][0]['attributes']['content']['innerContent']['desktop']['value'] );

		$old = $this->ok( 'kuduy-divi-get', array( 'id' => self::$divi4 ) );
		$this->assertSame( 'divi4', $old['format'] );
		$this->assertArrayNotHasKey( 'blocks', $old );
	}

	public function test_bk2_etch_pages_and_structure(): void {
		$this->assertSame( array( self::$etch ), array_column( $this->ok( 'kuduy-etch-pages', array( 'search' => 'Kuduy etch' ) )['pages'], 'id' ) );
		$page = $this->ok( 'kuduy-etch-get', array( 'id' => self::$etch ) );
		$this->assertSame( 2, $page['count'] );
		$this->assertSame( 'etch/element', $page['blocks'][0]['name'] );
		$this->assertSame( 'Etch text', $page['blocks'][0]['children'][0]['text'] );
	}

	public function test_bk4_divi5_block_edit_with_merge_and_divi4_refused(): void {
		$text = '0.0.0.0.0';
		$this->assertSame( 'divi/text', array_column( $this->ok( 'kuduy-post-blocks', array( 'id' => self::$divi ) )['blocks'], 'name', 'path' )[ $text ] );
		$edit  = $this->ok(
			'kuduy-block-edit',
			array(
				'id'         => self::$divi,
				'operations' => array(
					array(
						'op'     => 'update',
						'path'   => $text,
						'expect' => 'divi/text',
						'merge'  => true,
						'set'    => array( 'content' => array( 'innerContent' => array( 'tablet' => array( 'value' => 'Tablet text' ) ) ) ),
					),
				),
			)
		);
		$attrs = parse_blocks( get_post_field( 'post_content', self::$divi ) )[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs'];
		$this->assertSame( 'Divi text value', $attrs['content']['innerContent']['desktop']['value'], 'merge keeps the other breakpoints.' );
		$this->assertSame( 'Tablet text', $attrs['content']['innerContent']['tablet']['value'] );
		$this->assertStringContainsString( 'Divi live check heading', get_post_field( 'post_content', self::$divi ), 'Blocks around it are untouched.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$this->assertStringNotContainsString( 'Tablet text', get_post_field( 'post_content', self::$divi ) );

		$before = get_post_field( 'post_content', self::$divi4 );
		$this->fails(
			'kuduy-block-edit',
			array(
				'id'         => self::$divi4,
				'operations' => array(
					array(
						'op'     => 'insert',
						'markup' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
					),
				),
			),
			'a Divi 4 page is shortcodes, not blocks'
		);
		$this->fails(
			'kuduy-post-update',
			array(
				'id'      => self::$divi4,
				'content' => 'x',
			),
			'nor is its content replaced'
		);
		$this->assertSame( $before, get_post_field( 'post_content', self::$divi4 ) );
	}

	public function test_bk3_refusals_and_nothing_changes(): void {
		$before = get_post_field( 'post_content', self::$etch );
		$this->fails( 'kuduy-etch-get', array( 'id' => self::$divi ), 'a Divi page is not an Etch page' );
		$plain = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Plain',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-divi-get', array( 'id' => $plain ), 'not a Divi page' );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-etch-get', array( 'id' => self::$etch ), 'an author may not read someone else\'s page' );
		$this->assertSame( array(), $this->ok( 'kuduy-divi-pages' )['pages'], 'Only pages the user may edit are listed.' );

		$this->assertSame( $before, get_post_field( 'post_content', self::$etch ), 'Reading changes nothing.' );
	}
}
