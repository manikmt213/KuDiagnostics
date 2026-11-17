<?php
/**
 * WooCommerce phase G1 (test matrix WG1–WG10), run with HPOS on and off: duplicate, restore, permanent delete with
 * confirmation, default attributes, variation delete and generate, global attributes, shipping classes, refunds
 * with confirmation, order address update, store settings.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooCatalogTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $manager;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin   = self::make_user( 'administrator' );
		self::$manager = self::make_user( 'shop_manager' );
		self::$editor  = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A published simple product.
	 *
	 * @param string $sku SKU.
	 */
	private static function simple( string $sku = '' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kuduy catalogue item' );
		$product->set_regular_price( '20' );
		$product->set_sku( '' === $sku ? 'KD-' . wp_generate_password( 6, false ) : $sku );
		$product->set_status( 'publish' );
		return $product->save();
	}

	/**
	 * A variable product with a custom size attribute used for variations.
	 *
	 * @param list<string> $sizes Options.
	 */
	private static function variable( array $sizes = array( 'S', 'M', 'L' ) ): int {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $sizes );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product = new \WC_Product_Variable();
		$product->set_name( 'Kuduy shirt' );
		$product->set_attributes( array( $attribute ) );
		$product->set_status( 'publish' );
		return $product->save();
	}

	/**
	 * A product that must exist.
	 *
	 * @param int $id Product id.
	 */
	private function product( int $id ): \WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		return $product;
	}

	/**
	 * An order that must exist.
	 *
	 * @param int $id Order id.
	 */
	private function order( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		return $order;
	}

	/**
	 * Call a confirmable tool through both steps.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} Preview result and final result.
	 */
	private function confirmed( string $tool, array $args ): array {
		$preview = $this->ok( $tool, $args );
		$this->assertTrue( $preview['confirmation_required'], $tool . ' previews first' );
		return array( $preview, $this->ok( $tool, $args + array( 'confirm_token' => $preview['confirm_token'] ) ) );
	}

	public function test_duplicate_restore_and_delete(): void {
		$id   = self::simple( 'KD-DUP-1' );
		$copy = $this->ok( 'kuduy-woo-product-duplicate', array( 'id' => $id ) );
		$this->assertNotSame( $id, $copy['id'] );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertStringContainsString( '(Copy)', $copy['name'] );
		$this->assertNotSame( 'KD-DUP-1', $this->product( $copy['id'] )->get_sku(), 'WooCommerce gives the copy its own SKU.' );

		$this->ok( 'kuduy-woo-product-trash', array( 'id' => $id ) );
		$restored = $this->ok( 'kuduy-woo-product-restore', array( 'id' => $id ) );
		$this->assertNotSame( 'trash', get_post_status( $id ) );
		$this->fails( 'kuduy-woo-product-restore', array( 'id' => $id ), 'Only products in the trash are restored' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $restored['_snapshot_id'] ) );
		$this->assertSame( 'trash', get_post_status( $id ), 'Undo puts it back in the trash.' );

		[ $preview, $done ] = $this->confirmed( 'kuduy-woo-product-delete', array( 'id' => $copy['id'] ) );
		$this->assertSame( $copy['id'], $preview['preview']['id'] );
		$this->assertStringContainsString( 'cannot be restored', $preview['preview']['warning'] );
		$this->assertTrue( $done['deleted'] );
		$this->assertNull( get_post( $copy['id'] ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-woo-product-delete', array( 'id' => $id ), 'Editors cannot delete products' );
	}

	public function test_variations_generate_delete_and_defaults(): void {
		$id   = self::variable();
		$made = $this->ok( 'kuduy-woo-variations-generate', array( 'id' => $id ) );
		$this->assertCount( 3, $made['created'] );
		$again = $this->ok( 'kuduy-woo-variations-generate', array( 'id' => $id ) );
		$this->assertSame( array(), $again['created'], 'Existing combinations are not made twice.' );

		$undo = $this->ok( 'kuduy-woo-variation-delete', array( 'id' => $made['created'][0] ) );
		$this->assertCount( 2, $this->product( $id )->get_children() );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $undo['_snapshot_id'] ) );
		$this->assertCount( 3, $this->product( $id )->get_children(), 'Undo creates the variation again.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $made['_snapshot_id'] ) );
		$this->assertCount( 0, $this->product( $id )->get_children(), 'Undo of generate deletes what it made.' );

		$this->ok(
			'kuduy-woo-product-update',
			array(
				'id'                 => $id,
				'default_attributes' => array( 'size' => 'M' ),
			)
		);
		$this->assertSame( array( 'size' => 'M' ), $this->product( $id )->get_default_attributes() );
		$this->assertSame( array( 'size' => 'M' ), $this->ok( 'kuduy-woo-product-get', array( 'id' => $id ) )['default_attributes'] );

		$this->fails( 'kuduy-woo-variations-generate', array( 'id' => self::simple() ), 'Simple products have no variations' );
	}

	public function test_global_attributes(): void {
		$saved = $this->ok(
			'kuduy-woo-attribute-save',
			array(
				'name' => 'Kuduy Material',
				'slug' => 'kd-material',
			)
		);
		$this->assertSame( 'kd-material', $saved['slug'] );
		$this->assertContains( 'kd-material', array_column( $this->ok( 'kuduy-woo-attributes' )['attributes'], 'slug' ) );
		$this->fails( 'kuduy-woo-attribute-save', array( 'name' => 'Again', 'slug' => 'kd-material' ), 'Duplicate slugs are refused by WooCommerce' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.

		$renamed = $this->ok(
			'kuduy-woo-attribute-save',
			array(
				'id'   => $saved['id'],
				'name' => 'Kuduy Fabric',
			)
		);
		$this->assertSame( 'Kuduy Fabric', $renamed['name'] );
		$this->assertSame( 'kd-material', $renamed['slug'], 'Fields not given stay.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $renamed['_snapshot_id'] ) );
		$this->assertSame( 'Kuduy Material', wc_get_attribute( $saved['id'] )->name );

		[ $preview ] = $this->confirmed( 'kuduy-woo-attribute-delete', array( 'id' => $saved['id'] ) );
		$this->assertSame( 'pa_kd-material', $preview['preview']['taxonomy'] );
		$this->assertNull( wc_get_attribute( $saved['id'] ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-woo-attribute-save', array( 'name' => 'Nope' ), 'Editors cannot manage attributes' );
	}

	public function test_shipping_classes(): void {
		$class   = $this->ok(
			'kuduy-woo-shipping-class-save',
			array(
				'name'        => 'Kuduy Bulky',
				'description' => 'Big parcels',
			)
		);
		$product = self::simple();
		$this->ok(
			'kuduy-woo-product-update',
			array(
				'id'                => $product,
				'shipping_class_id' => $class['id'],
			)
		);
		$listed = array_column( $this->ok( 'kuduy-woo-shipping-classes' )['classes'], 'products', 'id' );
		$this->assertSame( 1, $listed[ $class['id'] ] );

		$deleted = $this->ok( 'kuduy-woo-shipping-class-delete', array( 'id' => $class['id'] ) );
		$this->assertSame( 0, $this->product( $product )->get_shipping_class_id() );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$back = $this->product( $product )->get_shipping_class_id();
		$this->assertGreaterThan( 0, $back, 'Undo recreates the class and puts it back on the product.' );
		$this->assertSame( 'Kuduy Bulky', get_term( $back, 'product_shipping_class' )->name );
		$this->fails( 'kuduy-woo-shipping-class-save', array( 'name' => 'Kuduy Bulky' ), 'A second class with the same name is refused' );
	}

	public function test_refund_with_confirmation(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( $this->product( self::simple() ), 2 );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$id = $order->get_id();

		$this->fails(
			'kuduy-woo-refund-create',
			array(
				'id'     => $id,
				'amount' => '999',
			),
			'More than the order total is refused'
		);
		$this->fails(
			'kuduy-woo-refund-create',
			array(
				'id'             => $id,
				'amount'         => '5',
				'refund_payment' => true,
			),
			'Cash on delivery cannot send money back automatically'
		);
		[ $preview, $done ] = $this->confirmed(
			'kuduy-woo-refund-create',
			array(
				'id'     => $id,
				'amount' => '5.00',
				'reason' => 'Damaged box',
			)
		);
		$this->assertStringContainsString( 'no money is sent', $preview['preview']['money'] );
		$this->assertSame( '40', $preview['preview']['remaining'] );
		$this->assertGreaterThan( 0, $done['refund_id'] );
		$this->assertEqualsWithDelta( 5.0, (float) $this->order( $id )->get_total_refunded(), 0.001 );

		// A second refund changes the order, so a token from before it no longer fits.
		$stale = $this->ok(
			'kuduy-woo-refund-create',
			array(
				'id'     => $id,
				'amount' => '1',
			)
		);
		wc_create_refund(
			array(
				'order_id' => $id,
				'amount'   => '1',
			)
		);
		$result = $this->call(
			'kuduy-woo-refund-create',
			array(
				'id'            => $id,
				'amount'        => '1',
				'confirm_token' => $stale['confirm_token'],
			)
		);
		$this->assertTrue( $result['isError'] );
		$this->assertSame( ToolError::CONFLICT, $result['_meta']['kuduy/error']['code'] );
		$this->assertEqualsWithDelta( 6.0, (float) $this->order( $id )->get_total_refunded(), 0.001, 'The stale token refunded nothing.' );

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-woo-refund-create',
			array(
				'id'     => $id,
				'amount' => '1',
			),
			'Editors cannot refund'
		);
	}

	public function test_order_address_update(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_city( 'Berlin' );
		$order->set_billing_email( 'jane@example.test' );
		$order->save();
		$id     = $order->get_id();
		$result = $this->ok(
			'kuduy-woo-order-update',
			array(
				'id'            => $id,
				'billing'       => array(
					'city'  => 'Hamburg',
					'email' => 'jane.new@example.test',
				),
				'customer_note' => 'Leave at the door',
			)
		);
		$this->assertSame( array( 'billing.city', 'billing.email', 'customer_note' ), $result['changed'] );
		$this->assertArrayNotHasKey( 'city', $result['billing'], 'Addresses are masked without include_pii.' );
		$this->assertSame( 'Hamburg', $this->order( $id )->get_billing_city() );
		global $wpdb;
		$row = (string) $wpdb->get_var( "SELECT args_redacted FROM {$wpdb->prefix}kuduy_audit_log WHERE tool = 'kuduy-woo-order-update' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
		$this->assertStringNotContainsString( 'Hamburg', $row, 'Addresses are not written to the audit log.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertSame( 'Berlin', $this->order( $id )->get_billing_city() );
	}

	public function test_store_settings(): void {
		$general = array_column( $this->ok( 'kuduy-woo-settings', array( 'group' => 'general' ) )['settings'], null, 'id' );
		$this->assertArrayHasKey( 'woocommerce_currency', $general );
		$this->assertArrayHasKey( 'options', $general['woocommerce_currency'] );
		$before = get_option( 'woocommerce_price_num_decimals' );

		wp_set_current_user( self::$manager );
		$result = $this->ok(
			'kuduy-woo-settings-update',
			array(
				'group'    => 'general',
				'settings' => array( 'woocommerce_price_num_decimals' => '3' ),
			)
		);
		$this->assertSame( array( 'woocommerce_price_num_decimals' ), $result['changed'] );
		$this->assertSame( '3', (string) get_option( 'woocommerce_price_num_decimals' ) );
		$this->fails(
			'kuduy-woo-settings-update',
			array(
				'group'    => 'general',
				'settings' => array( 'blogname' => 'x' ),
			),
			'Settings outside the group are refused'
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertSame( (string) $before, (string) get_option( 'woocommerce_price_num_decimals' ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-woo-settings', array( 'group' => 'general' ), 'Editors do not manage the store' );
	}
}
