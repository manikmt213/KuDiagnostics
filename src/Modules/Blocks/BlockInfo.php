<?php
/**
 * Tools: kuduy-block-schema, kuduy-block-validate, kuduy-patterns, kuduy-block-usage.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Read-only block intelligence (phase C): a block type exactly as registered, a check of a post or of markup
 * against the registered types, the registered and synced patterns, and which posts use a block type.
 */
final class BlockInfo implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode schema | validate | patterns | usage.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return match ( $this->mode ) {
			'schema'   => 'kuduy-block-schema',
			'validate' => 'kuduy-block-validate',
			'patterns' => 'kuduy-patterns',
			default    => 'kuduy-block-usage',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'schema'   => __( 'Block type schema', 'kuduy-mcp' ),
			'validate' => __( 'Check blocks', 'kuduy-mcp' ),
			'patterns' => __( 'Block patterns', 'kuduy-mcp' ),
			default    => __( 'Find where a block is used', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'schema'   => 'Returns one block type exactly as registered on this site (core or a plugin such as GenerateBlocks, Kadence Blocks or Spectra): every attribute with type, enum, default, and "source" when it is stored in the block\'s HTML instead of its settings; supports; styles; variations; where it may be placed (parent, ancestor) and which blocks it accepts inside (allowed_blocks); whether it is dynamic (WordPress runs a PHP render function for it; it may still have HTML saved by the editor); and an example. Use it before writing block markup or kuduy-block-edit updates; never invent attribute names.',
			'validate' => 'Checks the blocks of a post (id) or a piece of block markup (markup) against what is registered on this site: unknown block types, attribute values of the wrong type or outside the allowed values, blocks placed where they are not allowed, HTML outside blocks, and whether WordPress would store the markup the same way. Read only.',
			'patterns' => 'Lists block patterns: registered ones (from the theme, plugins and WordPress: name, title, categories, block types they suit) and synced patterns saved on the site (id, title). With name, returns that pattern\'s block markup; insert it with kuduy-block-edit (op insert, pattern). Filter with search or category.',
			default    => 'Lists posts and pages whose content uses a block type (e.g. "core/cover", "generateblocks/element") or any block of a namespace (e.g. "kadence/"), with how often it occurs in each. Only content your account can edit is listed. Paged.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name       = array(
			'type'      => 'string',
			'maxLength' => 100,
			'pattern'   => '^[a-z0-9-]+(/[a-z0-9-]*)?$',
		);
		$properties = match ( $this->mode ) {
			'schema'   => array( 'name' => $name ),
			'validate' => array(
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'markup' => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
			),
			'patterns' => array(
				'name'     => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'category' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			default    => array(
				'name'     => $name,
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( in_array( $this->mode, array( 'schema', 'usage' ), true ) ) {
			$schema['required'] = array( 'name' );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return match ( $this->mode ) {
			'schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'name'       => array( 'type' => 'string' ),
					'attributes' => array( 'type' => 'object' ),
					'dynamic'    => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'name', 'attributes', 'dynamic' ),
			),
			'validate' => array(
				'type'       => 'object',
				'properties' => array(
					'valid'       => array( 'type' => 'boolean' ),
					'block_count' => array( 'type' => 'integer' ),
					'problems'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'round_trip'  => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'valid', 'block_count', 'problems', 'round_trip' ),
			),
			'patterns' => array(
				'type'       => 'object',
				'properties' => array(
					'patterns' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'synced'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'name'     => array( 'type' => 'string' ),
					'content'  => array( 'type' => 'string' ),
				),
			),
			default    => array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'posts' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'total', 'posts' ),
			),
		};//end match
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
	public function capability( array $input ): string|array {
		if ( 'validate' === $this->mode && isset( $input['id'] ) ) {
			return array( 'edit_post', (int) $input['id'] );
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the block type, post or pattern is unknown.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'schema'   => self::schema( (string) $input['name'] ),
			'validate' => self::validate( $input ),
			'patterns' => self::patterns( $input ),
			default    => self::usage( $input ),
		};
	}

	/**
	 * One registered block type.
	 *
	 * @param string $name Block name.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not registered.
	 */
	private static function schema( string $name ): array {
		$name = str_contains( $name, '/' ) ? $name : 'core/' . $name;
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( null === $type ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Block type ' . $name . ' is not registered on this site. See kuduy-block-types.' );
		}
		$attributes = array();
		foreach ( (array) $type->get_attributes() as $key => $definition ) {
			$attributes[ (string) $key ] = (object) (array) $definition;
		}
		$variations = array();
		foreach ( (array) $type->get_variations() as $variation ) {
			$variation    = (array) $variation;
			$variations[] = array(
				'name'       => (string) ( $variation['name'] ?? '' ),
				'title'      => (string) ( $variation['title'] ?? '' ),
				'attributes' => (object) (array) ( $variation['attributes'] ?? array() ),
			);
		}
		return array(
			'name'           => $type->name,
			'title'          => (string) $type->title,
			'description'    => (string) $type->description,
			'category'       => (string) $type->category,
			'dynamic'        => $type->is_dynamic(),
			'attributes'     => (object) $attributes,
			'supports'       => (object) (array) $type->supports,
			'styles'         => array_values( array_map( static fn( $style ) => (string) ( ( (array) $style )['name'] ?? '' ), (array) $type->styles ) ),
			'variations'     => $variations,
			'parent'         => is_array( $type->parent ) ? array_values( $type->parent ) : null,
			'ancestor'       => is_array( $type->ancestor ) ? array_values( $type->ancestor ) : null,
			'allowed_blocks' => is_array( $type->allowed_blocks ) ? array_values( $type->allowed_blocks ) : null,
			'example'        => null === $type->example ? null : (object) (array) $type->example,
		);
	}

	/**
	 * Check a post or markup.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When neither or both are given, or the post is unknown.
	 */
	private static function validate( array $input ): array {
		if ( isset( $input['id'] ) === isset( $input['markup'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give either id (a post) or markup.' );
		}
		if ( isset( $input['id'] ) ) {
			$post = Content::post( (int) $input['id'] );
			if ( null === $post ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
			}
			AuditLog::target( 'post', (string) $post->ID );
			$markup = (string) $post->post_content;
		} else {
			$markup = (string) $input['markup'];
		}
		$blocks   = parse_blocks( $markup );
		$problems = BlockTree::document_problems( $blocks );
		return array(
			'valid'       => ! $problems,
			'block_count' => count( array_filter( $blocks, array( BlockTree::class, 'counts' ) ) ),
			'problems'    => array_slice( $problems, 0, 100 ),
			'round_trip'  => serialize_blocks( $blocks ) === $markup,
		);
	}

	/**
	 * Patterns.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the named pattern is unknown.
	 */
	private static function patterns( array $input ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		if ( isset( $input['name'] ) ) {
			$pattern = $registry->get_registered( (string) $input['name'] );
			if ( ! is_array( $pattern ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No registered pattern named ' . $input['name'] . '.' );
			}
			return array(
				'name'    => (string) $pattern['name'],
				'content' => (string) $pattern['content'],
			);
		}
		$search   = strtolower( (string) ( $input['search'] ?? '' ) );
		$category = (string) ( $input['category'] ?? '' );
		$out      = array();
		foreach ( $registry->get_all_registered() as $pattern ) {
			$title = (string) ( $pattern['title'] ?? '' );
			$cats  = array_values( (array) ( $pattern['categories'] ?? array() ) );
			if ( ( '' !== $search && ! str_contains( strtolower( $pattern['name'] . ' ' . $title ), $search ) ) || ( '' !== $category && ! in_array( $category, $cats, true ) ) ) {
				continue;
			}
			$out[] = array(
				'name'        => (string) $pattern['name'],
				'title'       => $title,
				'categories'  => $cats,
				'block_types' => array_values( (array) ( $pattern['blockTypes'] ?? array() ) ),
			);
		}
		$synced = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				's'              => '' === $search ? '' : $search,
			)
		) as $post ) {
			$synced[] = array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'markup' => '<!-- wp:block {"ref":' . $post->ID . '} /-->',
			);
		}
		return array(
			'patterns' => $out,
			'synced'   => $synced,
		);
	}

	/**
	 * Posts that use a block type.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function usage( array $input ): array {
		global $wpdb;
		$name = (string) $input['name'];
		$name = str_contains( $name, '/' ) ? $name : 'core/' . $name;
		// Core blocks are stored without their namespace ("<!-- wp:paragraph").
		$stored = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$prefix = str_ends_with( $stored, '/' );
		// The serializer always writes a space after the name: "<!-- wp:name -->", "<!-- wp:name {…} -->", "<!-- wp:name /-->".
		$like = '%' . $wpdb->esc_like( '<!-- wp:' . $stored ) . ( $prefix ? '' : ' ' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, prepared, capped; no core API searches content by block.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status NOT IN ('auto-draft', 'trash', 'inherit') AND post_content LIKE %s ORDER BY ID DESC LIMIT 1000", $like ) );
		$found = array();
		foreach ( $rows as $row ) {
			if ( ! current_user_can( 'edit_post', (int) $row->ID ) ) {
				continue;
			}
			$count = preg_match_all( '/<!-- wp:' . preg_quote( $stored, '/' ) . ( $prefix ? '[a-z0-9-]+' : '' ) . '\s/', (string) $row->post_content );
			if ( $count > 0 ) {
				$found[] = array(
					'id'    => (int) $row->ID,
					'count' => (int) $count,
				);
			}
		}
		$per   = (int) ( $input['per_page'] ?? 20 );
		$page  = array_slice( $found, ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per, $per );
		$posts = array();
		foreach ( $page as $item ) {
			$post    = get_post( $item['id'] );
			$posts[] = array(
				'id'     => $item['id'],
				'title'  => $post instanceof \WP_Post ? get_the_title( $post ) : '',
				'type'   => $post instanceof \WP_Post ? $post->post_type : '',
				'status' => $post instanceof \WP_Post ? $post->post_status : '',
				'count'  => $item['count'],
			);
		}
		return array(
			'total' => count( $found ),
			'posts' => $posts,
		);
	}
}
