<?php
/**
 * Kadence Shop Kit (test matrix SK1, phase L8): module switches read from its option (JSON form), editors refused.
 * Runs against the stand-in (KUDUY_FAKES=shop-kit).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ShopKitTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'KADENCE_WOO_EXTRAS_PATH' ) ) {
			self::markTestSkipped( 'Kadence Shop Kit (or its stand-in) is not loaded (KUDUY_FAKES=shop-kit).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	public function test_sk1_modules(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$on = array_column( $this->ok( 'kuduy-shop-kit-modules' )['modules'], 'on', 'module' );
		$this->assertCount( 16, $on );
		$this->assertTrue( $on['variation_swatches'] );
		$this->assertTrue( $on['product_quickview'] );
		$this->assertFalse( $on['kt_reviews'] );
		$this->assertFalse( $on['size_charts'] );
		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-shop-kit-modules', array(), 'site managers only' );
	}
}
