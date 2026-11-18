<?php
/**
 * Redirects of the Pro SEO plugins (test matrix SR1–SR3, phase L2, D59): Yoast SEO Premium, SEOPress PRO and All in
 * One SEO Pro through each plugin's own path — create, read back, list and search, change and undo, delete and undo,
 * 410 without a target, switching off, refusals (duplicate source, missing target, loop, bad regex, editor), Yoast's
 * warnings. The plugins are paid: this runs against stand-ins built from their source
 * (KUDUY_FAKES=yoast-premium,seopress-pro,aioseo-pro), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SeoRedirectsProTest extends TestCase {

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
		wp_set_current_user( self::$admin );
	}

	/**
	 * The shared scenario for one plugin.
	 *
	 * @param string $p          Tool prefix.
	 * @param bool   $can_switch Whether the plugin can switch a redirect off.
	 */
	private function scenario( string $p, bool $can_switch ): void {
		$made = $this->ok(
			"kuduy-$p-redirect-save",
			array(
				'source' => '/kuduy-old-' . $p . '/',
				'target' => home_url( '/kuduy-new/' ),
			)
		);
		$row  = $made['redirect'];
		$id   = (string) $row['id'];
		$this->assertSame( 'kuduy-old-' . $p, trim( $row['source'], '/' ) );
		$this->assertSame( 301, $row['type'] );
		$this->assertTrue( $row['enabled'] );
		$this->assertArrayNotHasKey( '_snapshot_id', $made, 'A new redirect has no snapshot.' );

		$list = $this->ok( "kuduy-$p-redirects", array( 'search' => 'kuduy-old-' . $p ) );
		$this->assertContains( $id, array_column( $list['redirects'], 'id' ) );

		$this->fails(
			"kuduy-$p-redirect-save",
			array(
				'source' => 'kuduy-old-' . $p,
				'target' => '/elsewhere/',
			),
			'the source is already used'
		);
		$this->fails( "kuduy-$p-redirect-save", array( 'source' => '/kuduy-no-target/' ), 'a 301 needs a target' );
		$this->fails(
			"kuduy-$p-redirect-save",
			array(
				'source' => '/kuduy-loop/',
				'target' => '/kuduy-loop',
			),
			'target equals source'
		);
		$this->fails(
			"kuduy-$p-redirect-save",
			array(
				'source' => '^/kuduy-(unclosed',
				'target' => '/x/',
				'regex'  => true,
			),
			'the pattern does not compile'
		);

		$changed = $this->ok(
			"kuduy-$p-redirect-save",
			array(
				'id'     => $id,
				'type'   => 302,
				'target' => home_url( '/kuduy-newer/' ),
			)
		);
		$this->assertSame( 302, $changed['redirect']['type'] );
		$this->assertStringContainsString( 'kuduy-newer', $changed['redirect']['target'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$back = $this->ok( "kuduy-$p-redirects", array( 'search' => 'kuduy-old-' . $p ) )['redirects'][0];
		$this->assertSame( 301, $back['type'], 'Undo puts the type back.' );
		$this->assertStringContainsString( 'kuduy-new/', $back['target'] );

		$gone = $this->ok(
			"kuduy-$p-redirect-save",
			array(
				'source' => '/kuduy-gone-' . $p . '/',
				'type'   => 410,
			)
		)['redirect'];
		$this->assertSame( 410, $gone['type'], '410 needs no target.' );

		if ( $can_switch ) {
			$off = $this->ok(
				"kuduy-$p-redirect-save",
				array(
					'id'      => $id,
					'enabled' => false,
				)
			);
			$this->assertFalse( $off['redirect']['enabled'] );
			$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		} else {
			$this->fails(
				"kuduy-$p-redirect-save",
				array(
					'id'      => $id,
					'enabled' => false,
				),
				'the plugin has no off switch'
			);
		}

		$deleted = $this->ok( "kuduy-$p-redirect-delete", array( 'id' => $id ) );
		$this->assertSame( $id, $deleted['deleted'] );
		$this->assertNotContains( 'kuduy-old-' . $p, array_map( static fn( $r ) => trim( $r['source'], '/' ), $this->ok( "kuduy-$p-redirects" )['redirects'] ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$again = $this->ok( "kuduy-$p-redirects", array( 'search' => 'kuduy-old-' . $p ) )['redirects'];
		$this->assertCount( 1, $again, 'Undo creates the deleted redirect again.' );
		$this->assertSame( 301, $again[0]['type'] );
		$this->assertTrue( $again[0]['enabled'] );

		$this->fails( "kuduy-$p-redirect-delete", array( 'id' => '999999' ), 'no such redirect' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			"kuduy-$p-redirect-save",
			array(
				'source' => '/kuduy-editor/',
				'target' => '/x/',
			),
			'editors may not manage redirects'
		);
		wp_set_current_user( self::$admin );
	}

	public function test_sr1_yoast_premium(): void {
		if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$this->markTestSkipped( 'Yoast SEO Premium (or its stand-in) is not loaded (KUDUY_FAKES=yoast-premium).' );
		}
		$this->scenario( 'yoast', false );
		$warned = $this->ok(
			'kuduy-yoast-redirect-save',
			array(
				'source' => '/kuduy-warned/',
				'target' => 'https://example.invalid/page',
			)
		);
		$this->assertNotEmpty( $warned['warnings'], 'Yoast\'s warning is returned and the redirect saved.' );
		$this->fails(
			'kuduy-yoast-redirect-save',
			array(
				'id'     => $warned['redirect']['id'],
				'source' => '/kuduy-renamed/',
			),
			'Yoast identifies a redirect by its source'
		);
	}

	public function test_sr2_seopress_pro(): void {
		if ( ! defined( 'SEOPRESS_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'SEOPress PRO (or its stand-in) is not loaded (KUDUY_FAKES=seopress-pro).' );
		}
		$this->scenario( 'seopress', true );
		$this->assertSame( 'publish', get_post_status( (int) $this->ok( 'kuduy-seopress-redirects', array( 'search' => 'kuduy-gone-seopress' ) )['redirects'][0]['id'] ), 'Stored as a seopress_404 post.' );
	}

	public function test_sr3_aioseo_pro(): void {
		if ( ! function_exists( 'aioseo' ) || empty( aioseo()->redirects ) ) {
			$this->markTestSkipped( 'All in One SEO Pro (or its stand-in) is not loaded (KUDUY_FAKES=aioseo-pro).' );
		}
		$this->scenario( 'aioseo', true );
	}
}
