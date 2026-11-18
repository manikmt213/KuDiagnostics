<?php
/**
 * Tool: kuduy-seo-audit.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Which posts lack their own SEO title, description or focus keyword, read through the active SEO plugin's adapter
 * (so the plugin's own storage and permission rules apply). Paged, newest first, only posts the user can edit.
 */
final class SeoAudit implements Tool {

	private const CHECKS = array( 'title', 'description', 'focus_keyword' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-seo-audit';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Find posts missing SEO data', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Audits published posts of one type in the active SEO plugin. check "missing" (default): posts with no SEO title, description or focus keyword of their own (the plugin\'s template then applies); only posts with something missing are listed, total counts all published posts of the type, for paging. check "noindex": posts on this page hidden from search engines by their own setting. check "duplicates": SEO titles and descriptions that several posts share (looks at up to 500 newest posts; not paged). Fix them with kuduy-seo-update, or many at once with kuduy-job-start. Read only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'      => 'string',
					'default'   => 'post',
					'maxLength' => 20,
				),
				'check'     => array(
					'type'    => 'string',
					'enum'    => array( 'missing', 'noindex', 'duplicates' ),
					'default' => 'missing',
				),
				'missing'   => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => self::CHECKS,
					),
					'minItems' => 1,
					'default'  => self::CHECKS,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin'      => array( 'type' => 'string' ),
				'checked'     => array(
					'type'        => 'integer',
					'description' => 'Posts on this page that were checked (those the user can edit).',
				),
				'posts'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'integer' ),
							'title'   => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'missing' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => 'Published posts of this type, for paging; not the number with missing data (that is the length of posts).',
				),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'duplicates'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'field' => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
							'ids'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			'required'   => array( 'plugin', 'checked', 'posts', 'total', 'total_pages', 'page' ),
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
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Seo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post type or SEO plugin is not usable.
	 */
	public function execute( array $input ): array {
		$type    = Content::post_type( (string) ( $input['post_type'] ?? 'post' ) );
		$adapter = Seo::adapter();
		$check   = (string) ( $input['check'] ?? 'missing' );
		if ( 'duplicates' === $check ) {
			return self::duplicates( $adapter, $type->name );
		}
		$checks  = array_values( array_intersect( self::CHECKS, (array) ( $input['missing'] ?? self::CHECKS ), $adapter->fields() ) );
		$page    = (int) ( $input['page'] ?? 1 );
		$query   = new \WP_Query(
			array(
				'post_type'      => $type->name,
				'post_status'    => 'publish',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$posts   = array();
		$checked = 0;
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			++$checked;
			$stored  = $adapter->stored( $post->ID );
			$missing = 'noindex' === $check
				? ( true === ( $stored['robots.noindex'] ?? null ) ? array( 'robots.noindex' ) : array() )
				: array_values( array_filter( $checks, static fn( string $field ): bool => null === ( $stored[ $field ] ?? null ) ) );
			if ( $missing ) {
				$posts[] = array(
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'url'     => (string) get_permalink( $post ),
					'missing' => $missing,
				);
			}
		}
		return array(
			'plugin'      => $adapter->slug(),
			'checked'     => $checked,
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * SEO titles and descriptions shared by several of the newest published posts of a type.
	 *
	 * @param SeoAdapter $adapter Active SEO plugin.
	 * @param string     $type    Post type.
	 * @return array<string, mixed>
	 */
	private static function duplicates( SeoAdapter $adapter, string $type ): array {
		$ids     = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the audit reads ids only, capped at 500 per run.
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		$seen    = array();
		$checked = 0;
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', (int) $id ) ) {
				continue;
			}
			++$checked;
			$stored = $adapter->stored( (int) $id );
			foreach ( array( 'title', 'description' ) as $field ) {
				$value = $stored[ $field ] ?? null;
				if ( is_string( $value ) && '' !== $value ) {
					$seen[ $field ][ mb_strtolower( $value ) ]['value'] = $value;
					$seen[ $field ][ mb_strtolower( $value ) ]['ids'][] = (int) $id;
				}
			}
		}
		$out = array();
		foreach ( $seen as $field => $values ) {
			foreach ( $values as $entry ) {
				if ( count( $entry['ids'] ) > 1 ) {
					$out[] = array(
						'field' => $field,
						'value' => $entry['value'],
						'ids'   => $entry['ids'],
					);
				}
			}
		}
		return array(
			'plugin'      => $adapter->slug(),
			'checked'     => $checked,
			'posts'       => array(),
			'total'       => count( $ids ),
			'total_pages' => 1,
			'page'        => 1,
			'duplicates'  => $out,
		);
	}
}
