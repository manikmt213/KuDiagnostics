<?php
/**
 * Tools: kuduy-kirki-status, kuduy-kirki-pages, kuduy-kirki-get (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Kirki;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Kirki 6 (the visual builder), read tools (D39; source of Kirki 6.4.2). A page built with Kirki has the editor mode
 * "kirki" and its data is a map of blocks (id => name, children ids, properties: tag, attributes, contents) under the
 * root block, plus style blocks stored separately, staged versions and collaboration records. Reading goes through
 * Kirki's own helper (Kirki\HelperFunctions::is_kirki_type_data()). Saving a page correctly means writing blocks,
 * style blocks, used style ids and fonts together (save_kirki_data_to_db()); KirkiEdit changes blocks only, through
 * Kirki's page manager.
 */
final class KirkiRead implements Tool {

	private const HELPER = '\Kirki\HelperFunctions';

	/**
	 * Kirki's page facade (Kirki 6.4.2 app/Supports/Facades/Page.php over App\Managers\PageManager): staged versions.
	 */
	private const PAGES = '\Kirki\App\Supports\Facades\Page';

	private const MAX_TEXT = 200;

	private const MAX_DEPTH = 40;

	/**
	 * Mode.
	 *
	 * @param string $mode status | pages | get.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-kirki-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status' => __( 'Kirki status', 'kuduy-mcp' ),
			'pages'  => __( 'List Kirki pages', 'kuduy-mcp' ),
			default  => __( 'Read a Kirki page', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status' => 'Kirki (the visual page builder, version 6 and later) on this site: version, whether your account may use the builder, and what these tools can do (kuduy-kirki-edit changes block text and attributes and removes, moves and duplicates blocks).',
			'pages'  => 'Lists pages, posts and Kirki templates built with the Kirki builder: id, title, post type, status, link.',
			default  => 'Returns the structure of a page built with Kirki: its blocks as a tree with id, name, HTML tag and text. include_properties: true adds each block\'s stored properties. Kirki saves edits as versions and shows visitors the published one: version "published" (default) reads that, "latest" the newest saved version not yet published; unpublished_changes says whether one exists. Change text and attributes, remove, move or duplicate blocks with kuduy-kirki-edit (block ids from version "latest"); styles in Kirki; title, status and other post fields with the post tools.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = match ( $this->mode ) {
			'pages' => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			) + Content::paging_input(),
			'get'   => array(
				'id'                 => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_properties' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'version'            => array(
					'type'    => 'string',
					'enum'    => array( 'published', 'latest' ),
					'default' => 'published',
				),
			),
			default => array(),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'get' === $this->mode ? array( 'id' ) : array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
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
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return 'get' === $this->mode && $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return self::available();
	}

	/**
	 * True when the Kirki builder (6+) is loaded, else the reason. Older Kirki versions were a Customizer framework
	 * without these helpers.
	 */
	public static function available(): bool|string {
		return defined( 'KIRKI_VERSION' ) && defined( 'KIRKI_META_NAME_FOR_POST_EDITOR_MODE' )
			&& is_callable( array( self::HELPER, 'is_kirki_type_data' ) ) && is_callable( array( self::HELPER, 'user_has_builder_access' ) )
			? true
			: 'The Kirki builder (version 6 or later) is not active on this site.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is not a Kirki page.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'status' => array(
				'version'       => (string) constant( 'KIRKI_VERSION' ),
				'can_build'     => (bool) call_user_func( array( self::HELPER, 'user_has_builder_access' ) ),
				'supports'      => array( 'list pages', 'read page structure', 'change text and attributes, remove, move and duplicate blocks (kuduy-kirki-edit), saved as an unpublished version or published' ),
				'not_supported' => 'Changing styles, classes and fonts, and adding new elements: style blocks are kept apart from the page and are changed in Kirki.',
			),
			'pages'  => self::pages( $input ),
			default  => self::get( (int) $input['id'], ! empty( $input['include_properties'] ), 'latest' === ( $input['version'] ?? 'published' ) ),
		};
	}

	/**
	 * Posts whose editor mode is Kirki.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function pages( array $input ): array {
		$args = array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'meta_key'       => (string) constant( 'KIRKI_META_NAME_FOR_POST_EDITOR_MODE' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Kirki's own marker for built pages.
			'meta_value'     => 'kirki', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'orderby'        => 'modified',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		$query = new \WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'link'      => (string) get_permalink( $post ),
			);
		}
		return array(
			'pages' => $out,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Structure of one Kirki page.
	 *
	 * @param int  $id              Post id.
	 * @param bool $with_properties Include stored properties.
	 * @param bool $latest          Read the newest unpublished version instead of the published one.
	 * @return array<string, mixed>
	 * @throws ToolError When the post is not built with Kirki.
	 */
	private static function get( int $id, bool $with_properties, bool $latest = false ): array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || ! is_array( call_user_func( array( self::HELPER, 'is_kirki_type_data' ), $post->ID ) ) ) {
			throw new ToolError( 'No Kirki page with this id. See kuduy-kirki-pages.' );
		}
		// Kirki saves each edit as a staged version and publishes one of them (seen on Kirki 6.4.2: Save in the builder
		// adds an unpublished version, the published one can be empty).
		$published = self::facade( 'get_published_stage_version', $post->ID );
		$unpub     = self::facade( 'get_most_recent_unpublished_stage_version', $post->ID );
		$newer     = $unpub > 0 && $unpub > $published;
		$data      = call_user_func( array( self::HELPER, 'is_kirki_type_data' ), $post->ID, $latest && $newer ? $unpub : false );
		$data      = is_array( $data ) ? $data : array();
		$blocks    = is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array();
		$seen      = array();
		$root      = isset( $blocks['root'] ) ? 'root' : (string) ( array_key_first( $blocks ) ?? '' );
		$tree      = '' !== $root ? self::tree( $blocks, $root, $with_properties, $seen, 0 ) : null;
		return array(
			'id'                  => $post->ID,
			'title'               => $post->post_title,
			'status'              => $post->post_status,
			'version'             => $latest && $newer ? 'latest' : 'published',
			'unpublished_changes' => $newer,
			'block_count'         => count( $blocks ),
			'root'                => $tree,
			'link'                => (string) get_permalink( $post ),
			'read_only'           => true !== ( new KirkiEdit() )->is_available(),
		);
	}

	/**
	 * A staged version number from Kirki's page facade, or 0 when there is none (or the facade is missing).
	 *
	 * @param string $method get_published_stage_version | get_most_recent_unpublished_stage_version.
	 * @param int    $post_id Post id.
	 */
	private static function facade( string $method, int $post_id ): int {
		if ( ! class_exists( ltrim( self::PAGES, '\\' ) ) || ! is_callable( array( self::PAGES, $method ) ) ) {
			return 0;
		}
		$version = call_user_func( array( self::PAGES, $method ), $post_id );
		return is_numeric( $version ) ? (int) $version : 0;
	}

	/**
	 * One block and its children.
	 *
	 * @param array<string, mixed> $blocks          Blocks by id.
	 * @param string               $id              Block id.
	 * @param bool                 $with_properties Include stored properties.
	 * @param array<string, true>  $seen            Ids already visited.
	 * @param int                  $depth           Depth.
	 * @return array<string, mixed>|null
	 */
	private static function tree( array $blocks, string $id, bool $with_properties, array &$seen, int $depth ): ?array {
		$block = $blocks[ $id ] ?? null;
		if ( ! is_array( $block ) || isset( $seen[ $id ] ) || $depth > self::MAX_DEPTH ) {
			return null;
		}
		$seen[ $id ] = true;
		$properties  = is_array( $block['properties'] ?? null ) ? $block['properties'] : array();
		$row         = array(
			'id'   => $id,
			'name' => (string) ( $block['name'] ?? '' ),
		);
		if ( isset( $properties['tag'] ) && is_string( $properties['tag'] ) ) {
			$row['tag'] = $properties['tag'];
		}
		$text = array();
		foreach ( (array) ( $properties['contents'] ?? array() ) as $content ) {
			if ( is_string( $content ) && '' !== trim( $content ) ) {
				$text[] = trim( wp_strip_all_tags( $content ) );
			}
		}
		if ( $text ) {
			$row['text'] = mb_substr( implode( ' ', $text ), 0, self::MAX_TEXT );
		}
		if ( $with_properties && $properties ) {
			$row['properties'] = $properties;
		}
		$children = array();
		foreach ( (array) ( $block['children'] ?? array() ) as $child ) {
			$node = is_string( $child ) || is_int( $child ) ? self::tree( $blocks, (string) $child, $with_properties, $seen, $depth + 1 ) : null;
			if ( null !== $node ) {
				$children[] = $node;
			}
		}
		if ( $children ) {
			$row['children'] = $children;
		}
		return $row;
	}
}
