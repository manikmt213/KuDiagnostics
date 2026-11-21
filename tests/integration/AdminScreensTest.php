<?php
/**
 * KuDiagnostics screen (test matrix AP1–AP4, phase P, D84): tabs by role, connections with access in words and last
 * use, activity filters and visibility, integrations list. AP5 (consent wording) is in OAuthTest.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Admin\ActivityTab;
use Kuduy\Mcp\Admin\ConnectionsPage;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AdminScreensTest extends TestCase {

	use CallsTools;

	private const CLIENT = 'https://claude.ai/oauth/kuduy-admin-screens-test.json';

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- fixtures in KuDiagnostics' own tables.
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_oauth_clients',
			array(
				'client_id'     => self::CLIENT,
				'type'          => 'cimd',
				'name'          => 'Claude (screen test)',
				'redirect_uris' => '[]',
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_oauth_tokens',
			array(
				'token_hash' => hash( 'sha256', wp_generate_password( 40, false ) ),
				'type'       => 'refresh',
				'family_id'  => md5( 'kuduy-admin-screens' ),
				'client_id'  => self::CLIENT,
				'user_id'    => self::$author,
				'scope'      => 'kuduy:read kuduy:write',
				'resource'   => home_url(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		foreach ( array(
			array( self::$author, self::CLIENT, 'kuduy-post-get', 'ok' ),
			array( self::$author, self::CLIENT, 'kuduy-post-update', 'ok' ),
			array( self::$author, self::CLIENT, 'kuduy-plugin-delete', 'denied' ),
			array( self::$admin, 'wordpress-login', 'kuduy-integrations', 'ok' ),
		) as $row ) {
			$wpdb->insert(
				$wpdb->prefix . 'kuduy_audit_log',
				array(
					'ts'          => gmdate( 'Y-m-d H:i:s' ),
					'user_id'     => $row[0],
					'client_id'   => $row[1],
					'tool'        => $row[2],
					'result'      => $row[3],
					'duration_ms' => 1,
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * The page as the current user sees it with these query arguments.
	 *
	 * @param array<string, mixed> $get Query arguments.
	 */
	private static function page( array $get ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test request.
		$_GET = $get;
		ob_start();
		ConnectionsPage::render();
		$_GET = array();
		return (string) ob_get_clean();
	}

	public function test_ap1_tabs_by_role(): void {
		wp_set_current_user( self::$admin );
		$html = self::page( array() );
		foreach ( array( 'Connections', 'Activity', 'Integrations' ) as $tab ) {
			$this->assertStringContainsString( '>' . $tab . '</a>', $html );
		}
		wp_set_current_user( self::$author );
		$html = self::page( array( 'tab' => 'integrations' ) );
		$this->assertStringNotContainsString( '>Integrations</a>', $html, 'authors cannot open the Integrations tab' );
		$this->assertStringContainsString( 'Active connections', $html, 'an unknown tab falls back to Connections' );
	}

	public function test_ap2_connections_show_access_in_words_and_use(): void {
		wp_set_current_user( self::$author );
		$html = self::page( array() );
		$this->assertStringContainsString( 'Claude (screen test)', $html );
		$this->assertStringContainsString( 'Read content and settings', $html );
		$this->assertStringContainsString( 'Create and edit content', $html );
		$this->assertStringContainsString( '3 calls in 7 days', $html );
		$this->assertStringContainsString( 'Full Site Administration', $html, 'the access levels are explained' );
		$this->assertStringContainsString( 'client=https%3A%2F%2Fclaude.ai%2Foauth%2Fkuduy-admin-screens-test.json', $html, 'the activity link encodes the client id' );
	}

	public function test_ap3_activity_filters_and_visibility(): void {
		$risky = ActivityTab::high_risk();
		$this->assertContains( 'kuduy-plugin-delete', $risky );
		$this->assertContains( 'kuduy-post-update', $risky, 'destructive tools count as high-risk' );
		$this->assertNotContains( 'kuduy-post-get', $risky );
		$this->assertNotContains( 'kuduy-dev-file-read', $risky, 'reading with admin access is not high-risk' );

		wp_set_current_user( self::$author );
		$html = self::page( array( 'tab' => 'activity' ) );
		$this->assertStringContainsString( 'kuduy-post-get', $html );
		$this->assertStringNotContainsString( 'kuduy-integrations', $html, 'authors see only their own calls' );
		$this->assertStringContainsString( 'Claude (screen test)', $html, 'the app is shown by its registered name' );

		$html = self::page(
			array(
				'tab'  => 'activity',
				'risk' => '1',
			)
		);
		$this->assertStringContainsString( 'kuduy-plugin-delete', $html );
		$this->assertStringContainsString( 'kuduy-post-update', $html );
		$this->assertStringNotContainsString( '<code>kuduy-post-get</code>', $html );

		$html = self::page(
			array(
				'tab'    => 'activity',
				'result' => 'denied',
			)
		);
		$this->assertStringContainsString( 'kuduy-plugin-delete', $html );
		$this->assertStringNotContainsString( '<code>kuduy-post-update</code>', $html );

		wp_set_current_user( self::$admin );
		$html = self::page(
			array(
				'tab'    => 'activity',
				'client' => self::CLIENT,
			)
		);
		$this->assertStringContainsString( 'kuduy-post-update', $html );
		$this->assertStringNotContainsString( '<code>kuduy-integrations</code>', $html, 'filtered to one connection' );
		$this->assertStringContainsString( '<code>kuduy-integrations</code>', self::page( array( 'tab' => 'activity' ) ), 'site managers see everyone' );
	}

	public function test_ap4_integrations_tab(): void {
		wp_set_current_user( self::$admin );
		$installed = self::page( array( 'tab' => 'integrations' ) );
		$this->assertStringContainsString( 'Last check by Claude', $installed );
		$this->assertStringContainsString( 'Show the whole catalogue', $installed );
		$all = self::page(
			array(
				'tab' => 'integrations',
				'all' => '1',
			)
		);
		$this->assertStringContainsString( 'data-id="bricks"', $all, 'the whole catalogue, installed or not' );
		$this->assertStringContainsString( 'not installed', $all );
		$this->assertStringNotContainsString( 'data-id="bricks"', $installed, 'by default only what is installed here' );
	}
}
