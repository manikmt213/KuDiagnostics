<?php
/**
 * Tools: kuduy-synced-pattern-get, kuduy-synced-pattern-save, kuduy-synced-pattern-trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Patterns saved on the site (post type wp_block, phase Q7) through core's blocks controller (/wp/v2/blocks), so its
 * permission checks and kses filtering apply as in the editor. Synced patterns are shown wherever
 * <!-- wp:block {"ref":ID} /--> is used; an unsynced one (meta wp_pattern_sync_status = "unsynced") is copied into the
 * page when inserted. Changes and trashing are snapshotted; trash is WordPress' own trash.
 */
final class SyncedPatterns implements Tool, Snapshottable {

	private const ROUTE = '/wp/v2/blocks';

	/**
	 * Mode.
	 *
	 * @param string $mode get | save | trash.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-synced-pattern-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'get'   => __( 'Read a site pattern', 'kuduy-mcp' ),
			'save'  => __( 'Create or change a site pattern', 'kuduy-mcp' ),
			default => __( 'Trash a site pattern', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'get'   => 'Returns a pattern saved on the site (the "synced" list of kuduy-patterns): title, block markup (content), sync ("synced" = one shared copy shown everywhere it is used, "unsynced" = copied into the page when inserted), status, and used_in: the posts, templates and parts that show it (<!-- wp:block {"ref":ID} /-->).',
			'save'  => 'Creates a pattern saved on the site (no id: title and content required) or changes one (id): title, content (block markup), sync ("synced" or "unsynced"). Changing a synced pattern changes every page that shows it; the result lists them (used_in). Insert it with kuduy-block-edit (op insert, markup <!-- wp:block {"ref":ID} /-->). Changes are snapshotted; undo with kuduy-snapshot-restore. A new pattern is removed with kuduy-synced-pattern-trash.',
			default => 'Moves a pattern saved on the site to the trash. Pages that show a synced pattern then show nothing in its place: check used_in first (kuduy-synced-pattern-get). Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'save' === $this->mode ) {
			$properties += array(
				'title'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'content' => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
				'sync'    => array(
					'type' => 'string',
					'enum' => array( 'synced', 'unsynced' ),
				),
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'save' === $this->mode ? array() : array( 'id' ),
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
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'content'      => array( 'type' => 'string' ),
				'sync'         => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'markup'       => array( 'type' => 'string' ),
				'used_in'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'title', 'sync', 'status' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'get' === $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'trash' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'get' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return string|array<int, mixed>
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id <= 0 ) {
			return 'publish_posts';
		}
		return match ( $this->mode ) {
			'get'   => array( 'read_post', $id ),
			'trash' => array( 'delete_post', $id ),
			default => array( 'edit_post', $id ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The pattern post, or a ToolError.
	 *
	 * @param int $id Post id.
	 * @throws ToolError When there is no such pattern.
	 */
	private static function pattern( int $id ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type || 'trash' === $post->post_status ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No pattern saved on the site with id ' . $id . ' (see the synced list of kuduy-patterns).' );
		}
		return $post;
	}

	/**
	 * Sync status of a pattern.
	 *
	 * @param int $id Post id.
	 */
	private static function sync( int $id ): string {
		return 'unsynced' === get_post_meta( $id, 'wp_pattern_sync_status', true ) ? 'unsynced' : 'synced';
	}

	/**
	 * Posts, templates and parts that show the pattern, as far as the user may edit them.
	 *
	 * @param int $id Pattern id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function used_in( int $id ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"ref":' . $id ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, prepared, capped; no core API searches content by block.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status NOT IN ('auto-draft', 'trash', 'inherit') AND post_content LIKE %s ORDER BY ID DESC LIMIT 200", $like ) );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! preg_match( '/<!-- wp:block \{[^}]*"ref":' . $id . '[,}]/', (string) $row->post_content ) || ! current_user_can( 'edit_post', (int) $row->ID ) ) {
				continue;
			}
			$post  = get_post( (int) $row->ID );
			$out[] = array(
				'id'    => (int) $row->ID,
				'title' => $post instanceof \WP_Post ? get_the_title( $post ) : '',
				'type'  => $post instanceof \WP_Post ? $post->post_type : '',
			);
		}
		return $out;
	}

	/**
	 * One pattern in the tool's shape.
	 *
	 * @param \WP_Post $post Pattern.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Post $post ): array {
		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'sync'    => self::sync( $post->ID ),
			'status'  => $post->post_status,
			'markup'  => '<!-- wp:block {"ref":' . $post->ID . '} /-->',
			'used_in' => self::used_in( $post->ID ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the pattern is missing.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'get' === $this->mode || $id <= 0 ) {
			return null;
		}
		$post = self::pattern( $id );
		return array(
			'object_type' => 'wp_block',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'      => $id,
				'mode'    => $this->mode,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => $post->post_status,
				'sync'    => self::sync( $id ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the pattern is gone.
	 */
	public function restore( array $payload ): void {
		$id   = (int) ( $payload['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'The pattern ' . $id . ' no longer exists.' );
		}
		if ( 'trash' === $post->post_status ) {
			wp_untrash_post( $id );
		}
		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_title'   => (string) ( $payload['title'] ?? '' ),
					'post_content' => (string) ( $payload['content'] ?? '' ),
					'post_status'  => (string) ( $payload['status'] ?? 'publish' ),
				)
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress refused: ' . $saved->get_error_message() );
		}
		if ( 'unsynced' === ( $payload['sync'] ?? '' ) ) {
			update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		} else {
			delete_post_meta( $id, 'wp_pattern_sync_status' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the pattern is missing or core refuses.
	 */
	public function execute( array $input ): array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'save' !== $this->mode || $id > 0 ) {
			AuditLog::target( 'wp_block', (string) $id );
			self::pattern( $id );
		}
		if ( 'get' === $this->mode ) {
			return self::shape( self::pattern( $id ) );
		}
		if ( 'trash' === $this->mode ) {
			$post = self::pattern( $id );
			$used = self::used_in( $id );
			CoreRest::call( 'DELETE', self::ROUTE . '/' . $id );
			clean_post_cache( $id );
			$trashed        = get_post( $id );
			$out            = self::shape( $trashed instanceof \WP_Post ? $trashed : $post );
			$out['used_in'] = $used;
			return $out;
		}
		$body = array_intersect_key( $input, array_flip( array( 'title', 'content' ) ) );
		if ( 'unsynced' === ( $input['sync'] ?? '' ) ) {
			$body['meta'] = array( 'wp_pattern_sync_status' => 'unsynced' );
		} elseif ( isset( $input['sync'] ) && $id > 0 && 'unsynced' === self::sync( $id ) ) {
			// Core keeps "synced" as no meta at all; null deletes it through the controller, but core refuses null
			// when nothing valid is stored (found live, 2026-11-18), so it is sent only to remove "unsynced".
			$body['meta'] = array( 'wp_pattern_sync_status' => null );
		}
		if ( $id <= 0 ) {
			if ( ! isset( $input['title'], $input['content'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new pattern needs title and content. Nothing was created.' );
			}
			$body['status'] = 'publish';
			$data           = (array) CoreRest::call( 'POST', self::ROUTE, $body );
			$id             = (int) ( $data['id'] ?? 0 );
			AuditLog::target( 'wp_block', (string) $id );
		} else {
			if ( ! $body && ! isset( $input['sync'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give title, content or sync. Nothing was changed.' );
			}
			if ( $body ) {
				CoreRest::call( 'POST', self::ROUTE . '/' . $id, $body );
			}
		}
		clean_post_cache( $id );
		return self::shape( self::pattern( $id ) );
	}
}
