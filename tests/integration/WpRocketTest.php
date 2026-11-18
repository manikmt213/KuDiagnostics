<?php
/**
 * WP Rocket (test matrix WR1, phase L5): settings through its get-options ability (keys outside its allowlist never
 * shown), a switch and a list changed through set-option with undo, a refused option, cache health and one page's
 * state, purge of the whole site and of one post through kuduy-cache-purge, editors refused. WP Rocket is paid: this
 * runs against the stand-in (KUDUY_FAKES=wp-rocket), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WpRocketTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			self::markTestSkipped( 'WP Rocket (or its stand-in) is not loaded (KUDUY_FAKES=wp-rocket).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	public function test_wr1_settings_status_purge(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$settings = (array) $this->ok( 'kuduy-wp-rocket-settings' )['settings'];
		$this->assertSame( 1, $settings['minify_css'] );
		$this->assertArrayNotHasKey( 'consumer_key', $settings, 'Only WP Rocket\'s allowlisted options are read.' );

		$on = $this->ok(
			'kuduy-wp-rocket-setting-update',
			array(
				'option' => 'minify_js',
				'value'  => true,
			)
		);
		$this->assertSame( 0, $on['previous_value'] );
		$this->assertSame( 1, $on['new_value'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $on['_snapshot_id'] ) );
		$this->assertSame( 0, ( (array) $this->ok( 'kuduy-wp-rocket-settings' )['settings'] )['minify_js'], 'Undo switches it back.' );

		$list = $this->ok(
			'kuduy-wp-rocket-setting-update',
			array(
				'option' => 'cache_reject_uri',
				'value'  => array( '/checkout/' ),
			)
		);
		$this->assertSame( array( '/cart/', '/checkout/' ), $list['new_value'], '"update" adds to a list.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $list['_snapshot_id'] ) );
		$this->assertSame( array( '/cart/' ), ( (array) $this->ok( 'kuduy-wp-rocket-settings' )['settings'] )['cache_reject_uri'], 'Undo replaces the whole list.' );

		$this->fails(
			'kuduy-wp-rocket-setting-update',
			array(
				'option' => 'consumer_key',
				'value'  => 'x',
			),
			'not on WP Rocket\'s allowlist'
		);

		$post   = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy rocket',
				'post_status' => 'publish',
			)
		);
		$status = $this->ok( 'kuduy-wp-rocket-cache-status', array( 'post_id' => $post ) );
		$this->assertSame( 2, ( (array) $status['health'] )['counts']['pending'] );
		$this->assertSame( 'completed', ( (array) $status['page'] )['status'] );

		delete_option( 'kuduy_fake_rocket_log' );
		$this->ok(
			'kuduy-cache-purge',
			array(
				'target'  => 'post',
				'post_id' => $post,
			)
		);
		$this->ok( 'kuduy-cache-purge', array( 'target' => 'all' ) );
		$log = (array) get_option( 'kuduy_fake_rocket_log', array() );
		$this->assertContains( 'post:' . $post, $log );
		$this->assertContains( 'domain', $log );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-wp-rocket-settings', array(), 'WP Rocket settings need a site manager' );
	}
}
