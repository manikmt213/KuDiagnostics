<?php
/**
 * Brizy read tools (test matrix BZ1–BZ3): status, list, page structure, refusals. Brizy cannot boot on the SQLite
 * test site, so this runs against a stand-in for its page model (KUDUY_FAKES=brizy); skips otherwise. Not verified
 * against Brizy itself.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BrizyTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	private static int $page;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'Brizy_Editor_Post' ) ) {
			self::markTestSkipped( 'The Brizy stand-in is not loaded in this run (KUDUY_FAKES=brizy).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		wp_set_current_user( self::$admin );

		self::$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy brizy page',
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
		$data       = array(
			'items' => array(
				array(
					'type'  => 'Section',
					'value' => array(
						'_id'   => 'sec1',
						'items' => array(
							array(
								'type'  => 'SectionItem',
								'value' => array(
									'_id'   => 'item1',
									'items' => array(
										array(
											'type'  => 'RichText',
											'value' => array(
												'_id'  => 'text1',
												'text' => '<p class="brz-tp-paragraph">Brizy body text</p>',
											),
										),
										array(
											'type'  => 'Button',
											'value' => array(
												'_id'  => 'btn1',
												'text' => 'Brizy button',
												'linkExternal' => 'https://example.org/',
												'bgColorHex' => '#112233',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
		$model      = \Brizy_Editor_Post::get( self::$page );
		$model->enable_editor();
		$model->set_editor_data( (string) wp_json_encode( $data ) );
		$model->set_editor_version( BRIZY_EDITOR_VERSION );
		$model->save();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_bz1_status_and_registry(): void {
		$status = $this->ok( 'kuduy-brizy-status' );
		$this->assertSame( BRIZY_VERSION, $status['version'] );
		$this->assertSame( 'free', $status['edition'] );
		$this->assertContains( 'page', $status['post_types'] );
		$this->assertTrue( $status['can_build'] );
		$this->assertStringContainsString( 'Changing layouts', $status['not_supported'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertSame( 'partial', $integrations['brizy']['support'], 'Read only is not full support.' );
		$this->assertTrue( $integrations['brizy']['tools']['available'] );
	}

	public function test_bz2_list_and_structure(): void {
		$pages = $this->ok( 'kuduy-brizy-pages', array( 'search' => 'Kuduy brizy page' ) );
		$this->assertContains( self::$page, array_column( $pages['pages'], 'id' ) );

		$page = $this->ok( 'kuduy-brizy-get', array( 'id' => self::$page ) );
		$this->assertTrue( $page['read_only'] );
		$this->assertSame( 4, $page['element_count'] );
		$this->assertSame( 'Section', $page['items'][0]['type'] );
		$this->assertSame( 'sec1', $page['items'][0]['id'] );
		$leaves = $page['items'][0]['children'][0]['children'];
		$this->assertSame( array( 'RichText', 'Button' ), array_column( $leaves, 'type' ) );
		$this->assertSame( 'Brizy body text', $leaves[0]['text'], 'Text is returned without markup.' );
		$this->assertSame( 'https://example.org/', $leaves[1]['linkExternal'] );
		$this->assertArrayNotHasKey( 'values', $leaves[1] );

		$full = $this->ok(
			'kuduy-brizy-get',
			array(
				'id'             => self::$page,
				'include_values' => true,
			)
		);
		$this->assertSame( '#112233', $full['items'][0]['children'][0]['children'][1]['values']['bgColorHex'] );
		$this->assertArrayNotHasKey( 'items', $full['items'][0]['values'], 'Children are in the tree, not repeated in values.' );
	}

	public function test_bz3_refusals_and_nothing_changes(): void {
		$before = get_post_meta( self::$page );

		$plain = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not Brizy',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-brizy-get', array( 'id' => $plain ), 'not built with Brizy' );
		$this->assertNotContains( $plain, array_column( $this->ok( 'kuduy-brizy-pages' )['pages'], 'id' ) );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-brizy-get', array( 'id' => self::$page ), 'an author may not read someone else\'s page' );
		$this->assertNotContains( self::$page, array_column( $this->ok( 'kuduy-brizy-pages' )['pages'], 'id' ), 'Only pages the user may edit are listed.' );

		wp_set_current_user( self::$admin );
		$this->ok( 'kuduy-brizy-get', array( 'id' => self::$page ) );
		$this->assertEquals( $before, get_post_meta( self::$page ), 'Reading changes nothing.' );
	}
}
