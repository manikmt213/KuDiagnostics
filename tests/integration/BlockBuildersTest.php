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
		self::$divi   = $make(
			'Kuduy divi page',
			'<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} --><!-- wp:divi/row --><!-- wp:divi/column -->'
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Divi text value"}}}} /-->'
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
		);
		self::$divi4  = $make( 'Kuduy divi4 page', '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Old Divi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' );
		self::$etch   = $make(
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

		$page = $this->ok( 'kuduy-divi-get', array( 'id' => self::$divi ) );
		$this->assertTrue( $page['read_only'] );
		$this->assertSame( 5, $page['count'] );
		$this->assertSame( 'divi/placeholder', $page['blocks'][0]['name'] );
		$text = $page['blocks'][0]['children'][0]['children'][0]['children'][0]['children'][0];
		$this->assertSame( 'divi/text', $text['name'] );
		$this->assertArrayNotHasKey( 'attributes', $text );

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
