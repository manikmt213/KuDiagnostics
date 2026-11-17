<?php
/**
 * Holds the tools for one request.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Modules\Blocks\BlockBuilderRead;
use Kuduy\Mcp\Modules\Bricks\BricksRead;
use Kuduy\Mcp\Modules\Brizy\BrizyRead;
use Kuduy\Mcp\Modules\Core\AstraAbilities;
use Kuduy\Mcp\Modules\Core\BuilderAbilities;
use Kuduy\Mcp\Modules\Core\CheckUpdate;
use Kuduy\Mcp\Modules\Core\Comments;
use Kuduy\Mcp\Modules\Core\Diagnostics;
use Kuduy\Mcp\Modules\Core\Menus;
use Kuduy\Mcp\Modules\Core\Plugins;
use Kuduy\Mcp\Modules\Core\PostCreate;
use Kuduy\Mcp\Modules\Core\PostGet;
use Kuduy\Mcp\Modules\Core\PostList;
use Kuduy\Mcp\Modules\Core\PostMeta;
use Kuduy\Mcp\Modules\Core\PostDuplicate;
use Kuduy\Mcp\Modules\Core\Shortcodes;
use Kuduy\Mcp\Modules\Core\PostTrash;
use Kuduy\Mcp\Modules\Core\PostTypes;
use Kuduy\Mcp\Modules\Core\PostUpdate;
use Kuduy\Mcp\Modules\Core\Revisions;
use Kuduy\Mcp\Modules\Core\Settings;
use Kuduy\Mcp\Modules\Code\CodeSnippetSave;
use Kuduy\Mcp\Modules\Code\CodeSnippets;
use Kuduy\Mcp\Modules\Core\IntegrationDetail;
use Kuduy\Mcp\Modules\Core\Integrations;
use Kuduy\Mcp\Modules\Core\SiteInfo;
use Kuduy\Mcp\Modules\Core\SnapshotList;
use Kuduy\Mcp\Modules\Core\SnapshotRestore;
use Kuduy\Mcp\Modules\Core\TermList;
use Kuduy\Mcp\Modules\Core\Themes;
use Kuduy\Mcp\Modules\Core\ThemeSettings;
use Kuduy\Mcp\Modules\Core\TermWrite;
use Kuduy\Mcp\Modules\Core\Users;
use Kuduy\Mcp\Modules\Beaver\BeaverRead;
use Kuduy\Mcp\Modules\Beaver\BeaverWrite;
use Kuduy\Mcp\Modules\Elementor\ElementorAbilities;
use Kuduy\Mcp\Modules\Elementor\ElementorDocs;
use Kuduy\Mcp\Modules\Elementor\ElementorPopup;
use Kuduy\Mcp\Modules\Elementor\ElementorRead;
use Kuduy\Mcp\Modules\Elementor\ElementorVerify;
use Kuduy\Mcp\Modules\Elementor\ElementorWrite;
use Kuduy\Mcp\Modules\Elementor\ThemeBuilder;
use Kuduy\Mcp\Jobs\JobTools;
use Kuduy\Mcp\Modules\Acf\AcfTools;
use Kuduy\Mcp\Modules\Blocks\BlockEdit;
use Kuduy\Mcp\Modules\Blocks\BlockInfo;
use Kuduy\Mcp\Modules\Blocks\BlockPluginTools;
use Kuduy\Mcp\Modules\Blocks\SpectraPopups;
use Kuduy\Mcp\Modules\Blocks\BlockTools;
use Kuduy\Mcp\Modules\Blocks\GlobalStyles;
use Kuduy\Mcp\Modules\Blocks\NavigationTools;
use Kuduy\Mcp\Modules\Blocks\SyncedPatterns;
use Kuduy\Mcp\Modules\Blocks\TemplateTools;
use Kuduy\Mcp\Modules\Blocks\WidgetTools;
use Kuduy\Mcp\Modules\Cache\CacheTools;
use Kuduy\Mcp\Modules\Dev\DevCode;
use Kuduy\Mcp\Modules\Dev\DevDatabase;
use Kuduy\Mcp\Modules\Dev\DevFiles;
use Kuduy\Mcp\Modules\Multilingual\LanguageTools;
use Kuduy\Mcp\Modules\Types\TypeTools;
use Kuduy\Mcp\Modules\Forms\Cf7Save;
use Kuduy\Mcp\Modules\Forms\FormEntries;
use Kuduy\Mcp\Modules\Forms\FormsTools;
use Kuduy\Mcp\Modules\Kirki\KirkiRead;
use Kuduy\Mcp\Modules\Media\MediaDelete;
use Kuduy\Mcp\Modules\Media\MediaFiles;
use Kuduy\Mcp\Modules\Avada\AvadaRead;
use Kuduy\Mcp\Modules\Avada\AvadaWrite;
use Kuduy\Mcp\Modules\WpBakery\WpBakeryRead;
use Kuduy\Mcp\Modules\WpBakery\WpBakeryWrite;
use Kuduy\Mcp\Modules\Media\MediaLibrary;
use Kuduy\Mcp\Modules\Media\MediaTools;
use Kuduy\Mcp\Modules\Seo\SeoAudit;
use Kuduy\Mcp\Modules\Seo\SeoTerms;
use Kuduy\Mcp\Modules\Seo\SeoTools;
use Kuduy\Mcp\Modules\Seo\RankMathExtras;
use Kuduy\Mcp\Modules\Seo\SeoVerify;
use Kuduy\Mcp\Modules\Woo\CatalogTools;
use Kuduy\Mcp\Modules\Woo\CouponTools;
use Kuduy\Mcp\Modules\Woo\CustomerTools;
use Kuduy\Mcp\Modules\Woo\OrderRead;
use Kuduy\Mcp\Modules\Woo\OrderUpdate;
use Kuduy\Mcp\Modules\Woo\OrderWrite;
use Kuduy\Mcp\Modules\Woo\ProductRead;
use Kuduy\Mcp\Modules\Woo\ProductWrite;
use Kuduy\Mcp\Modules\Woo\Report;
use Kuduy\Mcp\Modules\Woo\ReviewTools;
use Kuduy\Mcp\Modules\Woo\StoreSettings;
use Kuduy\Mcp\Modules\Woo\StoreConfirmed;
use Kuduy\Mcp\Modules\Woo\StoreSettingsWrite;
use Kuduy\Mcp\Modules\Woo\VariationWrite;
use Kuduy\Mcp\Modules\Woo\WooSettings;
use Kuduy\Mcp\Modules\Woo\WooStatus;

/**
 * Built per request so tools and integrations reflect the current state.
 */
final class ToolRegistry {

	/**
	 * Tools by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Registry with the built-in tools plus those added on `kuduy_mcp_register_tools`.
	 */
	public static function create(): self {
		$registry = new self();
		$registry->register( new SiteInfo() );
		$registry->register( new Integrations() );
		$registry->register( new IntegrationDetail( 'get' ) );
		$registry->register( new IntegrationDetail( 'diagnose' ) );
		foreach ( array( 'list', 'get', 'deactivate', 'trash' ) as $mode ) {
			$registry->register( new CodeSnippets( $mode ) );
		}
		foreach ( array( 'save', 'php-save' ) as $mode ) {
			$registry->register( new CodeSnippetSave( $mode ) );
		}
		$registry->register( new SnapshotList() );
		$registry->register( new SnapshotRestore() );
		$registry->register( new CheckUpdate() );
		$registry->register( new PostTypes() );
		$registry->register( new PostList() );
		$registry->register( new PostGet() );
		$registry->register( new TermList() );
		$registry->register( new PostCreate() );
		$registry->register( new PostUpdate() );
		$registry->register( new PostTrash() );
		$registry->register( new PostDuplicate() );
		$registry->register( new Shortcodes() );
		foreach ( array( 'create', 'update', 'delete' ) as $mode ) {
			$registry->register( new TermWrite( $mode ) );
		}
		foreach ( array( 'list', 'moderate', 'reply' ) as $mode ) {
			$registry->register( new Comments( $mode ) );
		}
		$registry->register( new Revisions( 'list' ) );
		$registry->register( new Revisions( 'restore' ) );
		$registry->register( new PostMeta( 'get' ) );
		$registry->register( new PostMeta( 'set' ) );
		foreach ( array( 'list', 'get', 'create', 'update', 'roles' ) as $mode ) {
			$registry->register( new Users( $mode ) );
		}
		foreach ( array( 'list', 'get', 'create', 'item-add', 'item-update', 'item-delete', 'assign' ) as $mode ) {
			$registry->register( new Menus( $mode ) );
		}
		$registry->register( new Settings( 'get' ) );
		$registry->register( new Settings( 'update' ) );
		foreach ( array( 'list', 'install', 'activate', 'deactivate', 'update' ) as $mode ) {
			$registry->register( new Plugins( $mode ) );
		}
		foreach ( array( 'list', 'install', 'switch', 'update' ) as $mode ) {
			$registry->register( new Themes( $mode ) );
		}
		foreach ( array( 'facts', 'settings', 'settings-update' ) as $mode ) {
			$registry->register( new ThemeSettings( $mode ) );
		}
		foreach ( array( 'abilities', 'ability-get', 'ability-run' ) as $mode ) {
			$registry->register( new AstraAbilities( $mode ) );
		}
		foreach ( array_keys( BuilderAbilities::BUILDERS ) as $builder ) {
			foreach ( array( 'abilities', 'ability-get', 'ability-run' ) as $mode ) {
				$registry->register( new BuilderAbilities( $builder, $mode ) );
			}
		}
		foreach ( array( 'health', 'cron', 'rewrite', 'rewrite-flush', 'hooks' ) as $mode ) {
			$registry->register( new Diagnostics( $mode ) );
		}
		foreach ( array( 'documents', 'get', 'widgets', 'schema', 'kit' ) as $mode ) {
			$registry->register( new ElementorRead( $mode ) );
		}
		foreach ( array( 'edit', 'create', 'kit-update' ) as $mode ) {
			$registry->register( new ElementorWrite( $mode ) );
		}
		$registry->register( new ElementorVerify() );
		foreach ( array( 'duplicate', 'document-settings', 'document-settings-update', 'dynamic-tags', 'dynamic-set' ) as $mode ) {
			$registry->register( new ElementorDocs( $mode ) );
		}
		foreach ( array( 'globals', 'classes', 'variables' ) as $mode ) {
			$registry->register( new ElementorAbilities( $mode ) );
		}
		foreach ( array( 'templates', 'conditions' ) as $mode ) {
			$registry->register( new ThemeBuilder( $mode ) );
		}
		foreach ( array( 'settings', 'settings-update' ) as $mode ) {
			$registry->register( new ElementorPopup( $mode ) );
		}
		foreach ( array( 'status', 'layouts', 'get', 'modules', 'module-schema' ) as $mode ) {
			$registry->register( new BeaverRead( $mode ) );
		}
		foreach ( array( 'edit', 'create' ) as $mode ) {
			$registry->register( new BeaverWrite( $mode ) );
		}
		foreach ( array( 'status', 'pages', 'get' ) as $mode ) {
			$registry->register( new BrizyRead( $mode ) );
		}
		foreach ( array( 'status', 'pages', 'get' ) as $mode ) {
			$registry->register( new KirkiRead( $mode ) );
		}
		foreach ( array( 'pages', 'get' ) as $mode ) {
			$registry->register( new BricksRead( $mode ) );
		}
		foreach ( array_keys( BlockBuilderRead::BUILDERS ) as $builder ) {
			foreach ( array( 'pages', 'get' ) as $mode ) {
				$registry->register( new BlockBuilderRead( $builder, $mode ) );
			}
		}
		foreach ( array( 'status', 'get', 'update' ) as $mode ) {
			$registry->register( new SeoTools( $mode ) );
		}
		$registry->register( new SeoVerify() );
		$registry->register( new SeoAudit() );
		foreach ( array_keys( SeoTerms::PLUGINS ) as $plugin ) {
			foreach ( array( 'term-seo', 'term-seo-update', 'settings' ) as $mode ) {
				$registry->register( new SeoTerms( $plugin, $mode ) );
			}
		}
		foreach ( array( 'redirects', 'redirect-save', 'redirect-trash', 'schema-get', 'schema-save', 'schema-delete', 'sitemap' ) as $mode ) {
			$registry->register( new RankMathExtras( $mode ) );
		}
		foreach ( array( 'list', 'get', 'update' ) as $mode ) {
			$registry->register( new MediaTools( $mode ) );
		}
		foreach ( array( 'usage', 'upload', 'regenerate' ) as $mode ) {
			$registry->register( new MediaLibrary( $mode ) );
		}
		foreach ( array( 'replace', 'rename' ) as $mode ) {
			$registry->register( new MediaFiles( $mode ) );
		}
		$registry->register( new MediaDelete() );
		foreach ( array( 'start', 'status', 'cancel', 'retry' ) as $mode ) {
			$registry->register( new JobTools( $mode ) );
		}
		$registry->register( new WooStatus() );
		foreach ( CatalogTools::MODES as $mode ) {
			$registry->register( new CatalogTools( $mode ) );
		}
		foreach ( array( 'product-delete', 'attribute-delete', 'refund-create' ) as $mode ) {
			$registry->register( new StoreConfirmed( $mode ) );
		}
		$registry->register( new OrderUpdate() );
		foreach ( array( 'get', 'update' ) as $mode ) {
			$registry->register( new WooSettings( $mode ) );
		}
		foreach ( array( 'list', 'get', 'variations' ) as $mode ) {
			$registry->register( new ProductRead( $mode ) );
		}
		foreach ( array( 'create', 'update', 'stock', 'trash' ) as $mode ) {
			$registry->register( new ProductWrite( $mode ) );
		}
		foreach ( array( 'create', 'update' ) as $mode ) {
			$registry->register( new VariationWrite( $mode ) );
		}
		foreach ( array( 'list', 'get' ) as $mode ) {
			$registry->register( new OrderRead( $mode ) );
		}
		foreach ( array( 'status', 'note' ) as $mode ) {
			$registry->register( new OrderWrite( $mode ) );
		}
		foreach ( array( 'list', 'get', 'create', 'update', 'trash' ) as $mode ) {
			$registry->register( new CouponTools( $mode ) );
		}
		foreach ( array( 'list', 'get', 'update' ) as $mode ) {
			$registry->register( new CustomerTools( $mode ) );
		}
		foreach ( array( 'list', 'moderate', 'reply' ) as $mode ) {
			$registry->register( new ReviewTools( $mode ) );
		}
		foreach ( array( 'shipping', 'tax', 'gateways' ) as $mode ) {
			$registry->register( new StoreSettings( $mode ) );
		}
		foreach ( array( 'zone', 'method', 'tax', 'tax-delete' ) as $mode ) {
			$registry->register( new StoreSettingsWrite( $mode ) );
		}
		$registry->register( new Report() );
		foreach ( array( 'status', 'field-groups', 'get', 'update' ) as $mode ) {
			$registry->register( new AcfTools( $mode ) );
		}
		foreach ( array( 'status', 'list', 'get' ) as $mode ) {
			$registry->register( new FormsTools( $mode ) );
		}
		foreach ( array( 'entries', 'entry-get', 'entry-update', 'entry-note' ) as $mode ) {
			$registry->register( new FormEntries( $mode ) );
		}
		foreach ( array( 'save', 'mail' ) as $mode ) {
			$registry->register( new Cf7Save( $mode ) );
		}
		foreach ( array( 'types', 'post' ) as $mode ) {
			$registry->register( new BlockTools( $mode ) );
		}
		$registry->register( new BlockEdit() );
		foreach ( array( 'schema', 'validate', 'patterns', 'usage' ) as $mode ) {
			$registry->register( new BlockInfo( $mode ) );
		}
		foreach ( array(
			'generateblocks' => array( 'status', 'settings-update' ),
			'kadence-blocks' => array( 'status', 'settings-update' ),
			'spectra'        => array( 'status', 'settings-update', 'block-toggle' ),
		) as $plugin => $modes ) {
			foreach ( $modes as $mode ) {
				$registry->register( new BlockPluginTools( $plugin, $mode ) );
			}
		}
		foreach ( array( 'spectra', 'spectra-blocks' ) as $plugin ) {
			foreach ( array( 'popups', 'popup-get', 'popup-save', 'popup-trash' ) as $mode ) {
				$registry->register( new SpectraPopups( $plugin, $mode ) );
			}
		}
		foreach ( array( 'list', 'get', 'create', 'update', 'revert' ) as $mode ) {
			$registry->register( new TemplateTools( $mode ) );
		}
		foreach ( array( 'get', 'save', 'trash' ) as $mode ) {
			$registry->register( new SyncedPatterns( $mode ) );
		}
		foreach ( array( 'get', 'update' ) as $mode ) {
			$registry->register( new GlobalStyles( $mode ) );
		}
		foreach ( array( 'list', 'save', 'remove' ) as $mode ) {
			$registry->register( new WidgetTools( $mode ) );
		}
		foreach ( array( 'list', 'get', 'save', 'trash' ) as $mode ) {
			$registry->register( new NavigationTools( $mode ) );
		}
		foreach ( array( 'status', 'elements', 'tree', 'structure', 'content', 'library', 'global-options', 'validate' ) as $mode ) {
			$registry->register( new AvadaRead( $mode ) );
		}
		foreach ( array( 'create', 'add-container', 'add-column', 'add-element', 'update-element', 'update-container', 'update-column', 'move-element', 'duplicate-element', 'delete-element', 'replace', 'page-css' ) as $mode ) {
			$registry->register( new AvadaWrite( $mode ) );
		}
		foreach ( array( 'tree', 'structure', 'content', 'elements', 'validate' ) as $mode ) {
			$registry->register( new WpBakeryRead( $mode ) );
		}
		foreach ( array( 'create', 'add-row', 'add-column', 'add-element', 'update-element', 'update-row', 'update-column', 'move-element', 'duplicate-element', 'delete-element', 'replace' ) as $mode ) {
			$registry->register( new WpBakeryWrite( $mode ) );
		}
		foreach ( array( 'status', 'purge' ) as $mode ) {
			$registry->register( new CacheTools( $mode ) );
		}
		foreach ( array( 'status', 'get', 'set', 'link', 'translate' ) as $mode ) {
			$registry->register( new LanguageTools( $mode ) );
		}
		foreach ( array( 'list', 'read', 'search', 'log' ) as $mode ) {
			$registry->register( new DevFiles( $mode ) );
		}
		foreach ( array( 'schema', 'query' ) as $mode ) {
			$registry->register( new DevDatabase( $mode ) );
		}
		foreach ( array( 'write', 'php' ) as $mode ) {
			$registry->register( new DevCode( $mode ) );
		}
		foreach ( array( 'list', 'save', 'remove' ) as $mode ) {
			$registry->register( new TypeTools( $mode ) );
		}
		do_action( 'kuduy_mcp_register_tools', $registry );
		return $registry;
	}

	/**
	 * Add a tool; a later registration with the same name replaces the earlier one.
	 *
	 * @param Tool $tool Tool.
	 */
	public function register( Tool $tool ): void {
		$this->tools[ $tool->name() ] = $tool;
	}

	/**
	 * Tool by name, or null.
	 *
	 * @param string $name Tool name.
	 */
	public function get( string $name ): ?Tool {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * All registered tools.
	 *
	 * @return array<string, Tool>
	 */
	public function all(): array {
		return $this->tools;
	}
}
