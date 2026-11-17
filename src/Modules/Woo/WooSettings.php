<?php
/**
 * Tools: kuduy-woo-settings, kuduy-woo-settings-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Blocks\CoreRest;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * WooCommerce store settings (phase G1) through WooCommerce's own settings REST controller, in process: the same
 * definitions, types, options and validation as WooCommerce > Settings (WooCommerce 11.2.2:
 * `wc/v3/settings/{group}` and `…/{group}/batch`, permission manage_woocommerce). Groups: general (address,
 * currency, selling locations), products (shop page, measurements, reviews, stock), account (accounts and privacy),
 * email (sender, email templates' options), site-visibility (coming soon). Tax, shipping and payment methods have
 * their own tools; integrations and advanced settings (pages, REST keys, webhooks) are left to WooCommerce.
 */
final class WooSettings implements Tool, Snapshottable {

	/**
	 * Settings groups handled here.
	 */
	public const GROUPS = array( 'general', 'products', 'account', 'email', 'site-visibility' );

	/**
	 * Mode.
	 *
	 * @param string $mode get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get' === $this->mode ? 'kuduy-woo-settings' : 'kuduy-woo-settings-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'get' === $this->mode ? __( 'WooCommerce settings', 'kuduy-mcp' ) : __( 'Change WooCommerce settings', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'get' === $this->mode
			? 'Returns one group of WooCommerce settings as WooCommerce > Settings defines them: general (store address, selling and shipping locations, currency and price format), products (shop page, weight and size units, reviews, stock), account (accounts and privacy), email (sender name and address, per-email options), site-visibility (coming soon / live). For each: id, label, description, type, value, default and allowed options. Tax, shipping and payment methods: kuduy-woo-tax, kuduy-woo-shipping, kuduy-woo-payment-gateways.'
			: 'Changes WooCommerce settings of one group (see kuduy-woo-settings): settings is id => value, e.g. {"woocommerce_currency": "EUR", "woocommerce_price_num_decimals": "2"}. WooCommerce validates each value against the setting\'s type and options; if any is refused, the result lists it and the others are still saved — read them back with kuduy-woo-settings. Needs a store manager. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array(
			'group' => array(
				'type' => 'string',
				'enum' => self::GROUPS,
			),
		);
		if ( 'update' === $this->mode ) {
			$properties['settings'] = array(
				'type'          => 'object',
				'minProperties' => 1,
				'maxProperties' => 50,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'update' === $this->mode ? array( 'group', 'settings' ) : array( 'group' ),
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
				'group'        => array( 'type' => 'string' ),
				'settings'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'changed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'refused'      => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'group' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'get' === $this->mode ? Content::read_only() : array(
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
		return 'get' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * Settings of a group from WooCommerce's controller.
	 *
	 * @param string $group Group id.
	 * @return list<array<string, mixed>>
	 */
	private static function group( string $group ): array {
		$out = array();
		foreach ( (array) CoreRest::call( 'GET', '/wc/v3/settings/' . $group ) as $setting ) {
			$setting = (array) $setting;
			$out[]   = array_intersect_key( $setting, array_flip( array( 'id', 'label', 'description', 'type', 'default', 'options', 'value' ) ) );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'update' !== $this->mode ) {
			return null;
		}
		$values = array_column( self::group( (string) $input['group'] ), 'value', 'id' );
		return array(
			'object_type' => 'option',
			'object_id'   => 'woocommerce_' . $input['group'],
			'payload'     => array(
				'group'  => (string) $input['group'],
				'values' => array_intersect_key( $values, (array) $input['settings'] ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		self::batch( (string) $payload['group'], (array) $payload['values'] );
	}

	/**
	 * Save values through the batch route.
	 *
	 * @param string               $group    Group id.
	 * @param array<string, mixed> $settings Id => value.
	 * @return array<string, string> Refused id => reason.
	 */
	private static function batch( string $group, array $settings ): array {
		$update = array();
		foreach ( $settings as $id => $value ) {
			$update[] = array(
				'id'    => (string) $id,
				'value' => $value,
			);
		}
		$result  = (array) CoreRest::call( 'POST', '/wc/v3/settings/' . $group . '/batch', array( 'update' => $update ) );
		$refused = array();
		foreach ( (array) ( $result['update'] ?? array() ) as $row ) {
			$row = (array) $row;
			if ( isset( $row['error'] ) ) {
				$refused[ (string) ( $row['id'] ?? '?' ) ] = wp_strip_all_tags( (string) ( ( (array) $row['error'] )['message'] ?? 'refused' ) );
			}
		}
		return $refused;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a setting is not in the group.
	 */
	public function execute( array $input ): array {
		$group = (string) $input['group'];
		if ( 'get' === $this->mode ) {
			return array(
				'group'    => $group,
				'settings' => self::group( $group ),
			);
		}
		$known   = array_column( self::group( $group ), 'id' );
		$unknown = array_diff( array_map( 'strval', array_keys( (array) $input['settings'] ) ), $known );
		if ( $unknown ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Not settings of the ' . $group . ' group: ' . implode( ', ', $unknown ) . ' (see kuduy-woo-settings). Nothing was changed.' );
		}
		AuditLog::target( 'option', 'woocommerce_' . $group );
		$refused = self::batch( $group, (array) $input['settings'] );
		return array(
			'group'   => $group,
			'changed' => array_values( array_diff( array_map( 'strval', array_keys( (array) $input['settings'] ) ), array_keys( $refused ) ) ),
			'refused' => (object) $refused,
		);
	}
}
