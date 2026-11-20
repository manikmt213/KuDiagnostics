<?php
/**
 * Phase O with WooCommerce (test matrix AO9): permanent delete leaves WooCommerce items to WooCommerce; user delete
 * says what WooCommerce does to the customer's orders and data. Runs in the WooCommerce rows (HPOS on and off).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooAdminTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
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

	/**
	 * AO9: a trashed product is refused by kuduy-post-delete (WooCommerce deletes it through its own CRUD).
	 */
	public function test_ao9_trashed_product_is_left_to_woocommerce(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kuduy delete-refused product' );
		$id = $product->save();
		wp_trash_post( $id );
		$message = $this->fails( 'kuduy-post-delete', array( 'id' => $id ), 'a WooCommerce product is not deleted by the core tool' );
		$this->assertStringContainsString( 'kuduy-woo-product-delete', $message );
		$this->assertInstanceOf( \WP_Post::class, get_post( $id ), 'Nothing was deleted.' );
		wc_get_product( $id )->delete( true );
	}

	/**
	 * AO9: user delete on a WooCommerce site reports the customer's orders, and WooCommerce keeps them as guest orders.
	 */
	public function test_ao9_customer_delete_reports_orders(): void {
		$customer = self::make_user( 'customer' );
		$order    = wc_create_order( array( 'customer_id' => $customer ) );
		$order->save();
		$preview = $this->ok(
			'kuduy-user-delete',
			array(
				'user_id'     => $customer,
				'reassign_to' => self::$admin,
			)
		);
		$this->assertSame( 1, $preview['preview']['woocommerce']['orders'] );
		$this->assertStringContainsString( 'guest orders', $preview['preview']['woocommerce']['effect'] );
		$done = $this->ok(
			'kuduy-user-delete',
			array(
				'user_id'       => $customer,
				'reassign_to'   => self::$admin,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertTrue( $done['deleted'] );
		$kept = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $kept, 'The order stays.' );
		$this->assertSame( 0, $kept->get_customer_id(), 'WooCommerce turned it into a guest order, as the preview said.' );
		$kept->delete( true );
	}
}
