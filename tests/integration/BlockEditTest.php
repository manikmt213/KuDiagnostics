<?php
/**
 * Block-level editing and block intelligence (phase C, test matrix BK-E1…BK-E12): kuduy-block-edit operations keep
 * untouched blocks as they were, keep innerContent in step with inner blocks, refuse what the registered block
 * types do not allow and save nothing then, and can be undone; kuduy-block-schema, -validate, kuduy-patterns,
 * kuduy-block-usage and kuduy-post-duplicate report and copy real data. With GenerateBlocks, Kadence Blocks or
 * Spectra Legacy active, one of their blocks is inserted and checked too.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BlockEditTest extends TestCase {

	use CallsTools;

	private const DOC = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inner A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Inner B</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

	private const PARA = "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->";

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
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
	 * A page with the test document.
	 *
	 * @param string $content Content.
	 */
	private function page( string $content = self::DOC ): int {
		return (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => 'Blocks',
					'post_content' => $content,
				)
			)
		);
	}

	/**
	 * Edit and return the result.
	 *
	 * @param int                        $id  Post.
	 * @param list<array<string, mixed>> $ops Operations.
	 * @return array<string, mixed>
	 */
	private function edit( int $id, array $ops ): array {
		return $this->ok(
			'kuduy-block-edit',
			array(
				'id'         => $id,
				'operations' => $ops,
			)
		);
	}

	/**
	 * Names and texts of the post's blocks by path ("0", "2.1", …; numeric paths become integer keys).
	 *
	 * @param int $id Post.
	 * @return array<int|string, string>
	 */
	private function tree( int $id ): array {
		$out = array();
		foreach ( $this->ok( 'kuduy-post-blocks', array( 'id' => $id ) )['blocks'] as $block ) {
			$out[ $block['path'] ] = $block['name'] . ':' . $block['text'];
		}
		return $out;
	}

	public function test_unchanged_content_round_trips(): void {
		$this->assertSame( self::DOC, serialize_blocks( parse_blocks( self::DOC ) ), 'Editor-made markup survives WordPress\' own serializer.' );
		$check = $this->ok( 'kuduy-block-validate', array( 'markup' => self::DOC ) );
		$this->assertTrue( $check['valid'] );
		$this->assertTrue( $check['round_trip'] );
		$this->assertSame( 3, $check['block_count'] );
	}

	public function test_insert_at_top_and_inside_a_group(): void {
		$id = $this->page();
		$this->edit(
			$id,
			array(
				array(
					'op'     => 'insert',
					'parent' => '',
					'index'  => 1,
					'markup' => self::PARA,
				),
				array(
					'op'     => 'insert',
					'parent' => '3',
					'index'  => 1,
					'markup' => self::PARA,
				),
				array(
					'op'     => 'insert',
					'markup' => self::PARA,
				),
			)
		);
		$tree = $this->tree( $id );
		$this->assertSame( 'core/heading:Title', $tree['0'] );
		$this->assertSame( 'core/paragraph:New', $tree['1'] );
		$this->assertSame( 'core/paragraph:First', $tree['2'] );
		$this->assertSame( array( 'core/paragraph:Inner A', 'core/paragraph:New', 'core/paragraph:Inner B' ), array( $tree['3.0'], $tree['3.1'], $tree['3.2'] ) );
		$this->assertSame( 'core/paragraph:New', $tree['4'] );
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertStringContainsString( "<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>New</p>", $content, 'Blocks are separated as the editor separates them.' );
		$this->assertStringContainsString( '<div class="wp-block-group"><!-- wp:paragraph -->', $content, 'The group\'s own HTML is kept around its inner blocks.' );
		$this->assertTrue( $this->ok( 'kuduy-block-validate', array( 'id' => $id ) )['valid'] );
	}

	public function test_insert_into_an_empty_group(): void {
		$id = $this->page( "<!-- wp:group -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->" );
		$this->edit(
			$id,
			array(
				array(
					'op'     => 'insert',
					'parent' => '0',
					'markup' => self::PARA,
				),
			)
		);
		$this->assertSame( 'core/paragraph:New', $this->tree( $id )['0.0'] );
		$this->assertStringContainsString( '<div class="wp-block-group"><!-- wp:paragraph -->', (string) get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_update_move_duplicate_remove(): void {
		$id     = $this->page();
		$result = $this->edit(
			$id,
			array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'expect' => 'paragraph',
					'markup' => "<!-- wp:paragraph -->\n<p>Replaced</p>\n<!-- /wp:paragraph -->",
				),
				array(
					'op'     => 'update',
					'path'   => '0',
					'expect' => 'core/heading',
					'set'    => array( 'level' => 3 ),
				),
				array(
					'op'        => 'move',
					'path'      => '2.1',
					'to_parent' => '',
					'to_index'  => 0,
				),
				array(
					'op'   => 'duplicate',
					'path' => '1',
				),
				array(
					'op'   => 'remove',
					'path' => '4.0',
				),
			)
		);
		$this->assertSame( 5, $result['applied'] );
		$this->assertSame( 1, preg_match( '/HTML saved by the block editor/', implode( ' ', $result['notes'] ) ), 'Updating a block with saved HTML says that HTML was kept.' );
		$tree = $this->tree( $id );
		$this->assertSame( 'core/paragraph:Inner B', $tree['0'] );
		$this->assertSame( 'core/heading:Title', $tree['1'] );
		$this->assertSame( 'core/heading:Title', $tree['2'] );
		$this->assertSame( 'core/paragraph:Replaced', $tree['3'] );
		$this->assertSame( 'core/group:', $tree['4'] );
		$this->assertArrayNotHasKey( '4.0', $tree, 'Inner A was removed, Inner B moved out.' );
		$this->assertSame( 3, parse_blocks( (string) get_post_field( 'post_content', $id, 'raw' ) )[2]['attrs']['level'] ?? null );

		$this->assertTrue( $this->ok( 'kuduy-block-validate', array( 'id' => $id ) )['valid'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertSame( self::DOC, (string) get_post_field( 'post_content', $id, 'raw' ), 'Undo restores the content byte for byte.' );
	}

	public function test_refusals_save_nothing(): void {
		$id      = $this->page();
		$refused = array(
			'unknown block'      => array(
				'op'     => 'insert',
				'markup' => '<!-- wp:kuduy/does-not-exist /-->',
			),
			'invalid attribute'  => array(
				'op'   => 'update',
				'path' => '0',
				'set'  => array( 'level' => 'big' ),
			),
			'unknown attribute'  => array(
				'op'   => 'update',
				'path' => '0',
				'set'  => array( 'colour_of_sky' => 'blue' ),
			),
			'sourced attribute'  => array(
				'op'   => 'update',
				'path' => '0',
				'set'  => array( 'content' => 'Changed' ),
			),
			'nesting rule'       => array(
				'op'     => 'insert',
				'markup' => "<!-- wp:column -->\n<div class=\"wp-block-column\"></div>\n<!-- /wp:column -->",
			),
			'stale path'         => array(
				'op'     => 'remove',
				'path'   => '1',
				'expect' => 'heading',
			),
			'missing path'       => array(
				'op'   => 'remove',
				'path' => '9',
			),
			'HTML outside block' => array(
				'op'     => 'insert',
				'markup' => '<p>loose</p>',
			),
			'into itself'        => array(
				'op'        => 'move',
				'path'      => '2',
				'to_parent' => '2.0',
			),
		);
		foreach ( $refused as $because => $op ) {
			$this->fails(
				'kuduy-block-edit',
				array(
					'id'         => $id,
					'operations' => array(
						array(
							'op'     => 'insert',
							'markup' => self::PARA,
						),
						$op,
					),
				),
				$because
			);
			$this->assertSame( self::DOC, (string) get_post_field( 'post_content', $id, 'raw' ), $because . ': the first operation is not saved either.' );
		}
	}

	public function test_permissions_and_builder_pages(): void {
		$id = $this->page();
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-block-edit',
			array(
				'id'         => $id,
				'operations' => array(
					array(
						'op'   => 'remove',
						'path' => '0',
					),
				),
			),
			'An author cannot edit someone else\'s page'
		);
		wp_set_current_user( self::$admin );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		$this->fails(
			'kuduy-block-edit',
			array(
				'id'         => $id,
				'operations' => array(
					array(
						'op'   => 'remove',
						'path' => '0',
					),
				),
			),
			'Elementor pages are refused'
		);
		$this->assertSame( self::DOC, (string) get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_schema_and_validate(): void {
		$schema = $this->ok( 'kuduy-block-schema', array( 'name' => 'heading' ) );
		$this->assertSame( 'core/heading', $schema['name'] );
		$this->assertSame( \WP_Block_Type_Registry::get_instance()->get_registered( 'core/heading' )->is_dynamic(), $schema['dynamic'] );
		$this->assertContains( $schema['attributes']['level']['type'], array( 'integer', 'number' ) );
		$this->assertArrayHasKey( 'source', $schema['attributes']['content'], 'Attributes kept in the HTML are marked.' );
		$this->assertTrue( $this->ok( 'kuduy-block-schema', array( 'name' => 'core/latest-posts' ) )['dynamic'] );
		$this->assertSame( array( 'core/columns' ), $this->ok( 'kuduy-block-schema', array( 'name' => 'column' ) )['parent'] );
		$this->fails( 'kuduy-block-schema', array( 'name' => 'kuduy/none' ), 'Unknown block type' );

		$bad = $this->ok( 'kuduy-block-validate', array( 'markup' => "<!-- wp:heading {\"level\":\"x\"} -->\n<h2>T</h2>\n<!-- /wp:heading -->\n\n<p>loose</p>\n\n<!-- wp:kuduy/none /-->" ) );
		$this->assertFalse( $bad['valid'] );
		$this->assertCount( 3, $bad['problems'], implode( ' | ', $bad['problems'] ) );
	}

	public function test_patterns_and_insert_a_pattern(): void {
		register_block_pattern(
			'kuduy-test/hero',
			array(
				'title'      => 'Kuduy test hero',
				'categories' => array( 'text' ),
				'content'    => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Pattern heading</h2>\n<!-- /wp:heading -->",
			)
		);
		$list = $this->ok( 'kuduy-patterns', array( 'search' => 'kuduy test' ) );
		$this->assertSame( array( 'kuduy-test/hero' ), array_column( $list['patterns'], 'name' ) );
		$this->assertStringContainsString( 'Pattern heading', $this->ok( 'kuduy-patterns', array( 'name' => 'kuduy-test/hero' ) )['content'] );

		$id = $this->page();
		$this->edit(
			$id,
			array(
				array(
					'op'      => 'insert',
					'index'   => 0,
					'pattern' => 'kuduy-test/hero',
				),
			)
		);
		$this->assertSame( 'core/heading:Pattern heading', $this->tree( $id )['0'] );
		unregister_block_pattern( 'kuduy-test/hero' );
	}

	public function test_usage(): void {
		$id    = $this->page();
		$found = array_column( $this->ok( 'kuduy-block-usage', array( 'name' => 'core/group' ) )['posts'], 'count', 'id' );
		$this->assertSame( 1, $found[ $id ] ?? null );
		$para = array_column( $this->ok( 'kuduy-block-usage', array( 'name' => 'paragraph' ) )['posts'], 'count', 'id' );
		$this->assertSame( 3, $para[ $id ] ?? null, 'Core names work without the core/ prefix; inner blocks count.' );
		$none = array_column( $this->ok( 'kuduy-block-usage', array( 'name' => 'core/para' ) )['posts'], 'count', 'id' );
		$this->assertArrayNotHasKey( $id, $none, 'A name is not matched as a prefix of another.' );
	}

	public function test_duplicate_a_post(): void {
		$id = $this->page();
		update_post_meta( $id, 'kuduy_note', array( 'a' => 1 ) );
		update_post_meta( $id, '_edit_lock', '1:1' );
		$copy = $this->ok( 'kuduy-post-duplicate', array( 'id' => $id ) );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertSame( $id, $copy['copied_from'] );
		$this->assertSame( 'Blocks (copy)', $copy['title'] );
		$this->assertSame( self::DOC, (string) get_post_field( 'post_content', $copy['id'], 'raw' ) );
		$this->assertSame( array( 'a' => 1 ), get_post_meta( $copy['id'], 'kuduy_note', true ), 'Structured custom fields stay structured.' );
		$this->assertSame( '', get_post_meta( $copy['id'], '_edit_lock', true ), 'Edit locks belong to the original.' );
		$this->assertSame( self::$admin, (int) get_post_field( 'post_author', $copy['id'] ) );

		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		$this->fails( 'kuduy-post-duplicate', array( 'id' => $id ), 'Elementor pages go to kuduy-elementor-duplicate' );
	}

	public function test_block_plugins_when_active(): void {
		$cases = array(
			'<!-- wp:generateblocks/text {"uniqueId":"kd1a2b3c","tagName":"p"} -->
<p class="gb-text">GenerateBlocks text</p>
<!-- /wp:generateblocks/text -->',
			'<!-- wp:kadence/spacer {"uniqueID":"_kd1a2b"} /-->',
			'<!-- wp:uagb/separator {"block_id":"kd1a2b3c"} /-->',
		);
		$ran   = 0;
		foreach ( $cases as $markup ) {
			$name = (string) parse_blocks( $markup )[0]['blockName'];
			if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			++$ran;
			$this->assertNotEmpty( $this->ok( 'kuduy-block-schema', array( 'name' => $name ) )['attributes'], $name . ' has registered attributes.' );
			$id = $this->page();
			$this->edit(
				$id,
				array(
					array(
						'op'     => 'insert',
						'index'  => 0,
						'markup' => $markup,
					),
				)
			);
			$this->assertStringStartsWith( $name . ':', $this->tree( $id )['0'] );
			$this->assertTrue( $this->ok( 'kuduy-block-validate', array( 'id' => $id ) )['valid'], $name );
			$this->assertSame( 1, array_column( $this->ok( 'kuduy-block-usage', array( 'name' => $name ) )['posts'], 'count', 'id' )[ $id ] ?? null );
		}
		if ( 0 === $ran ) {
			$this->markTestSkipped( 'No block plugin is active in this run.' );
		}
	}
}
