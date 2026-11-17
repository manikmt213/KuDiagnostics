<?php
/**
 * Tool: kuduy-woo-order-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Changes an order's billing and shipping address fields and the customer's note (phase G1) through WC_Order
 * setters and save(), as the order screen does; works with order storage (HPOS) on and off. Status and notes keep
 * their own tools (emails, stock). Addresses are personal data: masked in results unless include_pii and never
 * written to the audit log (D12).
 */
final class OrderUpdate implements Tool, Snapshottable, MasksAuditArgs {

	/**
	 * Address fields WooCommerce stores per address type.
	 */
	private const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-order-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Change an order\'s addresses', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Changes a WooCommerce order\'s billing and/or shipping address fields (only those you send: first_name, last_name, company, address_1, address_2, city, state, postcode, country, phone, and billing email) and the customer\'s note. Does not change status, items or totals (status: kuduy-woo-order-status; refunds: kuduy-woo-refund-create). No email is sent. The result shows names and country only unless include_pii is true. Addresses are not written to the audit log. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$address = static function ( bool $billing ): array {
			$properties = array();
			foreach ( self::FIELDS as $field ) {
				$properties[ $field ] = array(
					'type'      => 'string',
					'maxLength' => 'country' === $field ? 2 : 200,
				);
			}
			if ( $billing ) {
				$properties['email'] = array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 200,
				);
			}
			return array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			);
		};
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'billing'       => $address( true ),
				'shipping'      => $address( false ),
				'customer_note' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'include_pii'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'billing'      => array( 'type' => 'object' ),
				'shipping'     => array( 'type' => 'object' ),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'changed' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_shop_orders';
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
	 * @param array<string, mixed> $args Arguments.
	 */
	public function audit_args( array $args ): array {
		foreach ( array( 'billing', 'shipping', 'customer_note' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$args[ $key ] = is_array( $args[ $key ] ) ? array_fill_keys( array_keys( $args[ $key ] ), Pii::MASK ) : Pii::MASK;
			}
		}
		return $args;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$order = wc_get_order( (int) $input['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		return array(
			'object_type' => 'order',
			'object_id'   => (string) $order->get_id(),
			'payload'     => array(
				'id'            => $order->get_id(),
				'billing'       => $order->get_address( 'billing' ),
				'shipping'      => $order->get_address( 'shipping' ),
				'customer_note' => $order->get_customer_note( 'edit' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$order = Woo::order( (int) $payload['id'] );
		self::apply( $order, $payload );
		$order->save();
	}

	/**
	 * Set the given fields on an order.
	 *
	 * @param \WC_Order            $order  Order.
	 * @param array<string, mixed> $fields billing, shipping, customer_note.
	 * @return list<string> Names of the fields set.
	 * @throws ToolError When WooCommerce refuses a value.
	 */
	private static function apply( \WC_Order $order, array $fields ): array {
		$changed = array();
		try {
			foreach ( array( 'billing', 'shipping' ) as $type ) {
				foreach ( (array) ( $fields[ $type ] ?? array() ) as $key => $value ) {
					$setter = 'set_' . $type . '_' . $key;
					if ( method_exists( $order, $setter ) ) {
						$order->$setter( (string) $value );
						$changed[] = $type . '.' . $key;
					}
				}
			}
			if ( array_key_exists( 'customer_note', $fields ) ) {
				$order->set_customer_note( (string) $fields['customer_note'] );
				$changed[] = 'customer_note';
			}
		} catch ( \WC_Data_Exception $e ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'WooCommerce refused the value: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was changed.' );
		}
		return $changed;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the order is unknown or nothing is given.
	 */
	public function execute( array $input ): array {
		$order = Woo::order( (int) $input['id'] );
		Woo::assert_order_permission( 'edit', $order->get_id() );
		AuditLog::target( 'order', (string) $order->get_id() );
		$changed = self::apply( $order, $input );
		if ( ! $changed ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give billing, shipping and/or customer_note fields to change.' );
		}
		$order->save();
		$full = ! empty( $input['include_pii'] );
		return array(
			'id'       => $order->get_id(),
			'changed'  => $changed,
			'billing'  => (object) Pii::address( $order->get_address( 'billing' ), $full ),
			'shipping' => (object) Pii::address( $order->get_address( 'shipping' ), $full ),
		);
	}
}
