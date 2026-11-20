<?php
/**
 * Tool: kuduy-post-delete.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Permanent delete of a post, page or other content item (phase O, D46). Only an item already in the trash
 * (kuduy-post-trash first), in two steps with a confirmation token (D45). The post, its meta and its terms are saved
 * first; undo puts it back into the trash with the same id. Revisions and attachments WordPress removes with it are
 * not part of the undo, and the preview says so.
 */
final class PostDelete implements Tool, Confirmable, Snapshottable {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-post-delete';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Delete a post permanently', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Permanently deletes a post, page or other content item that is already in the trash (trash it with kuduy-post-trash first). Two steps: the first call changes nothing and returns a preview (title, type, revisions WordPress deletes with it) with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token. The item, its fields and its terms are saved first: undo with kuduy-snapshot-restore puts it back into the trash with the same id (its revisions do not come back). Needs the admin access level.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				Confirmation::ARG => Confirmation::token_input(),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'id'           => array( 'type' => 'integer' ),
				'deleted'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && get_post( $id ) ? array( 'delete_post', $id ) : 'delete_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * A trashed content item.
	 *
	 * @param int $id Post id.
	 * @throws ToolError When it does not exist, is not content, or is not in the trash.
	 */
	private static function trashed( int $id ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No content item with id ' . $id . ' (media: kuduy-media-delete; products: kuduy-woo-product-delete).' );
		}
		if ( 'trash' !== $post->post_status ) {
			throw ToolError::of( ToolError::CONFLICT, '"' . $post->post_title . '" is not in the trash. Trash it first with kuduy-post-trash (that can be undone), then delete it here. Nothing was deleted.' );
		}
		return $post;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the item cannot be deleted.
	 */
	public function preview( array $input ): array {
		$post = self::trashed( (int) $input['id'] );
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'revisions' => count( wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) ) ),
			'undo'      => 'The item, its fields and terms can be put back into the trash with kuduy-snapshot-restore; its revisions cannot.',
			'version'   => md5( $post->post_modified_gmt . '|' . $post->post_status ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$post = get_post( (int) ( $input['id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post || 'trash' !== $post->post_status ) {
			return null;
		}
		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
		}
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids                = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$terms[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'post'  => $post->to_array(),
				'meta'  => $meta,
				'terms' => $terms,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the id is taken or WordPress refuses.
	 */
	public function restore( array $payload ): void {
		$post = (array) $payload['post'];
		$id   = (int) $post['ID'];
		if ( get_post( $id ) ) {
			throw new ToolError( 'Post ' . $id . ' exists again; nothing to restore.' );
		}
		$data                = array_intersect_key( $post, array_flip( array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'post_type', 'post_mime_type', 'comment_status', 'ping_status', 'post_password', 'to_ping', 'pinged', 'post_content_filtered' ) ) );
		$data['import_id']   = $id;
		$data['post_status'] = 'trash';
		$new                 = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $new ) || $new !== $id ) {
			throw new ToolError( 'WordPress did not put post ' . $id . ' back' . ( is_wp_error( $new ) ? ': ' . wp_strip_all_tags( $new->get_error_message() ) : ' with the same id.' ) );
		}
		foreach ( (array) ( $payload['meta'] ?? array() ) as $key => $values ) {
			delete_post_meta( $id, (string) $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, (string) $key, wp_slash( $value ) );
			}
		}
		foreach ( (array) ( $payload['terms'] ?? array() ) as $taxonomy => $ids ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WordPress refuses.
	 */
	public function execute( array $input ): array {
		$post = self::trashed( (int) $input['id'] );
		AuditLog::target( 'post', (string) $post->ID );
		if ( ! wp_delete_post( $post->ID, true ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not delete the item.' );
		}
		return array(
			'id'      => $post->ID,
			'deleted' => true,
		);
	}
}
