<?php
/**
 * Breakdance 3 / Oxygen 6 through their own abilities (test matrix BA1–BA4): list, read, write with undo, refusals.
 * Runs against a stand-in for the abilities the builders register (KUDUY_FAKES=breakdance or KUDUY_FAKES=oxygen);
 * skips otherwise. Not verified against the builders themselves.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BuilderAbilitiesTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static string $ns;

	private static string $prefix;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BREAKDANCE_MODE' ) || ! function_exists( 'kuduy_fake_bd_can' ) ) {
			self::markTestSkipped( 'The Breakdance / Oxygen stand-in is not loaded in this run (KUDUY_FAKES=breakdance or oxygen).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$ns     = (string) constant( 'BREAKDANCE_MODE' );
		self::$prefix = '_' . self::$ns . '_';
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Tool name for this run's builder.
	 *
	 * @param string $mode abilities | ability-get | ability-run.
	 */
	private function tool( string $mode ): string {
		return 'kuduy-' . self::$ns . '-' . $mode;
	}

	/**
	 * A draft page.
	 */
	private function page(): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy builder page',
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
	}

	public function test_ba1_abilities_are_listed_with_what_runs_here(): void {
		$ns   = self::$ns;
		$list = $this->ok( $this->tool( 'abilities' ) );
		$this->assertTrue( $list['enabled'] );
		$rows = array_column( $list['abilities'], null, 'name' );
		$this->assertSame( 'read', $rows[ "$ns/get-post-tree" ]['kind'] );
		$this->assertSame( $this->tool( 'ability-get' ), $rows[ "$ns/get-post-tree" ]['run_with'] );
		$this->assertSame( $this->tool( 'ability-run' ), $rows[ "$ns/edit-post" ]['run_with'] );
		$this->assertSame( $this->tool( 'ability-run' ), $rows[ "$ns/create-post" ]['run_with'] );
		$this->assertSame( 'not run here', $rows[ "$ns/set-global-settings" ]['run_with'] );
		$this->assertSame( 'not run here', $rows[ "$ns/report-bug" ]['run_with'] );
		$this->assertStringContainsString( 'outside services', $rows[ "$ns/report-bug" ]['why_not'] );
		$this->assertSame( 'read', $rows[ "$ns/report-bug" ]['kind'], 'Breakdance marks it read-only; it is refused anyway.' );
		$this->assertSame( 'not run here', $rows[ "$ns/set-home-page" ]['run_with'], 'Takes a post but changes the whole site.' );

		$one = $this->ok( $this->tool( 'abilities' ), array( 'name' => "$ns/edit-post" ) )['ability'];
		$this->assertContains( 'operations', $one['input_schema']['required'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertSame( 'partial', $integrations[ $ns ]['support'], 'Not verified against the builder, so not full.' );
		$this->assertTrue( $integrations[ $ns ]['tools']['available'] );
	}

	public function test_ba2_read_write_and_undo(): void {
		$ns   = self::$ns;
		$page = $this->page();

		$this->assertSame( 'Stand-in instructions.', $this->ok( $this->tool( 'ability-get' ), array( 'name' => "$ns/get-instructions" ) )['result']['instructions'] );
		$before = $this->ok(
			$this->tool( 'ability-get' ),
			array(
				'name'  => "$ns/get-post-tree",
				'input' => array( 'post_id' => $page ),
			)
		)['result']['tree'];
		$this->assertSame( array(), $before['root']['children'] );

		$edit = $this->ok(
			$this->tool( 'ability-run' ),
			array(
				'name'  => "$ns/edit-post",
				'input' => array(
					'post_id'    => $page,
					'operations' => array(
						array(
							'op'      => 'insert',
							'payload' => array(
								'element_type' => 'EssentialElements\\Heading',
								'properties'   => array( 'content' => array( 'content' => array( 'text' => 'Hello builder' ) ) ),
							),
						),
					),
				),
			)
		);
		$this->assertSame( 1, $edit['result']['elements'] );
		$stored = get_post_meta( $page, self::$prefix . 'data', true );
		$this->assertStringContainsString( 'Hello builder', (string) $stored['tree_json_string'], 'The builder stored the tree in its own meta.' );
		$built = (int) get_post_meta( $page, 'kuduy_fake_bd_cache_built', true );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edit['_snapshot_id'] ) );
		$this->assertSame( '', get_post_meta( $page, self::$prefix . 'data', true ), 'Undo removes the builder data the page did not have before.' );
		$this->assertSame( $built + 1, (int) get_post_meta( $page, 'kuduy_fake_bd_cache_built', true ), 'Undo asks the builder to rebuild the page.' );

		$created = $this->ok(
			$this->tool( 'ability-run' ),
			array(
				'name'  => "$ns/create-post",
				'input' => array( 'title' => 'Made by the builder' ),
			)
		);
		$this->assertSame( 'Made by the builder', get_the_title( $created['result']['post_id'] ) );
	}

	public function test_ba3_site_wide_writes_and_reports_are_not_run(): void {
		$ns = self::$ns;
		$this->fails( $this->tool( 'ability-run' ), array( 'name' => "$ns/set-global-settings" ), 'site-wide settings cannot be undone here' );
		$this->fails( $this->tool( 'ability-run' ), array( 'name' => "$ns/report-bug" ), 'no data is sent to outside services' );
		$this->fails( $this->tool( 'ability-get' ), array( 'name' => "$ns/report-bug" ), 'refused by the read tool too, although marked read-only' );
		$this->fails(
			$this->tool( 'ability-run' ),
			array(
				'name'  => "$ns/set-home-page",
				'input' => array( 'post_id' => $this->page() ),
			),
			'the home page is a site-wide setting'
		);
		$this->assertFalse( get_option( 'kuduy_fake_bd_ran_set-home-page' ) );
		$this->assertFalse( get_option( 'kuduy_fake_bd_ran_set-global-settings' ) );
		$this->assertFalse( get_option( 'kuduy_fake_bd_ran_report-bug' ) );
	}

	public function test_ba4_refusals(): void {
		$ns   = self::$ns;
		$page = $this->page();
		$edit = array(
			'name'  => "$ns/edit-post",
			'input' => array(
				'post_id'    => $page,
				'operations' => array(
					array(
						'op'      => 'insert',
						'payload' => array( 'element_type' => 'X' ),
					),
				),
			),
		);

		$this->fails( $this->tool( 'ability-get' ), $edit, 'a write ability is not run by the read tool' );
		$this->fails( $this->tool( 'ability-run' ), array( 'name' => "$ns/get-instructions" ), 'a read ability is not run by the write tool' );
		$this->fails( $this->tool( 'ability-run' ), array( 'name' => "$ns/no-such-ability" ), 'unknown ability' );
		$this->fails(
			$this->tool( 'ability-run' ),
			array(
				'name'  => "$ns/edit-post",
				'input' => array( 'post_id' => $page ),
			),
			'the ability validates its own input'
		);
		$this->fails(
			$this->tool( 'ability-run' ),
			array(
				'name'  => "$ns/edit-post",
				'input' => array(
					'post_id'    => $page,
					'operations' => array( array( 'op' => 'explode' ) ),
				),
			),
			'the builder refuses an unknown operation'
		);

		wp_set_current_user( self::$editor );
		$this->fails( $this->tool( 'abilities' ), array(), 'the builders give their abilities to administrators only' );
		$this->fails( $this->tool( 'ability-run' ), $edit, 'an editor is refused by the builder' );
		$this->assertSame( '', get_post_meta( $page, self::$prefix . 'data', true ), 'Refused calls changed nothing.' );
	}
}
