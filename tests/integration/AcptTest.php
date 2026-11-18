<?php
/**
 * ACPT (test matrix FE4, phase E4, D58): a post type and a taxonomy registered through ACPT (existing keys refused),
 * a meta group created through ACPT's public API (invalid definitions refused by ACPT with nothing saved), groups
 * listed with boxes and fields, values written and read for a post, unknown fields refused, undo; editors refused on
 * definitions. Runs only when ACPT is active (KUDUY_PLUGINS=acpt-lite@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AcptTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'save_acpt_meta_field_value' ) ) {
			self::markTestSkipped( 'ACPT is not active in this run (KUDUY_PLUGINS).' );
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

	public function test_fe4_definitions_and_values(): void {
		$listed = $this->ok( 'kuduy-acpt-groups' );
		$this->assertContains( 'Text', $listed['field_types'] );

		$this->ok(
			'kuduy-acpt-post-type-create',
			array(
				'post_name'      => 'kuduy_album',
				'singular_label' => 'Album',
				'plural_label'   => 'Albums',
			)
		);
		$this->assertTrue( post_type_exists( 'kuduy_album' ) );
		$this->ok(
			'kuduy-acpt-taxonomy-create',
			array(
				'slug'           => 'kuduy_label',
				'singular_label' => 'Label',
				'plural_label'   => 'Labels',
				'post_types'     => array( 'kuduy_album' ),
			)
		);
		$this->fails(
			'kuduy-acpt-post-type-create',
			array(
				'post_name'      => 'page',
				'singular_label' => 'P',
				'plural_label'   => 'Ps',
			),
			'existing key'
		);

		$this->ok(
			'kuduy-acpt-group-create',
			array(
				'name'       => 'album_details',
				'label'      => 'Album details',
				'post_types' => array( 'post' ),
				'boxes'      => array(
					array(
						'name'   => 'details',
						'label'  => 'Details',
						'fields' => array(
							array(
								'name'     => 'artist',
								'label'    => 'Artist',
								'type'     => 'Text',
								'required' => true,
							),
							array(
								'name'  => 'year',
								'label' => 'Year',
								'type'  => 'Text',
							),
						),
					),
				),
			)
		);
		$groups = array_column( $this->ok( 'kuduy-acpt-groups' )['groups'], null, 'name' );
		$this->assertArrayHasKey( 'album_details', $groups );
		$this->assertSame( array( 'artist', 'year' ), array_column( $groups['album_details']['boxes'][0]['fields'], 'name' ) );

		$text = $this->fails(
			'kuduy-acpt-group-create',
			array(
				'name'       => 'broken_group',
				'label'      => 'Broken',
				'post_types' => array( 'post' ),
				'boxes'      => array(
					array(
						'name'   => 'b',
						'label'  => 'B',
						'fields' => array(
							array(
								'name'  => 'x',
								'label' => 'X',
								'type'  => 'NoSuchType',
							),
						),
					),
				),
			),
			'ACPT refuses an unknown field type'
		);
		$this->assertStringContainsString( 'Nothing was created', $text );
		$this->assertArrayNotHasKey( 'broken_group', array_column( $this->ok( 'kuduy-acpt-groups' )['groups'], null, 'name' ) );

		$post = (int) wp_insert_post(
			array(
				'post_title'  => 'An album',
				'post_status' => 'publish',
			)
		);
		$done = $this->ok(
			'kuduy-acpt-values-update',
			array(
				'post_id' => $post,
				'values'  => array(
					'details' => array(
						'artist' => 'The Band',
						'year'   => '1999',
					),
				),
			)
		);
		$got  = $this->ok( 'kuduy-acpt-values-get', array( 'post_id' => $post ) )['values'];
		$this->assertSame( 'The Band', $got['details']['artist'] );
		$this->assertSame( '1999', $got['details']['year'] );
		$this->assertSame(
			'The Band',
			$this->ok(
				'kuduy-acpt-values-get',
				array(
					'post_id' => $post,
					'fields'  => array( 'details' => array( 'artist' ) ),
				)
			)['values']['details']['artist']
		);

		$again = $this->ok(
			'kuduy-acpt-values-update',
			array(
				'post_id' => $post,
				'values'  => array( 'details' => array( 'artist' => 'Another Band' ) ),
			)
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $again['_snapshot_id'] ) );
		$this->assertSame( 'The Band', $this->ok( 'kuduy-acpt-values-get', array( 'post_id' => $post ) )['values']['details']['artist'], 'Undo puts the value back.' );
		$this->assertNotEmpty( $done['_snapshot_id'] );

		$this->fails(
			'kuduy-acpt-values-update',
			array(
				'post_id' => $post,
				'values'  => array( 'details' => array( 'no_such' => 'x' ) ),
			),
			'unknown field'
		);
		$this->fails( 'kuduy-acpt-values-get', array( 'post_id' => 999999 ), 'no such post' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			'kuduy-acpt-taxonomy-create',
			array(
				'slug'           => 'kuduy_x',
				'singular_label' => 'X',
				'plural_label'   => 'Xs',
			),
			'editors may not register types'
		);
	}
}
