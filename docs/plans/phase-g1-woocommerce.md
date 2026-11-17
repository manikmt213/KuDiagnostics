# Phase G1 — WooCommerce gaps (2026-11-17)

From `docs/plans/ecosystem-spec-gap.md` §4.2, decisions D45/D46. WooCommerce 11.2.2 source read (11.0.1 on WordPress 6.9); every operation goes through WooCommerce's own functions.

| Tool | WooCommerce API | Undo |
|---|---|---|
| `kuduy-woo-product-duplicate` | `WC_Admin_Duplicate_Product::product_duplicate()` (draft, new SKU, variations copied) | Trash the copy |
| `kuduy-woo-product-restore` | `wp_untrash_post()` (WooCommerce restores variations) | Snapshot: trashed again |
| `kuduy-woo-product-delete` | `WC_Product::delete( true )` | None — confirmation token (D45) |
| `kuduy-woo-variation-delete` | `WC_Product::delete( true )`, `WC_Product_Variable::sync()` | Snapshot: created again (new id) |
| `kuduy-woo-variations-generate` | product data store `create_all_product_variations()` (skips existing combinations; limit default 50) | Snapshot: the new ones deleted |
| `kuduy-woo-attributes`, `-attribute-save` | `wc_get_attribute_taxonomies()`, `wc_create_attribute()`, `wc_update_attribute()`; duplicate slugs also checked against the stored list | Snapshot of the row |
| `kuduy-woo-attribute-delete` | `wc_delete_attribute()` (terms too) | None — confirmation token |
| `kuduy-woo-shipping-classes`, `-shipping-class-save`, `-shipping-class-delete` | terms of `product_shipping_class` (no admin screen, so not reachable by the core term tools) | Snapshot; delete undone by recreating and re-assigning |
| `kuduy-woo-refund-create` | `wc_create_refund()`; preview states whether the gateway will send money (`refund_payment`, only for gateways that support refunds) or the refund is recorded only | None — confirmation token |
| `kuduy-woo-order-update` | `WC_Order` billing/shipping setters, `set_customer_note()`, `save()`; personal data masked, not audited | Snapshot |
| `kuduy-woo-settings`, `-settings-update` | WooCommerce's settings REST controller in process (`wc/v3/settings/{group}`, `…/batch`) for general, products, account, email, site-visibility | Snapshot of the changed values |
| `default_attributes` field on product get/update | `set_default_attributes()` | Product snapshot |

Tests: `WooCatalogTest` WG1–WG7 on all four WooCommerce jobs (11.0.1/11.2.2 × HPOS on/off).
