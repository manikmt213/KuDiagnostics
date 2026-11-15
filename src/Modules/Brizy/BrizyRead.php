<?php
/**
 * Tools: kuduy-brizy-status, kuduy-brizy-pages, kuduy-brizy-get (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Brizy;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Brizy, read only (D39; source of Brizy 2.8.26). A Brizy page keeps its editor data (JSON: sections, rows, columns,
 * elements) and, separately, the HTML compiled from it. Brizy compiles in its own editor in the browser and
 * recompiles on the server only when its version changes (Brizy_Editor_Compiler::needsCompile()), through Brizy's
 * compiler service. Editor data changed here would therefore not reach the public page, so these tools read through
 * Brizy's model (Brizy_Editor_Post::get(), get_editor_data()) and change nothing.
 */
final class BrizyRead implements Tool {

	private const MAX_TEXT = 200;

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
		return 'kuduy-brizy-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status' => __( 'Brizy status', 'kuduy-mcp' ),
			'pages'  => __( 'List Brizy pages', 'kuduy-mcp' ),
			default  => __( 'Read a Brizy page', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status' => 'Brizy on this site: version, free or Pro, the post types it works with, whether your account may use the builder, and what these tools can do. Brizy pages are read only here: Brizy builds the public page in its own editor, so a layout changed from outside would not show on the site.',
			'pages'  => 'Lists pages, posts and other content built with Brizy: id, title, post type, status, link, and whether the page was compiled with the installed Brizy version.',
			default  => 'Returns the structure of a Brizy page: sections > rows > columns > elements with their type, id and text. include_values: true adds every stored setting of each element. Read only: to change a Brizy page, edit it in Brizy (edit_url) or change title, status and other post fields with the post tools.',
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
				'id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_values' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			default => array(),
		};
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
	 * True when Brizy is loaded, else the reason.
	 */
	public static function available(): bool|string {
		return defined( 'BRIZY_VERSION' ) && defined( 'BRIZY_EDITOR_VERSION' ) && class_exists( 'Brizy_Editor' ) && class_exists( 'Brizy_Editor_Post' ) && class_exists( 'Brizy_Editor_Entity' ) && class_exists( 'Brizy_Editor_User' )
			? true
			: 'Brizy is not active on this site.';
	}

	/**
	 * Post types Brizy works with.
	 *
	 * @return list<string>
	 */
	private static function post_types(): array {
		return array_values( array_map( 'strval', (array) \Brizy_Editor::get()->supported_post_types() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is not a Brizy page.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'status' => self::status(),
			'pages'  => self::pages( $input ),
			default  => self::get( (int) $input['id'], ! empty( $input['include_values'] ) ),
		};
	}

	/**
	 * What is installed.
	 *
	 * @return array<string, mixed>
	 */
	private static function status(): array {
		return array(
			'version'        => (string) constant( 'BRIZY_VERSION' ),
			'editor_version' => (string) constant( 'BRIZY_EDITOR_VERSION' ),
			'edition'        => defined( 'BRIZY_PRO_VERSION' ) ? 'pro' : 'free',
			'post_types'     => self::post_types(),
			'can_build'      => (bool) call_user_func( array( 'Brizy_Editor_User', 'is_user_allowed' ) ),
			'supports'       => array( 'list pages', 'read page structure' ),
			'not_supported'  => 'Changing layouts: Brizy compiles the public page in its own editor, so layout changes made outside it would not appear on the site.',
		);
	}

	/**
	 * Posts built with Brizy.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function pages( array $input ): array {
		$args = array(
			'post_type'      => self::post_types(),
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'meta_key'       => 'brizy_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Brizy's own marker for built pages.
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
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
				'compiled'  => self::compiled( $post->ID ),
			);
		}
		return array(
			'pages' => $out,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Whether Brizy compiled the page with the installed version, or null when Brizy cannot say.
	 *
	 * @param int $id Post id.
	 */
	private static function compiled( int $id ): ?bool {
		try {
			return (bool) \Brizy_Editor_Post::get( $id )->isCompiledWithCurrentVersion();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Structure of one Brizy page.
	 *
	 * @param int  $id          Post id.
	 * @param bool $with_values Include every stored setting.
	 * @return array<string, mixed>
	 * @throws ToolError When the post is not built with Brizy or its data cannot be read.
	 */
	private static function get( int $id, bool $with_values ): array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || ! \Brizy_Editor_Entity::isBrizyEnabled( $post->ID ) ) {
			throw new ToolError( 'No Brizy page with this id. See kuduy-brizy-pages.' );
		}
		try {
			$model = \Brizy_Editor_Post::get( $post->ID );
			$data  = json_decode( (string) $model->get_editor_data( true ), true );
			$edit  = (string) $model->edit_url();
		} catch ( \Throwable $e ) {
			throw new ToolError( 'Brizy could not load this page.' );
		}
		$items = is_array( $data ) ? (array) ( $data['items'] ?? array() ) : array();
		$count = 0;
		$tree  = self::tree( $items, $with_values, $count );
		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'element_count' => $count,
			'items'         => $tree,
			'compiled'      => self::compiled( $post->ID ),
			'link'          => (string) get_permalink( $post ),
			'edit_url'      => $edit,
			'read_only'     => true,
		);
	}

	/**
	 * Brizy items as a tree: type, id, text, children.
	 *
	 * @param array<int, mixed> $items       Items.
	 * @param bool              $with_values Include every stored setting.
	 * @param int               $count       Running element count.
	 * @return list<array<string, mixed>>
	 */
	private static function tree( array $items, bool $with_values, int &$count ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			++$count;
			$value = is_array( $item['value'] ?? null ) ? $item['value'] : array();
			$row   = array(
				'type' => (string) ( $item['type'] ?? '' ),
				'id'   => (string) ( $value['_id'] ?? '' ),
			);
			foreach ( array( 'text', 'title', 'linkExternal', 'imageSrc' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== $value[ $key ] ) {
					$row[ $key ] = mb_substr( trim( wp_strip_all_tags( $value[ $key ] ) ), 0, self::MAX_TEXT );
				}
			}
			if ( $with_values ) {
				$row['values'] = array_diff_key( $value, array( 'items' => true ) );
			}
			$children = is_array( $value['items'] ?? null ) ? self::tree( $value['items'], $with_values, $count ) : array();
			if ( $children ) {
				$row['children'] = $children;
			}
			$out[] = $row;
		}//end foreach
		return $out;
	}
}
