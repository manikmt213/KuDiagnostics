<?php
/**
 * Tools: kuduy-woo-product-duplicate, -product-restore, -variation-delete, -variations-generate, -attributes,
 * -attribute-save, -shipping-classes, -shipping-class-save, -shipping-class-delete.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Catalogue work WooCommerce offers in its admin (phase G1), through its own functions (WooCommerce 11.2.2 source):
 * WC_Admin_Duplicate_Product::product_duplicate(), wp_untrash_post() (WooCommerce restores the variations with
 * it), WC_Product::delete() for a variation, the product data store's create_all_product_variations() ("Generate
 * variations"), wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute(), and terms of the
 * product_shipping_class taxonomy (which has no admin screen of its own, so the core term tools do not reach it).
 */
final class CatalogTools implements Tool, Snapshottable {

	/**
	 * Tool modes.
	 */
	public const MODES = array( 'product-duplicate', 'product-restore', 'variation-delete', 'variations-generate', 'attributes', 'attribute-save', 'shipping-classes', 'shipping-class-save', 'shipping-class-delete' );

	/**
	 * Mode.
	 *
	 * @param string $mode One of MODES.
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
			'product-duplicate'     => __( 'Duplicate a product', 'kuduy-mcp' ),
			'product-restore'       => __( 'Restore a product from the trash', 'kuduy-mcp' ),
			'variation-delete'      => __( 'Delete a product variation', 'kuduy-mcp' ),
			'variations-generate'   => __( 'Generate product variations', 'kuduy-mcp' ),
			'attributes'            => __( 'Product attributes', 'kuduy-mcp' ),
			'attribute-save'        => __( 'Create or change a product attribute', 'kuduy-mcp' ),
			'shipping-classes'      => __( 'Shipping classes', 'kuduy-mcp' ),
			'shipping-class-save'   => __( 'Create or change a shipping class', 'kuduy-mcp' ),
			default                 => __( 'Delete a shipping class', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'product-duplicate'     => 'Copies a WooCommerce product with WooCommerce\'s own Duplicate (as in Products > Duplicate): a draft named "… (Copy)" with a new unique SKU, the same prices, stock settings, categories, images and attributes; variations of a variable product are copied too. Sales, ratings and reviews start at zero. Undo: kuduy-woo-product-trash on the copy.',
			'product-restore'       => 'Restores a WooCommerce product from the trash (with its variations), to the status it had before. Snapshot first; undo with kuduy-snapshot-restore.',
			'variation-delete'      => 'Deletes one variation of a variable product (variations have no trash in WooCommerce). Undo with kuduy-snapshot-restore creates the same variation again with a new id (orders keep the old id).',
			'variations-generate'   => 'Creates every missing combination of a variable product\'s variation attributes, like "Generate variations" in the product editor, e.g. sizes S, M, L × colours Red, Blue = 6 variations; combinations that exist are skipped. Set the attributes first (kuduy-woo-product-update attributes with variation: true). New variations have no price until you set one (kuduy-woo-variation-update or a background job). limit caps how many are made (default 50, WooCommerce\'s own cap). Undo with kuduy-snapshot-restore deletes the ones made here.',
			'attributes'            => 'Lists the store\'s global product attributes (Products > Attributes): id, name, slug, taxonomy (e.g. pa_color), type, sort order, archives, and their terms (e.g. Red, Blue) with term ids. Create or change terms with kuduy-term-create / kuduy-term-update on the attribute\'s taxonomy. Products use them with kuduy-woo-product-update attributes.',
			'attribute-save'        => 'Creates a global product attribute (no id) or changes one (id): name, slug (without "pa_"; changing it renames the taxonomy), type ("select" unless a plugin adds others), order_by (menu_order, name, name_num or id), has_archives. WooCommerce checks the slug (length, reserved words, duplicates). New: undo with kuduy-woo-attribute-delete. Changes are snapshotted; undo with kuduy-snapshot-restore.',
			'shipping-classes'      => 'Lists WooCommerce shipping classes: id, name, slug, description and how many products use each. Products get one with kuduy-woo-product-update shipping_class_id; zones charge per class in their flat-rate methods (kuduy-woo-shipping).',
			'shipping-class-save'   => 'Creates a shipping class (no id) or changes one (id): name, slug, description. Changes are snapshotted; undo with kuduy-snapshot-restore. A new class: undo with kuduy-woo-shipping-class-delete.',
			default                 => 'Deletes a shipping class. Products that used it lose it (WordPress removes the term from them); flat-rate costs set for the class are no longer used. Undo with kuduy-snapshot-restore creates the class again (new id) and puts it back on those products.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = match ( $this->mode ) {
			'attributes', 'shipping-classes' => array(),
			'variations-generate'            => array(
				'id'    => $id,
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			),
			'attribute-save'                 => array(
				'id'           => $id,
				'name'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'slug'         => array(
					'type'      => 'string',
					'maxLength' => 28,
					'pattern'   => '^[a-z0-9_-]*$',
				),
				'type'         => array(
					'type'      => 'string',
					'maxLength' => 50,
				),
				'order_by'     => array(
					'type' => 'string',
					'enum' => array( 'menu_order', 'name', 'name_num', 'id' ),
				),
				'has_archives' => array( 'type' => 'boolean' ),
			),
			'shipping-class-save'            => array(
				'id'          => $id,
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'slug'        => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
			default                          => array( 'id' => $id ),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( in_array( $this->mode, array( 'product-duplicate', 'product-restore', 'variation-delete', 'variations-generate', 'shipping-class-delete' ), true ) ) {
			$schema['required'] = array( 'id' );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'created'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'attributes'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'classes'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'attributes', 'shipping-classes' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => in_array( $this->mode, array( 'product-restore', 'variation-delete', 'variations-generate', 'attribute-save', 'shipping-class-save', 'shipping-class-delete' ), true ),
			'idempotentHint'  => in_array( $this->mode, array( 'product-restore', 'variation-delete', 'shipping-class-delete' ), true ),
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'attributes', 'shipping-classes'      => 'kuduy:read',
			'attribute-save', 'shipping-class-save', 'shipping-class-delete' => 'kuduy:admin',
			default                               => 'kuduy:write',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return match ( $this->mode ) {
			'attributes', 'shipping-classes' => 'edit_products',
			'attribute-save', 'shipping-class-save', 'shipping-class-delete' => 'manage_product_terms',
			'product-duplicate'              => $id > 0 ? array( 'edit_post', $id ) : 'edit_products',
			'product-restore'                => $id > 0 ? array( 'delete_post', $id ) : 'delete_products',
			// A variation deleted here no longer exists when its undo runs; restore() checks its parent then.
			default                          => $id > 0 && get_post( $id ) ? array( 'edit_post', Woo::owner_id( $id ) ) : 'edit_products',
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
	 */
	public function snapshot( array $input ): ?array {
		$id      = (int) ( $input['id'] ?? 0 );
		$payload = match ( $this->mode ) {
			'product-restore'       => $id > 0 ? array(
				'id'     => $id,
				'status' => (string) get_post_status( $id ),
			) : null,
			'variation-delete'      => $id > 0 && 'product_variation' === get_post_type( $id ) ? self::variation_state( $id ) : null,
			'variations-generate'   => $id > 0 && 'product' === get_post_type( $id ) ? array(
				'id'       => $id,
				'children' => array_map( 'intval', (array) get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one lookup.
			) : null,
			'attribute-save'        => $id > 0 ? array( 'attribute' => self::attribute_row( $id ) ) : null,
			'shipping-class-save', 'shipping-class-delete' => $id > 0 ? self::class_state( $id ) : null,
			default                 => null,
		};
		return null === $payload ? null : array(
			'object_type' => 'attribute-save' === $this->mode ? 'woo_attribute' : 'post',
			'object_id'   => (string) $id,
			'payload'     => $payload,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the state cannot be put back.
	 */
	public function restore( array $payload ): void {
		switch ( $this->mode ) {
			case 'product-restore':
				wp_trash_post( (int) $payload['id'] );
				break;
			case 'variation-delete':
				if ( ! current_user_can( 'edit_post', (int) $payload['parent_id'] ) ) {
					throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account cannot edit the product this variation belonged to.' );
				}
				$variation = new \WC_Product_Variation();
				$variation->set_props( (array) $payload['props'] );
				$variation->set_parent_id( (int) $payload['parent_id'] );
				$variation->set_attributes( (array) $payload['attributes'] );
				$variation->save();
				break;
			case 'variations-generate':
				$keep = array_map( 'intval', (array) $payload['children'] );
				foreach ( (array) get_children( array( 'post_parent' => (int) $payload['id'], 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids' ) ) as $child ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one lookup.
					if ( ! in_array( (int) $child, $keep, true ) ) {
						$variation = wc_get_product( (int) $child );
						if ( $variation instanceof \WC_Product ) {
							$variation->delete( true );
						}
					}
				}
				\WC_Product_Variable::sync( (int) $payload['id'] );
				break;
			case 'attribute-save':
				$row    = (array) $payload['attribute'];
				$result = wc_update_attribute( (int) $row['id'], $row );
				if ( is_wp_error( $result ) ) {
					throw new ToolError( 'Undo failed: ' . wp_strip_all_tags( $result->get_error_message() ) );
				}
				break;
			default:
				self::restore_class( $payload );
		}//end switch
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WooCommerce refuses or the object is unknown.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'product-duplicate'     => self::duplicate( (int) $input['id'] ),
			'product-restore'       => self::untrash( (int) $input['id'] ),
			'variation-delete'      => self::delete_variation( (int) $input['id'] ),
			'variations-generate'   => self::generate( (int) $input['id'], (int) ( $input['limit'] ?? 50 ) ),
			'attributes'            => array( 'attributes' => self::attribute_list() ),
			'attribute-save'        => self::save_attribute( $input ),
			'shipping-classes'      => array( 'classes' => self::class_list() ),
			'shipping-class-save'   => self::save_class( $input ),
			default                 => self::delete_class( (int) $input['id'] ),
		};
	}

	/**
	 * Duplicate a product with WooCommerce's own duplicate.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>
	 * @throws ToolError When the product is unknown or the copy fails.
	 */
	private static function duplicate( int $id ): array {
		$product = Woo::product( $id );
		Woo::assert_product_permission( 'create' );
		if ( ! class_exists( '\WC_Admin_Duplicate_Product' ) ) {
			// WooCommerce loads its admin classes only on admin screens; this one has no other dependencies.
			$file = dirname( (string) constant( 'WC_PLUGIN_FILE' ) ) . '/includes/admin/class-wc-admin-duplicate-product.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			if ( ! class_exists( '\WC_Admin_Duplicate_Product' ) ) {
				throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'This WooCommerce version has no product duplicate function.' );
			}
		}
		AuditLog::target( 'post', (string) $id );
		$copy = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( $product );
		if ( $copy->get_id() < 1 ) {
			throw new ToolError( 'WooCommerce did not create the copy.' );
		}
		return Woo::summary( $copy ) + array( 'copied_from' => $id );
	}

	/**
	 * Restore a trashed product.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not in the trash.
	 */
	private static function untrash( int $id ): array {
		if ( 'product' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
			throw ToolError::of( ToolError::CONFLICT, 'Product ' . $id . ' is not in the trash.' );
		}
		AuditLog::target( 'post', (string) $id );
		if ( ! wp_untrash_post( $id ) ) {
			throw new ToolError( 'WordPress did not restore this product.' );
		}
		return Woo::summary( Woo::product( $id ) );
	}

	/**
	 * Everything needed to create a variation again.
	 *
	 * @param int $id Variation id.
	 * @return array<string, mixed>
	 */
	private static function variation_state( int $id ): array {
		$variation = Woo::variation( $id );
		$props     = Woo::read( $variation, array_diff( array_keys( Woo::variation_fields() ), array( 'attributes' ) ) );
		return array(
			'id'         => $id,
			'parent_id'  => $variation->get_parent_id(),
			'attributes' => $variation->get_attributes( 'edit' ),
			'props'      => $props,
		);
	}

	/**
	 * Delete one variation.
	 *
	 * @param int $id Variation id.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not a variation or WooCommerce does not delete it.
	 */
	private static function delete_variation( int $id ): array {
		$variation = Woo::variation( $id );
		AuditLog::target( 'post', (string) $id );
		$parent = $variation->get_parent_id();
		if ( ! $variation->delete( true ) ) {
			throw new ToolError( 'WooCommerce did not delete this variation.' );
		}
		\WC_Product_Variable::sync( $parent );
		return array(
			'id'        => $id,
			'parent_id' => $parent,
			'deleted'   => true,
		);
	}

	/**
	 * Generate missing variations.
	 *
	 * @param int $id    Variable product id.
	 * @param int $limit Most to create.
	 * @return array<string, mixed>
	 * @throws ToolError When the product is not variable or has no variation attributes.
	 */
	private static function generate( int $id, int $limit ): array {
		$product = Woo::product( $id );
		if ( ! $product instanceof \WC_Product_Variable ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Product ' . $id . ' is a ' . $product->get_type() . ' product; only variable products have variations.' );
		}
		if ( ! array_filter( $product->get_attributes(), 'wc_attributes_array_filter_variation' ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'This product has no attribute marked "used for variations". Set attributes with variation: true first (kuduy-woo-product-update).' );
		}
		AuditLog::target( 'post', (string) $id );
		$before = $product->get_children();
		$store  = \WC_Data_Store::load( 'product' );
		$count  = (int) $store->create_all_product_variations( $product, $limit );
		\WC_Product_Variable::sync( $id );
		$after = wc_get_product( $id );
		$now   = $after instanceof \WC_Product ? $after->get_children() : array();
		return array(
			'id'      => $id,
			'created' => array_values( array_map( 'intval', array_diff( $now, $before ) ) ),
			'count'   => $count,
			'total'   => count( $now ),
		);
	}

	/**
	 * One attribute row as wc_update_attribute() takes it.
	 *
	 * @param int $id Attribute id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no such attribute.
	 */
	private static function attribute_row( int $id ): array {
		$attribute = wc_get_attribute( $id );
		if ( null === $attribute ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No product attribute with id ' . $id . ' (see kuduy-woo-attributes).' );
		}
		return array(
			'id'           => (int) $attribute->id,
			'name'         => (string) $attribute->name,
			'slug'         => (string) preg_replace( '/^pa_/', '', (string) $attribute->slug ),
			'type'         => (string) $attribute->type,
			'order_by'     => (string) $attribute->order_by,
			'has_archives' => (bool) $attribute->has_archives,
		);
	}

	/**
	 * Global attributes with their terms.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function attribute_list(): array {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			$taxonomy = wc_attribute_taxonomy_name( (string) $row->attribute_name );
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			$out[]    = array(
				'id'           => (int) $row->attribute_id,
				'name'         => (string) $row->attribute_label,
				'slug'         => (string) $row->attribute_name,
				'taxonomy'     => $taxonomy,
				'type'         => (string) $row->attribute_type,
				'order_by'     => (string) $row->attribute_orderby,
				'has_archives' => (bool) $row->attribute_public,
				'terms'        => is_array( $terms ) ? array_map(
					static fn( \WP_Term $term ): array => array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					),
					$terms
				) : array(),
			);
		}//end foreach
		return $out;
	}

	/**
	 * Create or change an attribute.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function save_attribute( array $input ): array {
		$args = array_intersect_key( $input, array_flip( array( 'name', 'slug', 'type', 'order_by', 'has_archives' ) ) );
		// WooCommerce finds a duplicate slug through the registered taxonomy, which exists only from the next request
		// after an attribute is created; compare with the stored attributes as well.
		$slug = isset( $args['slug'] ) ? (string) $args['slug'] : ( isset( $input['id'] ) ? '' : wc_sanitize_taxonomy_name( (string) ( $args['name'] ?? '' ) ) );
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			if ( '' !== $slug && (string) $row->attribute_name === $slug && (int) ( $input['id'] ?? 0 ) !== (int) $row->attribute_id ) {
				throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'An attribute with the slug "' . $slug . '" exists already (id ' . (int) $row->attribute_id . '). Nothing was changed.' );
			}
		}
		if ( isset( $input['id'] ) ) {
			$id     = (int) $input['id'];
			$result = wc_update_attribute( $id, $args + self::attribute_row( $id ) );
		} else {
			if ( ! isset( $args['name'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new attribute needs a name.' );
			}
			$result = wc_create_attribute( $args );
		}
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'WooCommerce refused the attribute: ' . wp_strip_all_tags( $result->get_error_message() ) . ' Nothing was changed.' );
		}
		AuditLog::target( 'woo_attribute', (string) $result );
		// WooCommerce registers the attribute's taxonomy (pa_…) on the next request; its terms can be added from then on.
		return array( 'id' => (int) $result ) + self::attribute_row( (int) $result );
	}

	/**
	 * Shipping classes.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function class_list(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		return is_array( $terms ) ? array_values(
			array_map(
				static fn( \WP_Term $term ): array => array(
					'id'          => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'products'    => $term->count,
				),
				$terms
			)
		) : array();
	}

	/**
	 * A shipping class term and the products that use it.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not a shipping class.
	 */
	private static function class_state( int $id ): array {
		$term = get_term( $id, 'product_shipping_class' );
		if ( ! $term instanceof \WP_Term ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No shipping class with id ' . $id . ' (see kuduy-woo-shipping-classes).' );
		}
		$objects = get_objects_in_term( $id, 'product_shipping_class' );
		return array(
			'id'          => $id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'objects'     => is_array( $objects ) ? array_map( 'intval', $objects ) : array(),
		);
	}

	/**
	 * Put a shipping class back as it was (recreate it when it was deleted).
	 *
	 * @param array<string, mixed> $state From class_state().
	 * @throws ToolError When WordPress refuses.
	 */
	private static function restore_class( array $state ): void {
		$args = array(
			'name'        => (string) $state['name'],
			'slug'        => (string) $state['slug'],
			'description' => (string) $state['description'],
		);
		if ( get_term( (int) $state['id'], 'product_shipping_class' ) instanceof \WP_Term ) {
			$result = wp_update_term( (int) $state['id'], 'product_shipping_class', $args );
		} else {
			$result = wp_insert_term( (string) $state['name'], 'product_shipping_class', $args );
		}
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Undo failed: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		foreach ( (array) $state['objects'] as $object ) {
			wp_set_object_terms( (int) $object, array( (int) $result['term_id'] ), 'product_shipping_class' );
		}
	}

	/**
	 * Create or change a shipping class.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When WordPress refuses.
	 */
	private static function save_class( array $input ): array {
		$args = array_intersect_key( $input, array_flip( array( 'name', 'slug', 'description' ) ) );
		if ( isset( $input['id'] ) ) {
			self::class_state( (int) $input['id'] );
			$result = wp_update_term( (int) $input['id'], 'product_shipping_class', $args );
		} else {
			if ( ! isset( $args['name'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new shipping class needs a name.' );
			}
			$result = wp_insert_term( (string) $args['name'], 'product_shipping_class', $args );
		}
		if ( is_wp_error( $result ) ) {
			$code = 'term_exists' === $result->get_error_code() ? ToolError::DUPLICATE_RESOURCE : ToolError::VALIDATION_FAILED;
			throw ToolError::of( $code, 'WordPress refused the shipping class: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		AuditLog::target( 'term', (string) $result['term_id'] );
		$state = self::class_state( (int) $result['term_id'] );
		unset( $state['objects'] );
		return $state;
	}

	/**
	 * Delete a shipping class.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>
	 * @throws ToolError When WordPress does not delete it.
	 */
	private static function delete_class( int $id ): array {
		self::class_state( $id );
		AuditLog::target( 'term', (string) $id );
		$result = wp_delete_term( $id, 'product_shipping_class' );
		if ( true !== $result ) {
			throw new ToolError( 'WordPress did not delete this shipping class.' );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}
}
