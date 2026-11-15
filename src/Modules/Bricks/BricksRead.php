<?php
/**
 * Tools: kuduy-bricks-pages, kuduy-bricks-get (one class, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Bricks;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Bricks pages, read only, from Bricks' documented data model (D41; Bricks Academy "Bricks Data Model" and "Content
 * Area Schema"): each content area is a flat array of elements (id, name, parent — 0 for root elements —, children
 * ids in order, settings; component instances carry cid) stored in post meta under the keys Bricks defines as
 * BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_CONTENT and BRICKS_DB_PAGE_FOOTER. Works with any Bricks version that defines
 * those constants, also when Bricks' own abilities are switched off. Changes go through Bricks' abilities
 * (kuduy-bricks-ability-run), which keep Bricks' revisions and permission checks.
 */
final class BricksRead implements Tool {

	private const AREAS = array(
		'header'  => 'BRICKS_DB_PAGE_HEADER',
		'content' => 'BRICKS_DB_PAGE_CONTENT',
		'footer'  => 'BRICKS_DB_PAGE_FOOTER',
	);

	private const MAX_TEXT = 200;

	private const MAX_DEPTH = 40;

	/**
	 * Mode.
	 *
	 * @param string $mode pages | get.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-bricks-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'pages' === $this->mode ? __( 'List Bricks pages', 'kuduy-mcp' ) : __( 'Read a Bricks page', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'pages' === $this->mode
			? 'Lists pages, posts and templates built with Bricks: id, title, post type, status, link, and which areas (header, content, footer) have Bricks elements.'
			: 'Returns the elements of one Bricks page or template as a tree: id, element name, label, text, and the settings on request (include_settings). area picks header, content (default) or footer. Read only: change Bricks pages with Bricks\' own abilities (kuduy-bricks-abilities) or in Bricks.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = 'pages' === $this->mode
			? array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			) + Content::paging_input()
			: array(
				'id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'area'             => array(
					'type'    => 'string',
					'enum'    => array_keys( self::AREAS ),
					'default' => 'content',
				),
				'include_settings' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			);
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
		return defined( 'BRICKS_DB_PAGE_CONTENT' ) ? true : 'Bricks is not active on this site.';
	}

	/**
	 * Meta key of an area.
	 *
	 * @param string $area header | content | footer.
	 */
	private static function key( string $area ): string {
		$constant = self::AREAS[ $area ] ?? 'BRICKS_DB_PAGE_CONTENT';
		return defined( $constant ) ? (string) constant( $constant ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post has no Bricks elements in that area.
	 */
	public function execute( array $input ): array {
		return 'pages' === $this->mode ? self::pages( $input ) : self::get( $input );
	}

	/**
	 * Posts with Bricks elements in any area.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function pages( array $input ): array {
		$keys = array_values( array_filter( array_map( array( self::class, 'key' ), array_keys( self::AREAS ) ) ) );
		$meta = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$meta[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}
		$args = array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bricks keeps its elements in these documented meta keys.
			'meta_query'     => $meta,
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
			$areas = array();
			foreach ( array_keys( self::AREAS ) as $area ) {
				$elements = get_post_meta( $post->ID, self::key( $area ), true );
				if ( is_array( $elements ) && $elements ) {
					$areas[] = $area;
				}
			}
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'areas'     => $areas,
				'link'      => (string) get_permalink( $post ),
			);
		}
		return array(
			'pages' => $out,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Elements of one area as a tree.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the post has no Bricks elements in that area.
	 */
	private static function get( array $input ): array {
		$id       = (int) $input['id'];
		$area     = (string) ( $input['area'] ?? 'content' );
		$post     = get_post( $id );
		$elements = $post instanceof \WP_Post ? get_post_meta( $post->ID, self::key( $area ), true ) : null;
		if ( ! $post instanceof \WP_Post || ! is_array( $elements ) || ! $elements ) {
			throw new ToolError( 'This post has no Bricks elements in the ' . esc_html( $area ) . ' area. See kuduy-bricks-pages.' );
		}
		$by_id = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}
		$with_settings = ! empty( $input['include_settings'] );
		$seen          = array();
		$tree          = array();
		foreach ( $by_id as $element_id => $element ) {
			if ( empty( $element['parent'] ) ) {
				$node = self::node( $by_id, $element_id, $with_settings, $seen, 0 );
				if ( null !== $node ) {
					$tree[] = $node;
				}
			}
		}
		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'area'          => $area,
			'element_count' => count( $by_id ),
			'elements'      => $tree,
			'link'          => (string) get_permalink( $post ),
			'read_only'     => true,
		);
	}

	/**
	 * One element and its children.
	 *
	 * @param array<string, array<string, mixed>> $by_id         Elements by id.
	 * @param string                              $id            Element id.
	 * @param bool                                $with_settings Include settings.
	 * @param array<string, true>                 $seen          Visited ids.
	 * @param int                                 $depth         Depth.
	 * @return array<string, mixed>|null
	 */
	private static function node( array $by_id, string $id, bool $with_settings, array &$seen, int $depth ): ?array {
		$element = $by_id[ $id ] ?? null;
		if ( null === $element || isset( $seen[ $id ] ) || $depth > self::MAX_DEPTH ) {
			return null;
		}
		$seen[ $id ] = true;
		$settings    = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$row         = array(
			'id'   => $id,
			'name' => (string) ( $element['name'] ?? '' ),
		);
		if ( isset( $element['label'] ) && is_string( $element['label'] ) && '' !== $element['label'] ) {
			$row['label'] = $element['label'];
		}
		if ( isset( $element['cid'] ) ) {
			$row['component'] = (string) $element['cid'];
		}
		if ( isset( $settings['text'] ) && is_string( $settings['text'] ) && '' !== trim( $settings['text'] ) ) {
			$row['text'] = mb_substr( trim( wp_strip_all_tags( $settings['text'] ) ), 0, self::MAX_TEXT );
		}
		if ( $with_settings && $settings ) {
			$row['settings'] = $settings;
		}
		$children = array();
		foreach ( (array) ( $element['children'] ?? array() ) as $child ) {
			$node = is_scalar( $child ) ? self::node( $by_id, (string) $child, $with_settings, $seen, $depth + 1 ) : null;
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
