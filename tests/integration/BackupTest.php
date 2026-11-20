<?php
/**
 * Phase O (test matrix AO7): backups through UpdraftPlus (D77). Runs when UpdraftPlus is active
 * (KUDUY_PLUGINS=updraftplus@1.26.16).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BackupTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
			$this->assertNotTrue( ToolRegistry::create()->get( 'kuduy-backup-status' )->is_available(), 'Without UpdraftPlus the backup tools are not offered.' );
			$this->markTestSkipped( 'UpdraftPlus is not active in this run.' );
		}
		wp_set_current_user( self::$admin );
	}

	/**
	 * AO7: status lists UpdraftPlus' history; start asks first, then queues UpdraftPlus' own backup event.
	 */
	public function test_ao7_status_and_start(): void {
		$time = time() - 600;
		\UpdraftPlus_Backup_History::save_history(
			array(
				$time => array(
					'nonce'       => 'abcdef012345',
					'db'          => 'backup_2026-11-20-1200_Test_abcdef012345-db.gz',
					'db-size'     => 2048,
					'service'     => array( 'none' ),
					'always_keep' => true,
				),
			)
		);
		$status = $this->ok( 'kuduy-backup-status', array() );
		$this->assertSame( 'abcdef012345', $status['backups'][0]['nonce'] );
		$this->assertContains( 'db', $status['backups'][0]['contains'] );
		$this->assertTrue( $status['backups'][0]['always_keep'] );

		$plan = $this->ok( 'kuduy-backup-start', array( 'kind' => 'database' ) );
		$this->assertFalse( $plan['started'], 'Asks first.' );
		$this->assertSame( 0, self::queued(), 'Nothing queued before confirm.' );

		// Keep WP-Cron from running the backup in the test (spawn_cron() makes an HTTP request).
		$block = static fn() => new \WP_Error( 'kuduy_test', 'blocked in tests' );
		add_filter( 'pre_http_request', $block );
		$done  = $this->ok(
			'kuduy-backup-start',
			array(
				'kind'    => 'database',
				'nocloud' => true,
				'confirm' => true,
			)
		);
		remove_filter( 'pre_http_request', $block );
		$this->assertTrue( $done['started'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $done['nonce'] );
		$this->assertSame( 1, self::queued() );
		$queued = $this->ok( 'kuduy-backup-status', array() )['running'];
		$this->assertContains( $done['nonce'], array_column( $queued, 'nonce' ), 'Status shows the queued backup.' );
		wp_clear_scheduled_hook(
			'updraft_backupnow_backup_database',
			array(
				array(
					'nocloud'     => true,
					'use_nonce'   => $done['nonce'],
					'always_keep' => false,
				),
			)
		);
		$this->assertSame( 0, self::queued() );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-backup-status', array(), 'editors cannot see backups' );
	}

	/**
	 * Queued UpdraftPlus database backups.
	 */
	private static function queued(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += count( (array) ( $hooks['updraft_backupnow_backup_database'] ?? array() ) );
		}
		return $count;
	}
}
