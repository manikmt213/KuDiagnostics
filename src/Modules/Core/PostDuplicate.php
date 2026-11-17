<?php
/**
 * Tool: kuduy-post-duplicate.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Copies a post into a new draft (phase C): content, excerpt, terms, featured image, page template and custom fields
 * (meta), never edit locks, old slugs or trash markers. Elementor pages go through kuduy-elementor-duplicate
 * (Elementor rebuilds its CSS and ids there); WooCommerce products are refused (their lookup tables need
 * WooCommerce's own duplicate).
 */
final class PostDuplicate implements Tool {

	/**
	 * Meta keys that belong to the original only.
	 */
	private const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-duplicate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Duplicate a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Copies a post, page or custom post into a new draft with your account as author: title (with " (copy)" unless title is given), content, excerpt, categories/tags/terms, featured image, page template, parent and custom fields (including SEO plugin fields), but not edit locks or old slugs. The copy is never published. Elementor pages: use kuduy-elementor-duplicate. WooCommerce products are refused. Undo: kuduy-post-trash on the copy.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title' => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$schema                               = Content::summary_schema();
		$schema['properties']['copied_from']  = array( 'type' => 'integer' );
		$schema['properties']['meta_copied']  = array( 'type' => 'integer' );
		$schema['properties']['terms_copied'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		return isset( $input['id'] ) ? array( 'edit_post', (int) $input['id'] ) : 'edit_posts';
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
	 * @throws ToolError When the post cannot be copied here.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
		}
		if ( 'product' === $post->post_type ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'WooCommerce products are not copied here: their stock, prices and lookup tables need WooCommerce\'s own duplicate (Products > Duplicate).' );
		}
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'This page is built with Elementor: copy it with kuduy-elementor-duplicate, which lets Elementor rebuild its styles for the copy.' );
		}
		Content::assert_writable( $post->post_type );
		$type = Content::post_type( $post->post_type );
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account cannot create ' . $type->labels->name . '.' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		$title = isset( $input['title'] ) ? (string) $input['title'] : $post->post_title . ' (copy)';
		$id    = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $post->post_type,
					'post_status'    => 'draft',
					'post_author'    => get_current_user_id(),
					'post_title'     => $title,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_parent'    => $post->post_parent,
					'menu_order'     => $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the copy: ' . esc_html( $id->get_error_message() ) );
		}
		$terms = 0;
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $ids ) && $ids ) {
				wp_set_object_terms( $id, array_map( 'intval', $ids ), $taxonomy );
				$terms += count( $ids );
			}
		}
		$meta = 0;
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
				++$meta;
			}
		}
		$copy = get_post( $id );
		if ( ! $copy instanceof \WP_Post ) {
			throw new ToolError( 'The copy was created but cannot be read back.' );
		}
		return Content::summary( $copy ) + array(
			'copied_from'  => $post->ID,
			'meta_copied'  => $meta,
			'terms_copied' => $terms,
		);
	}
}
