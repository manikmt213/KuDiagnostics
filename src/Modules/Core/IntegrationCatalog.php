<?php
/**
 * The catalogue of integrations KuDiagnostics knows about, with what it really supports for each.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

/**
 * One row per integration (decision D30): the target list of the upgrade plan plus what is supported beyond it. The
 * support class is a statement about tests, not intentions: "full" means real operations tested against the real
 * product, "partial" some operations or not verified against it, "detection" recognised only, "none" nothing. It
 * changes only together with INTEGRATION-MATRIX.md when a test passes. Folder names of paid products are the ones
 * those products are known to install under and are UNVERIFIED (no copy was available).
 */
final class IntegrationCatalog {

	/**
	 * Categories, in display order.
	 */
	public const CATEGORIES = array( 'builder', 'theme', 'blocks', 'store', 'forms', 'fields', 'addon', 'dynamic', 'code', 'seo', 'translation', 'cache' );

	/**
	 * Support classes.
	 */
	public const SUPPORT = array( 'full', 'partial', 'detection', 'none' );

	/**
	 * Rows: id, name, category, kind (plugin | theme | core), folders, support, tool name prefixes, copy (free | paid |
	 * core), plan phase, note.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: string, 6: list<string>, 7: string, 8: string, 9: string}>
	 */
	private const ROWS = array(
		array( 'elementor', 'Elementor', 'builder', 'plugin', array( 'elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', 'F', 'Tested on 3.35.9 and 4.3.6, including duplicating a document, page settings and dynamic tags; verified live on 4.4.0 (2026-11-16). Popups and forms come with Elementor Pro (see Elementor Pro). Not yet: dynamic tags on v4 elements and inside repeater rows.' ),
		array( 'elementor-pro', 'Elementor Pro', 'builder', 'plugin', array( 'elementor-pro' ), 'partial', array( 'kuduy-elementor-theme-', 'kuduy-elementor-popup-', 'kuduy-elementor-form', 'kuduy-elementor-dynamic-' ), 'paid', 'F', 'Theme Builder templates and conditions tested with Pro 4.3.2; popups verified live on Pro 4.0.4 (2026-11-16); Pro dynamic tags through the dynamic tag tools. Forms: form widgets with fields and actions, submissions read, marked read and trashed through Pro\'s submissions store (verified live on Pro 4.0.4, 2026-11-18).' ),
		array( 'wpbakery', 'WPBakery Page Builder', 'builder', 'plugin', array( 'js_composer' ), 'partial', array( 'kuduy-wpbakery-' ), 'paid', 'L', 'Verified on 9.1 on a live site (2026-11-15) for elements, create, tree, adding and changing elements, validate and undo; CI runs against a stand-in. Saved templates (My Templates) are listed, read, saved and deleted with undo (verified live on 9.1, 2026-11-18).' ),
		array( 'beaver-builder', 'Beaver Builder', 'builder', 'plugin', array( 'beaver-builder-lite-version', 'bb-plugin' ), 'full', array( 'kuduy-beaver-' ), 'free', '', 'Layouts are read, created and edited (rows, columns, modules) through Beaver Builder\'s own model; tested with Lite 2.11.1.2. Not changed: shared (global) rows and modules, column widths, saved templates, the paid version\'s extra modules (not tested).' ),
		array( 'brizy', 'Brizy', 'builder', 'plugin', array( 'brizy', 'brizy-pro' ), 'partial', array( 'kuduy-brizy-' ), 'free', '', 'Pages are listed and their structure is read; built from the source of 2.8.26 and tested against a stand-in, not against Brizy itself. Layouts are not changed: Brizy compiles the public page in its own editor.' ),
		array( 'kirki', 'Kirki', 'builder', 'plugin', array( 'kirki' ), 'partial', array( 'kuduy-kirki-' ), 'free', 'H', 'Pages built with the Kirki builder (version 6) are listed and read (checked live on 6.4.2); block text and attributes are changed and blocks removed, moved and duplicated through Kirki\'s page manager, as an unpublished version or published, with undo (tested with 6.4.2, verified live 2026-11-18). Not yet: styles, classes, fonts and new elements.' ),
		array( 'bricks', 'Bricks', 'builder', 'theme', array( 'bricks' ), 'partial', array( 'kuduy-bricks-' ), 'paid', 'L', 'Pages are read from Bricks\' documented data model (any version); with Bricks 2.4+ and its abilities switched on, pages are also read and edited through those abilities, with undo. Verified live on 2.4.5 (2026-11-15). Site-wide abilities (global settings, variables and similar) run in two steps with a confirmation and an options snapshot for undo (verified live on 2.4.5, 2026-11-18); abilities that change roles, menus, files or pages that way are not run, nor are PHP abilities.' ),
		array( 'oxygen', 'Oxygen', 'builder', 'plugin', array( 'oxygen' ), 'partial', array( 'kuduy-oxygen-' ), 'paid', 'L', 'Oxygen 6: pages are read and edited through the abilities Oxygen itself registers (verified live on 6.2.0, 2026-11-15). Site-wide settings run in two steps with a confirmation and an options snapshot for undo (verified live 2026-11-18). Oxygen 4 (classic) is not supported.' ),
		array( 'divi', 'Divi', 'builder', 'theme', array( 'Divi' ), 'partial', array( 'kuduy-divi-', 'kuduy-block-edit' ), 'paid', 'L', 'Divi 5 pages (WordPress blocks) are listed and read (verified live on 5.19.0) and changed block by block with kuduy-block-edit, nested settings merged (Divi registers its modules as block types and clears its CSS on save; verified live on 5.19.0, 2026-11-18). Divi 4 pages are recognised and left alone.' ),
		array( 'divi-builder', 'Divi Builder (plugin)', 'builder', 'plugin', array( 'divi-builder' ), 'partial', array( 'kuduy-divi-' ), 'paid', 'L', 'Same read-only tools as the Divi theme (checked on the Divi 5.19.0 theme; the plugin itself not yet).' ),
		array( 'breakdance', 'Breakdance', 'builder', 'plugin', array( 'breakdance' ), 'partial', array( 'kuduy-breakdance-' ), 'paid', 'L', 'Breakdance 3: pages are read and edited through the abilities Breakdance itself registers (verified live on 3.0.0-beta.4, 2026-11-15). Site-wide settings run in two steps with a confirmation and an options snapshot for undo (verified live 2026-11-18).' ),
		array( 'etch', 'Etch', 'builder', 'plugin', array( 'etch' ), 'partial', array( 'kuduy-etch-', 'kuduy-block-edit' ), 'paid', 'L', 'Etch pages (WordPress blocks) are listed, read and changed with kuduy-block-edit. Tested against a stand-in, not against Etch itself (its block names are unverified).' ),
		array( 'mosaic', 'Mosaic', 'builder', 'plugin', array( 'mosaic' ), 'none', array(), 'paid', 'L', '' ),

		array( 'ollie', 'Ollie', 'theme', 'theme', array( 'ollie' ), 'full', array( 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu', 'kuduy-theme-facts' ), 'free', '', 'Block theme: templates, Global Styles and navigation through the Site Editor tools (tested with 1.6.6).' ),
		array( 'spectra-one', 'Spectra One', 'theme', 'theme', array( 'spectra-one' ), 'full', array( 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu', 'kuduy-theme-facts' ), 'free', '', 'Block theme: templates, Global Styles and navigation through the Site Editor tools (tested with 1.2.6).' ),
		array( 'astra', 'Astra', 'theme', 'theme', array( 'astra' ), 'full', array( 'kuduy-astra-', 'kuduy-theme-facts' ), 'free', '', 'Settings are read and changed through the abilities Astra itself provides (tested with 4.14.2). The site owner must switch them on in Astra > Settings first.' ),
		array( 'kadence', 'Kadence', 'theme', 'theme', array( 'kadence' ), 'full', array( 'kuduy-theme-facts', 'kuduy-theme-settings' ), 'free', '', 'Customizer settings are read and changed with the theme sanitizers (tested with 1.6.0).' ),
		array( 'blocksy', 'Blocksy', 'theme', 'theme', array( 'blocksy' ), 'full', array( 'kuduy-theme-facts', 'kuduy-theme-settings' ), 'free', '', 'Customizer settings are read and changed with the theme sanitizers (tested with 2.1.61).' ),
		array( 'generatepress', 'GeneratePress', 'theme', 'theme', array( 'generatepress' ), 'full', array( 'kuduy-theme-facts', 'kuduy-theme-settings' ), 'free', '', 'Customizer settings are read and changed with the theme sanitizers (tested with 3.6.1).' ),
		array( 'oceanwp', 'OceanWP', 'theme', 'theme', array( 'oceanwp' ), 'full', array( 'kuduy-theme-facts', 'kuduy-theme-settings' ), 'free', '', 'Customizer settings are read and changed with the theme sanitizers (tested with 4.2.8).' ),
		array( 'avada', 'Avada (theme)', 'theme', 'theme', array( 'Avada' ), 'partial', array( 'kuduy-avada-status', 'kuduy-avada-global-options' ), 'paid', 'L', 'Detected with versions and child theme; Global Options read (design settings) and changed through Avada\'s settings option with undo, secrets refused (verified live on Avada 7.16, 2026-11-18).' ),
		array( 'avada-builder', 'Avada Builder', 'builder', 'plugin', array( 'fusion-builder' ), 'full', array( 'kuduy-avada-' ), 'paid', 'L', 'Pages, containers, columns, elements, Library and Layouts. Tested on real Avada 7.16.4 / Builder 3.16.4 (2026-11-13). Not changed: Global Options, Layout conditions.' ),
		array( 'voxel', 'Voxel', 'theme', 'theme', array( 'voxel' ), 'detection', array(), 'paid', 'L', 'Theme settings are not managed yet.' ),
		array( 'mylisting', 'MyListing', 'theme', 'theme', array( 'my-listing' ), 'detection', array(), 'paid', 'L', 'Theme settings are not managed yet.' ),

		array( 'gutenberg', 'WordPress blocks and Site Editor', 'blocks', 'core', array(), 'partial', array( 'kuduy-block-', 'kuduy-post-blocks', 'kuduy-patterns', 'kuduy-synced-pattern-', 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu', 'kuduy-widget' ), 'core', 'C', 'Templates, Global Styles, navigation and widgets are managed; single blocks are added, changed, moved and removed with checks against the registered block types; templates and template parts are created; patterns are listed and inserted, and synced patterns created, changed and trashed with undo. The HTML of static blocks is never regenerated (the block editor does that in the browser).' ),
		array( 'generateblocks', 'GenerateBlocks', 'blocks', 'plugin', array( 'generateblocks' ), 'partial', array( 'kuduy-block-', 'kuduy-generateblocks-' ), 'free', 'C', 'Its blocks are listed with their exact schemas and are inserted, changed, moved and checked through the core block tools; its settings are read and changed through its own settings route (tested with GenerateBlocks 2.5.1). GenerateBlocks Pro features are not covered.' ),
		array( 'kadence-blocks', 'Kadence Blocks', 'blocks', 'plugin', array( 'kadence-blocks' ), 'partial', array( 'kuduy-block-', 'kuduy-kadence-blocks-' ), 'free', 'C', 'Its blocks go through the core block tools; its registered settings are read and changed like its settings screen does, API keys and secrets never shown or changed; the blocks it hides from the inserter are reported (tested with Kadence Blocks 3.9.1). Kadence Blocks Pro features are not covered.' ),
		array( 'spectra', 'Spectra Legacy', 'blocks', 'plugin', array( 'ultimate-addons-for-gutenberg' ), 'partial', array( 'kuduy-block-', 'kuduy-spectra-status', 'kuduy-spectra-settings-update', 'kuduy-spectra-block-toggle', 'kuduy-spectra-popup' ), 'free', 'C', 'The older Spectra plugin (not Spectra Blocks). Its blocks go through the core block tools; blocks are switched on or off as on its Blocks dashboard; its settings (extensions, editor options, fonts, container sizes, coming soon / maintenance mode) are changed as its Settings screen saves them; popups are listed, read, created, changed and trashed (tested with Spectra Legacy 2.20.6). Not yet: Spectra Pro features.' ),
		array( 'spectra-blocks', 'Spectra Blocks', 'blocks', 'plugin', array( 'spectra-blocks' ), 'partial', array( 'kuduy-block-', 'kuduy-spectra-blocks-' ), 'free', 'C', 'The newer Spectra Blocks plugin (not Spectra Legacy). Its blocks go through the core block tools; popups are listed, read, created, changed and trashed; its own abilities (when switched on in its settings) are run page by page with undo, site-wide ones are not (tested with Spectra Blocks 1.0.15).' ),

		array( 'woocommerce', 'WooCommerce', 'store', 'plugin', array( 'woocommerce' ), 'full', array( 'kuduy-woo-' ), 'free', 'G', 'Tested with order storage (HPOS) on and off, WooCommerce 11.0.1 and 11.2.2. Products (incl. duplicate, restore, permanent delete with confirmation), variations (incl. generate and delete), global attributes, shipping classes, orders (status, notes, addresses, refunds with confirmation), coupons, customers, reviews, shipping, tax, store settings (general, products, accounts, emails, visibility). Not yet: customer create and delete, payment gateway settings changes.' ),

		array( 'contact-form-7', 'Contact Form 7', 'forms', 'plugin', array( 'contact-form-7' ), 'full', array( 'kuduy-forms-' ), 'free', '', 'Entries need Flamingo.' ),
		array( 'flamingo', 'Flamingo', 'forms', 'plugin', array( 'flamingo' ), 'full', array( 'kuduy-forms-entr' ), 'free', '', '' ),
		array( 'fluentform', 'Fluent Forms', 'forms', 'plugin', array( 'fluentform' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; entries are read, changed and annotated. Not yet: creating or changing forms.' ),
		array( 'wpforms', 'WPForms', 'forms', 'plugin', array( 'wpforms-lite', 'wpforms' ), 'partial', array( 'kuduy-forms-' ), 'free', 'L', 'Forms are read (tested with Lite 2.0.4). Entries need WPForms Pro: reading, changing and notes are tested against a stand-in, not against Pro itself.' ),
		array( 'gravityforms', 'Gravity Forms', 'forms', 'plugin', array( 'gravityforms' ), 'partial', array( 'kuduy-forms-' ), 'paid', 'L', 'Forms are read; entries are read, changed and annotated. Built from its documentation and tested against a stand-in, not against the plugin itself.' ),
		array( 'ninja-forms', 'Ninja Forms', 'forms', 'plugin', array( 'ninja-forms' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; submissions are read, trashed and restored (tested with 3.16.1). Not yet: creating or changing forms.' ),
		array( 'formidable', 'Formidable Forms', 'forms', 'plugin', array( 'formidable' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; entries are read only (tested with 7.0). Not yet: creating or changing forms.' ),

		array( 'acf', 'Advanced Custom Fields', 'fields', 'plugin', array( 'advanced-custom-fields', 'advanced-custom-fields-pro' ), 'partial', array( 'kuduy-acf-' ), 'free', 'E', 'Field groups are read; values are read and changed. Not yet: creating field groups; repeater and flexible content (Pro) are not verified.' ),
		array( 'meta-box', 'Meta Box', 'fields', 'plugin', array( 'meta-box' ), 'none', array(), 'free', 'E', '' ),
		array( 'pods', 'Pods', 'fields', 'plugin', array( 'pods' ), 'none', array(), 'free', 'E', '' ),
		array( 'acpt', 'ACPT', 'fields', 'plugin', array( 'acpt-lite', 'advanced-custom-post-type' ), 'none', array(), 'free', 'E', '' ),
		array( 'ase', 'Admin and Site Enhancements (ASE)', 'fields', 'plugin', array( 'admin-site-enhancements', 'admin-site-enhancements-pro' ), 'none', array(), 'free', 'E', '' ),
		array( 'jet-engine', 'JetEngine', 'fields', 'plugin', array( 'jet-engine' ), 'none', array(), 'paid', 'L', '' ),

		array( 'ultimate-addons-elementor', 'Ultimate Addons for Elementor', 'addon', 'plugin', array( 'header-footer-elementor', 'ultimate-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with the free version 2.9.6).' ),
		array( 'essential-addons-elementor', 'Essential Addons for Elementor', 'addon', 'plugin', array( 'essential-addons-for-elementor-lite' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 6.8.8).' ),
		array( 'premium-addons-elementor', 'Premium Addons for Elementor', 'addon', 'plugin', array( 'premium-addons-for-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 4.11.117).' ),
		array( 'happy-addons', 'Happy Addons', 'addon', 'plugin', array( 'happy-elementor-addons' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 3.51.3).' ),
		array( 'ht-mega', 'HT Mega', 'addon', 'plugin', array( 'ht-mega-for-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 3.2.8).' ),
		array( 'powerpack', 'PowerPack for Elementor', 'addon', 'plugin', array( 'powerpack-lite-for-elementor', 'powerpack-elements' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with Lite 3.1.4).' ),
		array( 'qi-addons', 'Qi Addons for Elementor', 'addon', 'plugin', array( 'qi-addons-for-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 1.11.2).' ),
		array( 'unlimited-elements', 'Unlimited Elements for Elementor', 'addon', 'plugin', array( 'unlimited-elements-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'A fresh install registers no Elementor widgets (they are installed from its own catalogue); widgets installed that way are not tested.' ),
		array( 'xpro-addons', 'Xpro Elementor Addons', 'addon', 'plugin', array( 'xpro-elementor-addons' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 1.7.11).' ),
		array( 'element-pack', 'Element Pack', 'addon', 'plugin', array( 'bdthemes-element-pack-lite', 'bdthemes-element-pack' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with Lite 10.0.5).' ),
		array( 'piotnet-addons', 'Piotnet Addons for Elementor', 'addon', 'plugin', array( 'piotnet-addons-for-elementor', 'piotnet-addons-for-elementor-pro' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 2.4.37).' ),
		array( 'jet-elements', 'JetElements', 'addon', 'plugin', array( 'jet-elements' ), 'partial', array( 'kuduy-elementor-' ), 'paid', 'L', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'dynamic-content-elementor', 'Dynamic Content for Elementor', 'addon', 'plugin', array( 'dynamic-content-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'paid', 'L', 'Its widgets should work through the Elementor tools like any registered widget; not tested. Dynamic tags are not managed yet.' ),
		array( 'bricksforge', 'Bricksforge', 'addon', 'plugin', array( 'bricksforge' ), 'none', array(), 'paid', 'L', 'Needs Bricks.' ),

		array( 'dynamic-shortcodes', 'Dynamic Shortcodes', 'dynamic', 'plugin', array( 'dynamic-shortcodes' ), 'none', array(), 'paid', 'L', '' ),
		array( 'code-snippets', 'Code Snippets', 'code', 'plugin', array( 'code-snippets', 'code-snippets-pro' ), 'full', array( 'kuduy-code-snippets-' ), 'free', 'G', 'Snippets listed, read with a syntax check and risky-call report, HTML/CSS/JS snippets saved with a confirmation step, PHP snippets only where the site allows code execution (kuduy:code), switched off and trashed with undo (tested with 4.1.0). Not yet: import/export, Pro conditions and cloud.' ),

		array( 'rank-math', 'Rank Math SEO', 'seo', 'plugin', array( 'seo-by-rank-math' ), 'full', array( 'kuduy-seo-', 'kuduy-rank-math-' ), 'free', 'G', 'Post and term meta, redirects, schema, sitemap status, title templates (read). Audits for missing data, noindex and duplicate titles/descriptions. Not yet: heading and link audits, settings changes.' ),
		array( 'yoast', 'Yoast SEO', 'seo', 'plugin', array( 'wordpress-seo' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap', 'kuduy-yoast-' ), 'free', 'G', 'Post and term meta, sitemap status, title templates and archive settings (read). Audits for missing data, noindex and duplicates. Not yet: schema type, settings changes, redirects (Premium).' ),
		array( 'aioseo', 'All in One SEO', 'seo', 'plugin', array( 'all-in-one-seo-pack' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap' ), 'free', 'G', 'Post meta and audits. Term SEO is an All in One SEO Pro feature (not covered). Not yet: settings.' ),
		array( 'seopress', 'SEOPress', 'seo', 'plugin', array( 'wp-seopress' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap', 'kuduy-seopress-' ), 'free', 'G', 'Post and term meta (through its abilities), title templates (read), audits. Not yet: settings changes, redirects (Pro).' ),

		array( 'polylang', 'Polylang', 'translation', 'plugin', array( 'polylang', 'polylang-pro' ), 'full', array( 'kuduy-lang-' ), 'free', '', 'Tested with 3.9.2.' ),
		array( 'wpml', 'WPML', 'translation', 'plugin', array( 'sitepress-multilingual-cms' ), 'none', array(), 'paid', 'L', '' ),
		array( 'weglot', 'Weglot', 'translation', 'plugin', array( 'weglot' ), 'none', array(), 'free', 'K', 'Translations are kept in Weglot\'s cloud service, not on the site.' ),

		array( 'litespeed-cache', 'LiteSpeed Cache', 'cache', 'plugin', array( 'litespeed-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'w3-total-cache', 'W3 Total Cache', 'cache', 'plugin', array( 'w3-total-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-super-cache', 'WP Super Cache', 'cache', 'plugin', array( 'wp-super-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-fastest-cache', 'WP Fastest Cache', 'cache', 'plugin', array( 'wp-fastest-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-rocket', 'WP Rocket', 'cache', 'plugin', array( 'wp-rocket' ), 'partial', array( 'kuduy-cache-' ), 'paid', 'L', 'Purge built from its documentation; not tested against the plugin.' ),
	);

	/**
	 * Integrations that only work on top of another one: id => ids of the parents it needs (all of them).
	 *
	 * @var array<string, list<string>>
	 */
	private const REQUIRES = array(
		'elementor-pro'              => array( 'elementor' ),
		'ultimate-addons-elementor'  => array( 'elementor' ),
		'essential-addons-elementor' => array( 'elementor' ),
		'premium-addons-elementor'   => array( 'elementor' ),
		'happy-addons'               => array( 'elementor' ),
		'ht-mega'                    => array( 'elementor' ),
		'powerpack'                  => array( 'elementor' ),
		'qi-addons'                  => array( 'elementor' ),
		'unlimited-elements'         => array( 'elementor' ),
		'xpro-addons'                => array( 'elementor' ),
		'element-pack'               => array( 'elementor' ),
		'piotnet-addons'             => array( 'elementor' ),
		'jet-elements'               => array( 'elementor' ),
		'dynamic-content-elementor'  => array( 'elementor' ),
		'bricksforge'                => array( 'bricks' ),
		'avada-builder'              => array( 'avada' ),
	);

	/**
	 * Ids of the integrations this one needs.
	 *
	 * @param string $id Integration id.
	 * @return list<string>
	 */
	public static function requires( string $id ): array {
		return self::REQUIRES[ $id ] ?? array();
	}

	/**
	 * One catalogue row by id.
	 *
	 * @param string $id Integration id.
	 * @return array{id: string, name: string, category: string, kind: string, folders: list<string>, support: string, prefixes: list<string>, copy: string, phase: string, note: string}|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Every catalogue row as a keyed array.
	 *
	 * @return list<array{id: string, name: string, category: string, kind: string, folders: list<string>, support: string, prefixes: list<string>, copy: string, phase: string, note: string}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::ROWS as $row ) {
			$out[] = array(
				'id'       => $row[0],
				'name'     => $row[1],
				'category' => $row[2],
				'kind'     => $row[3],
				'folders'  => $row[4],
				'support'  => $row[5],
				'prefixes' => $row[6],
				'copy'     => $row[7],
				'phase'    => $row[8],
				'note'     => $row[9],
			);
		}
		return $out;
	}
}
