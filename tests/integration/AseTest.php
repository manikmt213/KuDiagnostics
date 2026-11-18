<?php
/**
 * Admin and Site Enhancements (test matrix FE5, phase E5, D58): module switches listed (refused ones marked), switched
 * on and off as ASE stores them with other settings untouched, undo, refused and unknown switches change nothing,
 * secrets never shown, editors refused. Runs only when ASE is active (KUDUY_PLUGINS=admin-site-enhancements@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AseTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ASENHA_SLUG_U' ) ) {
			self::markTestSkipped( 'Admin and Site Enhancements is not active in this run (KUDUY_PLUGINS).' );
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

	public function test_fe5_modules(): void {
		$option = (string) constant( 'ASENHA_SLUG_U' );
		update_option(
			$option,
			array(
				'enable_duplication' => false,
				'smtp_password'      => 'mail-pass-123',
				'custom_body_class'  => 'kept',
			)
		);
		$read    = $this->ok( 'kuduy-ase-settings' );
		$modules = array_column( $read['modules'], null, 'module' );
		$this->assertFalse( $modules['enable_duplication']['on'] );
		$this->assertArrayHasKey( 'not_changed_here', $modules['disable_rest_api'] );
		$this->assertStringNotContainsString( 'mail-pass-123', (string) wp_json_encode( $read ) );

		$done  = $this->ok(
			'kuduy-ase-settings-update',
			array(
				'modules' => array(
					'enable_duplication' => true,
					'disable_comments'   => true,
				),
			)
		);
		$saved = get_option( $option );
		$this->assertTrue( $saved['enable_duplication'] );
		$this->assertTrue( $saved['disable_comments'] );
		$this->assertSame( 'kept', $saved['custom_body_class'], 'Other settings stay.' );
		$this->assertSame( 'mail-pass-123', $saved['smtp_password'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$back = get_option( $option );
		$this->assertFalse( $back['enable_duplication'] );
		$this->assertArrayNotHasKey( 'disable_comments', $back, 'Undo removes what was not there.' );

		foreach ( array( 'disable_rest_api', 'change_login_url', 'insert_head_body_footer_code', 'disable_all_updates' ) as $refused ) {
			$this->fails( 'kuduy-ase-settings-update', array( 'modules' => array( $refused => true ) ), $refused . ' is refused' );
		}
		$this->fails(
			'kuduy-ase-settings-update',
			array(
				'modules' => array(
					'enable_duplication' => true,
					'no_such_module'     => true,
				),
			),
			'unknown module'
		);
		$this->assertFalse( get_option( $option )['enable_duplication'], 'Refused calls change nothing.' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-ase-settings-update', array( 'modules' => array( 'enable_duplication' => true ) ), 'editors may not switch modules' );
	}
}
