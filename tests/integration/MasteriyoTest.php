<?php
/**
 * Phase R (test matrix MY1–MY4): Masteriyo tools against the real Masteriyo 3.5.2 (job "+ Masteriyo").
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MasteriyoTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		if ( ! defined( 'MASTERIYO_VERSION' ) ) {
			$this->markTestSkipped( 'Masteriyo is not active in this run.' );
		}
		wp_set_current_user( self::$admin );
	}

	/**
	 * MY1: the abilities list says what runs here and why not.
	 */
	public function test_abilities_listed(): void {
		$list = $this->ok( 'kuduy-masteriyo-abilities' );
		$this->assertTrue( $list['enabled'] );
		$rows = array_column( $list['abilities'], null, 'name' );
		$this->assertSame( 'read', $rows['masteriyo/course-list']['kind'] ?? null );
		$this->assertSame( 'kuduy-masteriyo-ability-get', $rows['masteriyo/course-list']['run_with'] );
		$this->assertSame( 'kuduy-masteriyo-ability-run', $rows['masteriyo/course-update']['run_with'] ?? null );
		$this->assertSame( 'not run here', $rows['masteriyo/order-delete']['run_with'] ?? null );
		$this->assertArrayHasKey( 'why_not', $rows['masteriyo/user-create'] ?? array() );
		$this->assertArrayNotHasKey( 'masteriyo/settings-update-payments', $rows, 'Masteriyo does not register payment settings updates by default.' );
		$one = $this->ok( 'kuduy-masteriyo-abilities', array( 'name' => 'masteriyo/course-create' ) );
		$this->assertArrayHasKey( 'input_schema', $one['ability'] );
	}

	/**
	 * MY2: a course is created, read, changed and the change undone; a write ability is refused by the read tool.
	 */
	public function test_course_lifecycle(): void {
		$created = $this->ok(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-create',
				'input' => array(
					'name'   => 'Kuduy Masteriyo course',
					'status' => 'draft',
				),
			)
		);
		$id      = (int) ( $created['result']['id'] ?? 0 );
		$this->assertGreaterThan( 0, $id, (string) wp_json_encode( $created ) );
		$this->assertSame( 'mto-course', get_post_type( $id ) );

		$read = $this->ok(
			'kuduy-masteriyo-ability-get',
			array(
				'name'  => 'masteriyo/course-get',
				'input' => array( 'id' => $id ),
			)
		);
		$this->assertSame( 'Kuduy Masteriyo course', $read['result']['name'] ?? null );

		$this->fails(
			'kuduy-masteriyo-ability-get',
			array(
				'name'  => 'masteriyo/course-update',
				'input' => array( 'id' => $id ),
			),
			'a write ability is not run by the read tool'
		);

		$changed = $this->ok(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-update',
				'input' => array(
					'id'   => $id,
					'name' => 'Kuduy course renamed',
				),
			)
		);
		$this->assertSame( 'Kuduy course renamed', get_the_title( $id ) );
		$this->assertArrayHasKey( '_snapshot_id', $changed );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		clean_post_cache( $id );
		$this->assertSame( 'Kuduy Masteriyo course', get_the_title( $id ), 'Undo puts the old name back.' );

		$trashed = $this->ok(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-delete',
				'input' => array( 'id' => $id ),
			)
		);
		clean_post_cache( $id );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		clean_post_cache( $id );
		$this->assertSame( 'draft', get_post_status( $id ), 'Undo takes the course out of the trash.' );
		wp_delete_post( $id, true );
	}

	/**
	 * MY3: what is not run here is refused before Masteriyo is called.
	 */
	public function test_refused_writes(): void {
		$course = wp_insert_post(
			array(
				'post_type'   => 'mto-course',
				'post_title'  => 'Kuduy refused course',
				'post_status' => 'draft',
			)
		);
		$this->fails(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-delete',
				'input' => array(
					'id'    => $course,
					'force' => true,
				),
			),
			'permanent deletes are not run'
		);
		$this->assertSame( 'draft', get_post_status( $course ) );
		$this->fails(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/order-create',
				'input' => array(),
			),
			'orders are not run'
		);
		$this->fails(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-update',
				'input' => array( 'name' => 'No id' ),
			),
			'an update must name the item by id'
		);
		$this->fails(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/no-such-ability',
				'input' => array(),
			),
			'unknown ability'
		);
		wp_delete_post( (int) $course, true );
	}

	/**
	 * MY4: a subscriber gets Masteriyo's own refusal for a write.
	 */
	public function test_masteriyo_checks_the_user(): void {
		$subscriber = self::make_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		$this->fails(
			'kuduy-masteriyo-ability-run',
			array(
				'name'  => 'masteriyo/course-create',
				'input' => array( 'name' => 'Not allowed' ),
			),
			'Masteriyo refuses a subscriber'
		);
	}
}
