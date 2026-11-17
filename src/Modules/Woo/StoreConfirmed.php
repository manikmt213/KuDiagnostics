<?php
/**
 * Tools: kuduy-woo-product-delete, kuduy-woo-attribute-delete, kuduy-woo-refund-create.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * WooCommerce changes that cannot be undone (decision D46), each in two steps with a confirmation token (D45): the
 * first call previews, the second runs. Through WooCommerce itself: WC_Product::delete( true ), wc_delete_attribute()
 * and wc_create_refund() (WooCommerce 11.2.2; it refuses amounts above what is left to refund).
 */
final class StoreConfirmed implements Tool, Confirmable {

	/**
	 * Mode.
	 *
	 * @param string $mode product-delete | attribute-delete | refund-create.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'product-delete'   => __( 'Delete a product permanently', 'kuduy-mcp' ),
			'attribute-delete' => __( 'Delete a product attribute', 'kuduy-mcp' ),
			default            => __( 'Refund an order', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$two = ' Two steps: the first call (no confirm_token) changes nothing and returns a preview with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token.';
		return match ( $this->mode ) {
			'product-delete'   => 'Permanently deletes a WooCommerce product and its variations (no trash; to keep it restorable use kuduy-woo-product-trash). Past orders keep their line items but lose the link to the product. Cannot be undone.' . $two,
			'attribute-delete' => 'Deletes a global product attribute (e.g. Colour) with all its terms; products that used it lose that attribute and variations based on it stop matching. Cannot be undone.' . $two,
			default            => 'Refunds a WooCommerce order through WooCommerce, as the order screen\'s Refund button does: amount (up to what is left to refund), reason, optional line_items (order item id => qty and refund_total) and restock_items. refund_payment: true asks the payment gateway to send the money back automatically, only for gateways that support it; otherwise the refund is recorded in WooCommerce and the customer must be paid back another way. The preview says which of the two will happen, the remaining amount and the gateway. Cannot be undone.' . $two,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id                              = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties                      = 'refund-create' === $this->mode ? array(
			'id'             => $id,
			'amount'         => array(
				'type'        => 'string',
				'pattern'     => '^[0-9]+(\.[0-9]{1,4})?$',
				'description' => 'Amount as a decimal string, e.g. "12.50".',
			),
			'reason'         => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
			'line_items'     => array(
				'type'                 => 'object',
				'description'          => 'Order item id => {"qty": 1, "refund_total": "10.00"}; item ids from kuduy-woo-order-get.',
				'additionalProperties' => array(
					'type'                 => 'object',
					'properties'           => array(
						'qty'          => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'refund_total' => array(
							'type'    => 'string',
							'pattern' => '^[0-9]+(\.[0-9]{1,4})?$',
						),
					),
					'additionalProperties' => false,
				),
			),
			'restock_items'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'refund_payment' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		) : array( 'id' => $id );
		$properties[ Confirmation::ARG ] = Confirmation::token_input();
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'refund-create' === $this->mode ? array( 'id', 'amount' ) : array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'id'        => array( 'type' => 'integer' ),
				'deleted'   => array( 'type' => 'boolean' ),
				'refund_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => 'refund-create' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return match ( $this->mode ) {
			'product-delete'   => $id > 0 ? array( 'delete_post', $id ) : 'delete_products',
			'attribute-delete' => 'manage_product_terms',
			default            => 'edit_shop_orders',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the object is unknown or the request cannot be done.
	 */
	public function preview( array $input ): array {
		return match ( $this->mode ) {
			'product-delete'   => self::product_preview( (int) $input['id'] ),
			'attribute-delete' => self::attribute_preview( (int) $input['id'] ),
			default            => self::refund_preview( $input ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		$id = (int) $input['id'];
		switch ( $this->mode ) {
			case 'product-delete':
				$product = Woo::product( $id );
				AuditLog::target( 'post', (string) $id );
				if ( ! $product->delete( true ) ) {
					throw new ToolError( 'WooCommerce did not delete this product.' );
				}
				return array(
					'id'      => $id,
					'deleted' => true,
				);
			case 'attribute-delete':
				AuditLog::target( 'woo_attribute', (string) $id );
				if ( ! wc_delete_attribute( $id ) ) {
					throw new ToolError( 'WooCommerce did not delete this attribute.' );
				}
				return array(
					'id'      => $id,
					'deleted' => true,
				);
			default:
				AuditLog::target( 'order', (string) $id );
				$refund = wc_create_refund(
					array(
						'order_id'       => $id,
						'amount'         => (string) $input['amount'],
						'reason'         => isset( $input['reason'] ) ? (string) $input['reason'] : null,
						'line_items'     => self::line_items( (array) ( $input['line_items'] ?? array() ) ),
						'restock_items'  => ! empty( $input['restock_items'] ),
						'refund_payment' => ! empty( $input['refund_payment'] ),
					)
				);
				if ( is_wp_error( $refund ) ) {
					throw ToolError::of( ToolError::OPERATION_FAILED, 'WooCommerce did not refund: ' . wp_strip_all_tags( $refund->get_error_message() ) );
				}
				$order = Woo::order( $id );
				return array(
					'id'        => $id,
					'refund_id' => $refund->get_id(),
					'amount'    => (string) $refund->get_amount(),
					'remaining' => (string) $order->get_remaining_refund_amount(),
					'status'    => $order->get_status(),
				);
		}//end switch
	}

	/**
	 * Preview of a permanent product delete.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>
	 */
	private static function product_preview( int $id ): array {
		$product = Woo::product( $id );
		Woo::assert_product_permission( 'delete', $id );
		return array(
			'id'         => $id,
			'name'       => $product->get_name(),
			'sku'        => $product->get_sku(),
			'type'       => $product->get_type(),
			'status'     => $product->get_status(),
			'variations' => count( $product->get_children() ),
			'warning'    => 'The product' . ( $product->get_children() ? ' and its ' . count( $product->get_children() ) . ' variations are' : ' is' ) . ' deleted for good; it cannot be restored. kuduy-woo-product-trash keeps it restorable.',
			'version'    => md5( (string) get_post_field( 'post_modified_gmt', $id ) . '|' . implode( ',', $product->get_children() ) ),
		);
	}

	/**
	 * Preview of an attribute delete.
	 *
	 * @param int $id Attribute id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such attribute.
	 */
	private static function attribute_preview( int $id ): array {
		$attribute = wc_get_attribute( $id );
		if ( null === $attribute ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No product attribute with id ' . $id . ' (see kuduy-woo-attributes).' );
		}
		$taxonomy = (string) $attribute->slug;
		$terms    = taxonomy_exists( $taxonomy ) ? get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		) : array();
		$terms    = is_array( $terms ) ? $terms : array();
		$used     = 0;
		foreach ( $terms as $term ) {
			$used += (int) $term->count;
		}
		return array(
			'id'       => $id,
			'name'     => (string) $attribute->name,
			'taxonomy' => $taxonomy,
			'terms'    => count( $terms ),
			'uses'     => $used,
			'warning'  => 'The attribute and its ' . count( $terms ) . ' terms are deleted for good' . ( $used ? '; ' . $used . ' product assignments lose it and variations built on it stop matching' : '' ) . '. Cannot be undone.',
			'version'  => md5( count( $terms ) . '|' . $used . '|' . $attribute->name ),
		);
	}

	/**
	 * Line items in wc_create_refund()'s shape.
	 *
	 * @param array<string|int, mixed> $items Item id => qty, refund_total.
	 * @return array<int, array<string, mixed>>
	 */
	private static function line_items( array $items ): array {
		$out = array();
		foreach ( $items as $item_id => $item ) {
			$item                  = (array) $item;
			$out[ (int) $item_id ] = array(
				'qty'          => (int) ( $item['qty'] ?? 0 ),
				'refund_total' => (string) ( $item['refund_total'] ?? '0' ),
			);
		}
		return $out;
	}

	/**
	 * Preview of a refund.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the refund cannot be done as asked.
	 */
	private static function refund_preview( array $input ): array {
		$id    = (int) $input['id'];
		$order = Woo::order( $id );
		Woo::assert_order_permission( 'edit', $id );
		$amount    = (float) $input['amount'];
		$remaining = (float) $order->get_remaining_refund_amount();
		if ( $amount <= 0 || $amount > $remaining + 0.00001 ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The amount must be more than 0 and at most what is left to refund (' . wc_format_decimal( (string) $remaining ) . ' ' . $order->get_currency() . ').' );
		}
		$items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		foreach ( array_keys( (array) ( $input['line_items'] ?? array() ) ) as $item_id ) {
			if ( ! isset( $items[ (int) $item_id ] ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Order ' . $id . ' has no item ' . (int) $item_id . ' (see kuduy-woo-order-get).' );
			}
		}
		$method   = $order->get_payment_method();
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $method ] ?? null;
		$auto     = $gateway instanceof \WC_Payment_Gateway && $gateway->supports( 'refunds' );
		if ( ! empty( $input['refund_payment'] ) && ! $auto ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'The payment method of this order (' . ( '' === $method ? 'none' : $method ) . ') cannot send refunds automatically. Leave refund_payment off to record the refund, and pay the customer back another way.' );
		}
		$money = ! empty( $input['refund_payment'] )
			? 'The payment gateway (' . $order->get_payment_method_title() . ') will be asked to send ' . $input['amount'] . ' ' . $order->get_currency() . ' back to the customer.'
			: 'The refund is recorded in WooCommerce only; no money is sent. Pay the customer back yourself.' . ( $auto ? ' (This gateway could send it automatically with refund_payment: true.)' : '' );
		return array(
			'id'             => $id,
			'order_number'   => $order->get_order_number(),
			'currency'       => $order->get_currency(),
			'order_total'    => (string) $order->get_total(),
			'refunded'       => (string) $order->get_total_refunded(),
			'remaining'      => (string) $remaining,
			'amount'         => (string) $input['amount'],
			'payment_method' => $order->get_payment_method_title(),
			'money'          => $money,
			'restock_items'  => ! empty( $input['restock_items'] ),
			'warning'        => 'A refund cannot be undone.',
			'version'        => md5( $remaining . '|' . count( $order->get_refunds() ) . '|' . $order->get_status() ),
		);
	}
}
