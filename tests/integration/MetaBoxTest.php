<?php
/**
 * Meta Box (test matrix FE2, phase E2, D58): a meta box registered in code as Meta Box itself does on init; boxes
 * listed and filtered; values read through Meta Box's own get ability (password never shown) and changed through its
 * update and delete abilities (unknown fields refused, nothing changed); undo; authors refused on others' posts.
 * Runs only when Meta Box is active (KUDUY_PLUGINS=meta-box@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MetaBoxTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $post;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			self::markTestSkipped( 'Meta Box is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
		// As Meta Box's own register_meta_boxes() does on init with the rwmb_meta_boxes filter.
		rwmb_get_registry( 'meta_box' )->make(
			array(
				'id'         => 'kuduy_book_box',
				'title'      => 'Book',
				'post_types' => array( 'post' ),
				'fields'     => array(
					array(
						'id'   => 'kuduy_isbn',
						'name' => 'ISBN',
						'type' => 'text',
					),
					array(
						'id'      => 'kuduy_format',
						'name'    => 'Format',
						'type'    => 'select',
						'options' => array(
							'paper' => 'Paperback',
							'ebook' => 'E-book',
						),
					),
					array(
						'id'    => 'kuduy_tags',
						'name'  => 'Tags',
						'type'  => 'text',
						'clone' => true,
					),
					array(
						'id'   => 'kuduy_pin',
						'name' => 'PIN',
						'type' => 'password',
					),
				),
			)
		)->register_fields();
		self::$post = (int) wp_insert_post(
			array(
				'post_title'  => 'Book post',
				'post_status' => 'publish',
				'post_author' => self::$admin,
			)
		);
		update_post_meta( self::$post, 'kuduy_isbn', '978-0' );
		update_post_meta( self::$post, 'kuduy_pin', 'hunter2' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_fe2_boxes_values_undo(): void {
		$boxes = array_column( $this->ok( 'kuduy-meta-box-boxes', array( 'post_type' => 'post' ) )['boxes'], null, 'id' );
		$this->assertArrayHasKey( 'kuduy_book_box', $boxes );
		$fields = array_column( $boxes['kuduy_book_box']['fields'], null, 'id' );
		$this->assertSame( 'select', $fields['kuduy_format']['type'] );
		$this->assertTrue( $fields['kuduy_tags']['clone'] );
		$this->assertSame( array(), $this->ok( 'kuduy-meta-box-boxes', array( 'post_type' => 'page' ) )['boxes'] );

		$got = $this->ok(
			'kuduy-meta-box-get',
			array( 'object_id' => self::$post )
		)['values'];
		$this->assertSame( '978-0', $got['kuduy_isbn'] );
		$this->assertSame( '[hidden]', $got['kuduy_pin'] );
		$this->assertStringNotContainsString( 'hunter2', (string) wp_json_encode( $got ) );

		$done = $this->ok(
			'kuduy-meta-box-update',
			array(
				'object_id' => self::$post,
				'values'    => array(
					'kuduy_isbn'   => '978-1',
					'kuduy_format' => 'ebook',
					'kuduy_tags'   => array( 'fiction', 'classic' ),
				),
			)
		);
		$this->assertSame( array( 'kuduy_isbn', 'kuduy_format', 'kuduy_tags' ), $done['changed'] );
		$this->assertSame( '978-1', rwmb_get_value( 'kuduy_isbn', array(), self::$post ) );
		$this->assertSame( 'ebook', rwmb_get_value( 'kuduy_format', array(), self::$post ) );
		$this->assertSame( array( 'fiction', 'classic' ), rwmb_get_value( 'kuduy_tags', array(), self::$post ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( '978-0', get_post_meta( self::$post, 'kuduy_isbn', true ), 'Undo puts the value back.' );
		$this->assertSame( '', get_post_meta( self::$post, 'kuduy_format', true ), 'Undo removes what was not there.' );

		$deleted = $this->ok(
			'kuduy-meta-box-update',
			array(
				'object_id' => self::$post,
				'values'    => array( 'kuduy_isbn' => null ),
			)
		);
		$this->assertSame( '', get_post_meta( self::$post, 'kuduy_isbn', true ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( '978-0', get_post_meta( self::$post, 'kuduy_isbn', true ) );

		$this->fails(
			'kuduy-meta-box-update',
			array(
				'object_id' => self::$post,
				'values'    => array(
					'kuduy_isbn' => 'x',
					'_edit_lock' => '1',
				),
			),
			'not a Meta Box field'
		);
		$this->assertSame( '978-0', get_post_meta( self::$post, 'kuduy_isbn', true ), 'Refused calls change nothing.' );
		$this->fails( 'kuduy-meta-box-get', array( 'object_id' => 999999 ), 'no such post' );

		wp_set_current_user( self::make_user( 'author' ) );
		$this->fails(
			'kuduy-meta-box-update',
			array(
				'object_id' => self::$post,
				'values'    => array( 'kuduy_isbn' => 'x' ),
			),
			'authors may not edit others\' posts'
		);
	}
}
