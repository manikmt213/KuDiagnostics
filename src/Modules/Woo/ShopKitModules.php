<?php
/**
 * Tool: kuduy-shop-kit-modules.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Kadence Shop Kit module switches (phase L8; read on Kadence Shop Kit 3.0.10, kadence-shop-kit.php): the plugin
 * reads the option kt_woo_extras (an array, or JSON in older saves) and loads each module whose switch is on.
 * Read only: the plugin's own save path for this option was not found in its source, so switches are changed on its
 * settings screen.
 */
final class ShopKitModules implements Tool {

	/**
	 * Module switches read in kadence-shop-kit.php, with labels.
	 */
	private const MODULES = array(
		'snackbar_notices'          => 'Snackbar notices',
		'variation_swatches'        => 'Variation swatches',
		'product_templates'         => 'Product templates (needs Kadence Blocks)',
		'product_gallery'           => 'Product gallery',
		'size_charts'               => 'Size charts',
		'kt_add_to_cart_text'       => 'Add to cart text',
		'kt_reviews'                => 'Reviews',
		'kt_cart_notice'            => 'Cart notices',
		'kt_product_badges_options' => 'Product badges',
		'product_quickview'         => 'Quick view',
		'kt_extra_cat'              => 'Extra category description',
		'kt_checkout_editor'        => 'Checkout editor',
		'kt_affiliate_options'      => 'Affiliate options',
		'kt_product_brands_options' => 'Product brands',
		'kt_coupon_modal_checkout'  => 'Coupon modal at checkout',
		'kt_global_tabs'            => 'Global tabs',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-shop-kit-modules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'Kadence Shop Kit modules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists the Kadence Shop Kit modules (swatches, gallery, quick view, badges, reviews, brands, checkout editor …) and whether each is on. Read only: switch modules in Kadence Shop Kit\'s settings.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'modules' => array( 'type' => 'array' ) ),
			'required'   => array( 'modules' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return defined( 'KADENCE_WOO_EXTRAS_PATH' ) ? true : 'Kadence Shop Kit is not active.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		$saved = get_option( 'kt_woo_extras', array() );
		if ( ! is_array( $saved ) ) {
			$saved = json_decode( (string) $saved, true );
		}
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::MODULES as $key => $label ) {
			$out[] = array(
				'module' => $key,
				'label'  => $label,
				'on'     => ! empty( $saved[ $key ] ),
			);
		}
		return array( 'modules' => $out );
	}
}
