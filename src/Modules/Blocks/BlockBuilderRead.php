<?php
/**
 * Tools: kuduy-divi-pages, kuduy-divi-get, kuduy-etch-pages, kuduy-etch-get (one class, two builders, two modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Builders that save pages as WordPress blocks in post_content (D41), read with WordPress' own parse_blocks(): Divi 5
 * (blocks named "divi/…"; Divi 4 pages are et_pb_* shortcodes and are reported as such) and Etch (Etch docs, "Block
 * authoring": everything built is saved as custom Gutenberg blocks in WordPress). Read only: neither builder documents
 * how its block attributes may be changed outside its editor. The block name prefixes come from third-party
 * references and are UNVERIFIED until checked on a site with the builder.
 */
final class BlockBuilderRead implements Tool {

	/**
	 * Builders: id => label, block name prefix, how to detect it.
	 *
	 * @var array<string, array{label: string, prefix: string}>
	 */
	public const BUILDERS = array(
		'divi' => array(
			'label'  => 'Divi 5',
			'prefix' => 'divi/',
		),
		'etch' => array(
			'label'  => 'Etch',
			'prefix' => 'etch/',
		),
	);

	private const MAX_TEXT = 200;

	private const MAX_DEPTH = 40;

	/**
	 * Builder and mode.
	 *
	 * @param string $builder divi | etch.
	 * @param string $mode    pages | get.
	 */
	public function __construct( private string $builder, private string $mode ) {}

	/**
	 * Builder settings.
	 *
	 * @return array{label: string, prefix: string}
	 */
	private function config(): array {
		return self::BUILDERS[ $this->builder ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->builder . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'pages' === $this->mode
			/* translators: %s: page builder name. */
			? sprintf( __( 'List %s pages', 'kuduy-mcp' ), $this->config()['label'] )
			/* translators: %s: page builder name. */
			: sprintf( __( 'Read a %s page', 'kuduy-mcp' ), $this->config()['label'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$label = $this->config()['label'];
		$extra = 'divi' === $this->builder ? ' Divi 4 pages (shortcodes) are listed with format "divi4" and are not read as a tree.' : '';
		return 'pages' === $this->mode
			? 'Lists pages, posts and templates built with ' . $label . ' (saved as WordPress blocks): id, title, post type, status, link.' . $extra
			: 'Returns the structure of a page built with ' . $label . ': its blocks as a tree with name, text and, on request (include_attributes), every stored attribute. Read only: edit the page in ' . $label . '.' . $extra;
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
				'id'                 => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_attributes' => array(
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
		return self::active( $this->builder ) ? true : $this->config()['label'] . ' is not active on this site.';
	}

	/**
	 * Whether a builder is active: its block types are registered, or (Divi) the Divi theme or Divi Builder plugin is
	 * active.
	 *
	 * @param string $builder divi | etch.
	 */
	public static function active( string $builder ): bool {
		$prefix = self::BUILDERS[ $builder ]['prefix'] ?? '';
		if ( '' === $prefix ) {
			return false;
		}
		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			if ( str_starts_with( (string) $name, $prefix ) ) {
				return true;
			}
		}
		if ( 'divi' === $builder ) {
			if ( 'Divi' === get_template() ) {
				return true;
			}
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			return is_plugin_active( 'divi-builder/divi-builder.php' );
		}
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is not built with the builder.
	 */
	public function execute( array $input ): array {
		return 'pages' === $this->mode ? $this->pages( $input ) : $this->get( (int) $input['id'], ! empty( $input['include_attributes'] ) );
	}

	/**
	 * Format of a post's content for this builder: "blocks", "divi4" or "".
	 *
	 * @param string $content Post content.
	 */
	private function format( string $content ): string {
		if ( str_contains( $content, '<!-- wp:' . $this->config()['prefix'] ) ) {
			return 'blocks';
		}
		return 'divi' === $this->builder && str_contains( $content, '[et_pb_section' ) ? 'divi4' : '';
	}

	/**
	 * Posts built with the builder.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function pages( array $input ): array {
		global $wpdb;
		$per    = (int) ( $input['per_page'] ?? 20 );
		$page   = (int) ( $input['page'] ?? 1 );
		$like   = array( '%' . $wpdb->esc_like( '<!-- wp:' . $this->config()['prefix'] ) . '%' );
		$search = (string) ( $input['search'] ?? '' );
		if ( 'divi' === $this->builder ) {
			$like[] = '%' . $wpdb->esc_like( '[et_pb_section' ) . '%';
		}
		$where = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );
		$args  = $like;
		$sql   = "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','future','draft','pending','private') AND post_type NOT IN ('revision','attachment') AND ( {$where} )";
		if ( '' !== $search ) {
			$sql   .= ' AND post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql .= ' ORDER BY post_modified DESC LIMIT 500';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read only; the builder marker is in post_content, which WP_Query cannot search; every value is a placeholder.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		$ids = array_values( array_filter( $ids, array( self::class, 'listed' ) ) );
		$out = array();
		foreach ( array_slice( $ids, ( $page - 1 ) * $per, $per ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'format'    => $this->format( $post->post_content ),
				'link'      => (string) get_permalink( $post ),
			);
		}
		return array(
			'pages' => $out,
			'total' => count( $ids ),
		);
	}

	/**
	 * Structure of one page.
	 *
	 * @param int  $id              Post id.
	 * @param bool $with_attributes Include attributes.
	 * @return array<string, mixed>
	 * @throws ToolError When the post is not built with the builder.
	 */
	private function get( int $id, bool $with_attributes ): array {
		$post   = get_post( $id );
		$format = $post instanceof \WP_Post ? $this->format( $post->post_content ) : '';
		if ( ! $post instanceof \WP_Post || '' === $format ) {
			throw new ToolError( 'No ' . esc_html( $this->config()['label'] ) . ' page with this id. See kuduy-' . esc_html( $this->builder ) . '-pages.' );
		}
		$out = array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'format'    => $format,
			'link'      => (string) get_permalink( $post ),
			'read_only' => true,
		);
		if ( 'divi4' === $format ) {
			$out['note'] = 'This page is saved in the Divi 4 shortcode format; it is not read as a block tree. Divi 5 converts it when the page is opened and saved in Divi 5.';
			return $out;
		}
		$count         = 0;
		$out['blocks'] = $this->tree( parse_blocks( $post->post_content ), $with_attributes, $count, 0 );
		$out['count']  = $count;
		return $out;
	}

	/**
	 * Whether a post goes in the pages list: one the user may edit, of a post type with an admin screen (Divi 5 also
	 * keeps module markup in its own internal post type, _et_pb_speculation, seen on Divi 5.19.0).
	 *
	 * @param int $id Post id.
	 */
	private static function listed( int $id ): bool {
		$type = get_post_type_object( (string) get_post_type( $id ) );
		return null !== $type && $type->show_ui && current_user_can( 'edit_post', $id );
	}

	/**
	 * Text a block keeps in its attributes rather than its HTML. Divi 5 stores a module's text as
	 * {"<field>": {"innerContent": {"desktop": {"value": "…" or {"text": "…"}}}}} (Divi 5.19.0, heading and button
	 * saved by its Visual Builder); admin labels sit under module.meta and are not page text.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function attribute_text( array $attrs ): string {
		$parts = array();
		foreach ( $attrs as $key => $field ) {
			if ( 'module' === $key || ! is_array( $field ) ) {
				continue;
			}
			$value = $field['innerContent']['desktop']['value'] ?? null;
			if ( is_array( $value ) ) {
				$value = $value['text'] ?? null;
			}
			if ( is_string( $value ) ) {
				$parts[] = wp_strip_all_tags( $value );
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Blocks as a tree, without empty whitespace blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks          Parsed blocks.
	 * @param bool                             $with_attributes Include attributes.
	 * @param int                              $count           Running count.
	 * @param int                              $depth           Depth.
	 * @return list<array<string, mixed>>
	 */
	private function tree( array $blocks, bool $with_attributes, int &$count, int $depth ): array {
		$out = array();
		if ( $depth > self::MAX_DEPTH ) {
			return $out;
		}
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			++$count;
			$row  = array( 'name' => $name );
			$text = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			if ( '' === $text ) {
				$text = self::attribute_text( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
			}
			if ( '' !== $text ) {
				$row['text'] = mb_substr( preg_replace( '/\s+/', ' ', $text ) ?? $text, 0, self::MAX_TEXT );
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( $with_attributes && $attrs ) {
				$row['attributes'] = $attrs;
			}
			$children = $this->tree( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $with_attributes, $count, $depth + 1 );
			if ( $children ) {
				$row['children'] = $children;
			}
			$out[] = $row;
		}//end foreach
		return $out;
	}
}
