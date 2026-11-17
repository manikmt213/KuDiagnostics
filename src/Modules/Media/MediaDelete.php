<?php
/**
 * Tool: kuduy-media-delete.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Media;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Permanently deletes one media item with its files (decision D46). High-risk: the first call only previews (what
 * the item is and where it is used) and returns a confirmation token (decision D45); nothing can be undone.
 */
final class MediaDelete implements Tool, Confirmable {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-media-delete';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Delete a media item permanently', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Permanently deletes one media library item: the attachment and its files (original and all sizes). This cannot be undone and there is no trash. Two steps: the first call (no confirm_token) changes nothing and returns a preview — title, file, size and every place the item is used — with a confirm_token; pages that use the item will show a broken image afterwards. Show the preview to the user; only if they agree, call again with the same id and the confirm_token. To swap an image everywhere, use kuduy-media-replace instead.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Media item id (see kuduy-media-list).',
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
			'properties' => array_merge(
				Confirmation::output_properties(),
				array(
					'deleted' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'file'    => array( 'type' => 'string' ),
				)
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
		return isset( $input['id'] ) ? array( 'delete_post', (int) $input['id'] ) : 'upload_files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The media item, or a "not found" error.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When there is no such media item.
	 */
	private static function item( array $input ): \WP_Post {
		$post = get_post( (int) $input['id'] );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No media item with this id.' );
		}
		return $post;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function preview( array $input ): array {
		$post  = self::item( $input );
		$path  = (string) get_attached_file( $post->ID );
		$size  = '' !== $path && is_file( $path ) ? (int) filesize( $path ) : 0;
		$usage = MediaLibrary::usage( $post );
		$uses  = $usage['uses'];
		return array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'file'      => (string) get_post_meta( $post->ID, '_wp_attached_file', true ),
			'mime_type' => (string) $post->post_mime_type,
			'bytes'     => $size,
			'uses'      => $uses,
			'warning'   => $uses
				? sprintf( 'Used in %d place(s): they will show a broken image or lose it. This cannot be undone.', count( $uses ) )
				: 'No use found (the search can miss images linked through other URLs). This cannot be undone.',
			'version'   => md5( $post->post_modified_gmt . '|' . $path . '|' . $size . '|' . count( $uses ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WordPress does not delete the item.
	 */
	public function execute( array $input ): array {
		$post = self::item( $input );
		$file = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		AuditLog::target( 'post', (string) $post->ID );
		if ( ! wp_delete_attachment( $post->ID, true ) ) {
			throw new ToolError( 'WordPress did not delete this media item.' );
		}
		return array(
			'deleted' => true,
			'id'      => $post->ID,
			'file'    => $file,
		);
	}
}
