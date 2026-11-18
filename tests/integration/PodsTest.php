<?php
/**
 * Pods (test matrix FE3, phase E3, D58): a post type created as a Pod, fields added and changed through Pods' own API
 * (unknown types and settings refused), labels changed and undone, a field change undone; items added and changed
 * with their values read back, password values never shown, undo; authors refused on definitions. Runs only when
 * Pods is active (KUDUY_PLUGINS=pods@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class PodsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'pods_api' ) ) {
			self::markTestSkipped( 'Pods is not active in this run (KUDUY_PLUGINS).' );
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

	public function test_fe3_pods_fields_items(): void {
		$made = $this->ok(
			'kuduy-pods-save',
			array(
				'pod'      => 'kuduy_film',
				'action'   => 'create',
				'type'     => 'post_type',
				'singular' => 'Film',
				'plural'   => 'Films',
			)
		);
		$this->assertTrue( $made['created'] );
		$this->assertSame( 'post_type', $made['pod']['type'] );
		$this->assertContains( 'kuduy_film', array_column( $this->ok( 'kuduy-pods-list' )['pods'], 'name' ) );

		$this->ok(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'director',
				'settings' => array(
					'label'    => 'Director',
					'type'     => 'text',
					'required' => 1,
				),
			)
		);
		$this->ok(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'runtime',
				'settings' => array(
					'label' => 'Runtime',
					'type'  => 'number',
				),
			)
		);
		$this->ok(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'access_code',
				'settings' => array(
					'label' => 'Access code',
					'type'  => 'password',
				),
			)
		);
		$got    = $this->ok( 'kuduy-pods-get', array( 'pod' => 'kuduy_film' ) )['pod'];
		$fields = array_column( $got['fields'], null, 'name' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_name, post_type, post_parent FROM {$wpdb->posts} WHERE post_type IN ('_pods_pod','_pods_field','_pods_group')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'text', $fields['director']['type'] ?? null, wp_json_encode( array( $got, $rows ) ) );
		$this->assertTrue( $fields['director']['required'] );

		$changed = $this->ok(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'director',
				'settings' => array( 'label' => 'Directed by' ),
			)
		);
		$this->assertFalse( $changed['created'] );
		$this->assertSame( 'Directed by', $changed['field']['label'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( 'Director', array_column( $this->ok( 'kuduy-pods-get', array( 'pod' => 'kuduy_film' ) )['pod']['fields'], 'label', 'name' )['director'], 'Undo puts the label back.' );

		$relabel = $this->ok(
			'kuduy-pods-save',
			array(
				'pod'    => 'kuduy_film',
				'action' => 'update',
				'plural' => 'Movies',
			)
		);
		$this->assertSame( 'Movies', $relabel['pod']['label'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $relabel['_snapshot_id'] ) );
		$this->assertSame( 'Films', $this->ok( 'kuduy-pods-get', array( 'pod' => 'kuduy_film' ) )['pod']['label'] );

		$item = $this->ok(
			'kuduy-pods-item-save',
			array(
				'pod'    => 'kuduy_film',
				'values' => array(
					'post_title'  => 'Kuduy test film',
					'post_status' => 'publish',
					'director'    => 'A. Director',
					'runtime'     => 95,
					'access_code' => 'sesame',
				),
			)
		);
		$this->assertTrue( $item['created'] );
		$id  = $item['id'];
		$got = $this->ok(
			'kuduy-pods-item-get',
			array(
				'pod' => 'kuduy_film',
				'id'  => $id,
			)
		)['values'];
		$this->assertSame( 'A. Director', $got['director'] );
		$this->assertSame( '[hidden]', $got['access_code'] );
		$this->assertStringNotContainsString( 'sesame', (string) wp_json_encode( $got ) );

		$edit = $this->ok(
			'kuduy-pods-item-save',
			array(
				'pod'    => 'kuduy_film',
				'id'     => $id,
				'values' => array( 'director' => 'B. Director' ),
			)
		);
		$this->assertSame( 'B. Director', pods( 'kuduy_film', $id )->field( 'director' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$this->assertSame( 'A. Director', pods( 'kuduy_film', $id )->field( 'director' ), 'Undo puts the value back.' );

		$this->fails(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'x',
				'settings' => array(
					'label' => 'X',
					'type'  => 'no_such_type',
				),
			),
			'unknown type'
		);
		$this->fails(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'x',
				'settings' => array(
					'label' => 'X',
					'type'  => 'text',
					'sql'   => 'x',
				),
			),
			'unknown setting'
		);
		$this->fails(
			'kuduy-pods-item-save',
			array(
				'pod'    => 'kuduy_film',
				'id'     => $id,
				'values' => array( 'no_such_field' => 'x' ),
			),
			'unknown field'
		);
		$this->fails(
			'kuduy-pods-save',
			array(
				'pod'      => 'page',
				'action'   => 'create',
				'type'     => 'post_type',
				'singular' => 'P',
				'plural'   => 'Ps',
			),
			'core post type exists'
		);
		$this->fails( 'kuduy-pods-get', array( 'pod' => 'no_such_pod' ), 'no such pod' );

		wp_set_current_user( self::make_user( 'author' ) );
		$this->fails(
			'kuduy-pods-field-save',
			array(
				'pod'      => 'kuduy_film',
				'field'    => 'director',
				'settings' => array( 'label' => 'X' ),
			),
			'authors may not change definitions'
		);
	}
}
