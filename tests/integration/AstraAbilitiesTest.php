<?php
/**
 * Astra's settings through Astra's own abilities (upgrade plan phase D, test matrix TH5–TH7) on the real theme.
 * Runs with KUDUY_THEME=astra@…; the runner switches Astra's abilities on (they are off by default). Skips otherwise.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AstraAbilitiesTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ASTRA_THEME_VERSION' ) ) {
			self::markTestSkipped( 'Astra is not the active theme in this run (KUDUY_THEME=astra@…).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_th5_abilities_are_listed_with_their_schema(): void {
		$list = $this->ok( 'kuduy-astra-abilities' );
		$this->assertTrue( $list['enabled'], 'The runner switched Astra\'s abilities on.' );
		$rows = array_column( $list['abilities'], null, 'name' );
		$this->assertGreaterThan( 30, count( $rows ) );
		$this->assertSame( 'read', $rows['astra/get-container-layout']['kind'] );
		$this->assertSame( 'kuduy-astra-ability-get', $rows['astra/get-container-layout']['run_with'] );
		$this->assertSame( 'write', $rows['astra/update-container-layout']['kind'] );
		$this->assertSame( 'kuduy-astra-ability-run', $rows['astra/update-container-layout']['run_with'] );

		$one = $this->ok( 'kuduy-astra-abilities', array( 'name' => 'astra/update-container-layout' ) )['ability'];
		$this->assertContains( 'narrow-width-container', $one['input_schema']['properties']['container_layout']['enum'] );
		$this->fails( 'kuduy-astra-abilities', array( 'name' => 'astra/no-such-ability' ), 'unknown ability' );

		$facts = $this->ok( 'kuduy-theme-facts' );
		$this->assertSame( 'astra', $facts['stylesheet'] );
		$this->assertStringContainsString( 'kuduy-astra-abilities', $facts['managed_with'] );

		$integrations = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertTrue( $integrations['astra']['active'] );
		$this->assertTrue( $integrations['astra']['tools']['available'] );
	}

	public function test_th6_read_write_and_undo_through_astras_abilities(): void {
		$before = $this->ok( 'kuduy-astra-ability-get', array( 'name' => 'astra/get-container-layout' ) )['result'];
		$this->assertTrue( $before['success'] );
		$layout = $before['data']['container_layout'];
		$stored = get_option( 'astra-settings', array() );
		$new    = 'narrow-width-container' === $layout ? 'full-width-container' : 'narrow-width-container';

		$update = $this->ok(
			'kuduy-astra-ability-run',
			array(
				'name'  => 'astra/update-container-layout',
				'input' => array( 'container_layout' => $new ),
			)
		);
		$this->assertTrue( $update['result']['success'] );
		// Checked in Astra's stored option, not by reading back through Astra: Astra keeps its options in memory for
		// one request, every real tool call is its own request, and all tests share one PHP process.
		$this->assertSame( $new, ( (array) get_option( 'astra-settings' ) )['ast-site-content-layout'] ?? null, 'It is in Astra\'s own option.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $update['_snapshot_id'] ) );
		$this->assertEquals( $stored, get_option( 'astra-settings', array() ), 'Undo puts the stored Astra settings back exactly.' );
	}

	public function test_th7_refusals(): void {
		$stored = get_option( 'astra-settings' );

		$this->fails(
			'kuduy-astra-ability-run',
			array(
				'name'  => 'astra/update-container-layout',
				'input' => array( 'container_layout' => 'not-a-layout' ),
			),
			'Astra validates its own input'
		);
		$this->fails( 'kuduy-astra-ability-run', array( 'name' => 'astra/get-container-layout' ), 'a read ability is not run by the write tool' );
		$this->fails(
			'kuduy-astra-ability-get',
			array(
				'name'  => 'astra/update-container-layout',
				'input' => array( 'container_layout' => 'narrow-width-container' ),
			),
			'a write ability is not run by the read tool'
		);
		$this->fails( 'kuduy-astra-ability-run', array( 'name' => 'astra/no-such-ability' ), 'unknown ability' );
		$this->fails(
			'kuduy-astra-ability-run',
			array(
				'name'  => 'astra/update-post-meta',
				'input' => array( 'post_id' => 1 ),
			),
			'post meta belongs to the post tools'
		);

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-astra-abilities', array(), 'an editor may not manage theme settings' );
		$this->fails(
			'kuduy-astra-ability-run',
			array(
				'name'  => 'astra/update-container-layout',
				'input' => array( 'container_layout' => 'narrow-width-container' ),
			),
			'an editor may not change theme settings'
		);
		$this->assertEquals( $stored, get_option( 'astra-settings' ), 'Refused calls changed nothing.' );
	}
}
