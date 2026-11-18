<?php
/**
 * Tool: kuduy-kirki-edit.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Kirki;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Edits the blocks of a Kirki 6 page (phase Q2) through Kirki's own page manager (Kirki\App\Supports\Facades\Page,
 * source of Kirki 6.4.2): the same calls its builder makes when it saves. By default the change is saved as an
 * unpublished version (get_stage_version_for_save() + save_blocks() into that version), so visitors keep seeing the
 * published page until it is published in Kirki; with publish the blocks are written to the published data and the
 * version is published (publish_stage_version()), as the builder's Publish button does. Only block content changes:
 * text, HTML attributes, removing, moving and duplicating blocks. Style blocks, classes and fonts are never written,
 * so duplicated blocks keep the classes of the original. Snapshot first; undo removes the version the change made or
 * writes the previous blocks back.
 */
final class KirkiEdit implements Tool, Snapshottable {

	private const HELPER = '\Kirki\HelperFunctions';

	private const PAGES = '\Kirki\App\Supports\Facades\Page';

	private const META = 'kirki';

	private const OPS = array( 'text', 'attribute', 'remove', 'move', 'duplicate' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-kirki-edit';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Edit a Kirki page', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Changes blocks of a page built with Kirki, through Kirki\'s own page manager: 1–50 operations applied in order and saved once. Block ids come from kuduy-kirki-get (version "latest"). op text: block + text (plain text; replaces the text of a block that has no inline elements such as links or spans inside). op attribute: block + name + value (an HTML attribute such as href, alt, title, target or data-*; value null removes it; event handlers and javascript: URLs are refused). op remove: block (with its children). op move: block + to_parent + to_index. op duplicate: block (copy with new ids placed right after it; it keeps the original\'s classes). Styles, classes and fonts are not changed: style the page in Kirki. By default the change is saved as an unpublished version, as Save in the builder does; visitors still see the published page until it is published in Kirki. publish: true publishes it at once (refused while the page has other unpublished changes). Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 100,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'operations' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'op'        => array(
								'type' => 'string',
								'enum' => self::OPS,
							),
							'block'     => $id,
							'text'      => array(
								'type'      => 'string',
								'maxLength' => 20000,
							),
							'name'      => array(
								'type'      => 'string',
								'maxLength' => 100,
								'pattern'   => '^[a-zA-Z_:][a-zA-Z0-9_:.-]*$',
							),
							'value'     => array(
								'type'      => array( 'string', 'null' ),
								'maxLength' => 2000,
							),
							'to_parent' => $id,
							'to_index'  => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'required'             => array( 'op', 'block' ),
						'additionalProperties' => false,
					),
				),
				'publish'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'id', 'operations' ),
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
				'applied'      => array( 'type' => 'integer' ),
				'published'    => array( 'type' => 'boolean' ),
				'version'      => array( 'type' => 'integer' ),
				'block_count'  => array( 'type' => 'integer' ),
				'new_blocks'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notes'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'applied', 'published', 'block_count', 'notes' ),
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
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<int, mixed>
	 */
	public function capability( array $input ): string|array {
		return isset( $input['id'] ) ? array( 'edit_post', (int) $input['id'] ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$ok = KirkiRead::available();
		if ( true !== $ok ) {
			return $ok;
		}
		return class_exists( ltrim( self::PAGES, '\\' ) ) && is_callable( array( self::PAGES, 'get_stage_version_for_save' ) )
			? true
			: 'This Kirki version has no page manager with versions (Kirki 6.4 or later is needed to edit pages).';
	}

	/**
	 * Call Kirki's page facade.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 */
	private static function page( string $method, mixed ...$args ): mixed {
		return call_user_func_array( array( self::PAGES, $method ), $args );
	}

	/**
	 * Version numbers of the page with whether each is published.
	 *
	 * @param int $id Post id.
	 * @return array<int, bool>
	 */
	private static function versions( int $id ): array {
		$list = self::page( 'get_all_staged_versions', $id, false );
		$list = is_object( $list ) && method_exists( $list, 'to_array' ) ? $list->to_array() : (array) $list;
		$out  = array();
		foreach ( $list as $row ) {
			if ( is_array( $row ) && isset( $row['version'] ) ) {
				$out[ (int) $row['version'] ] = ! empty( $row['publish'] ) && 'false' !== $row['publish'];
			}
		}
		return $out;
	}

	/**
	 * Published version number, and the newest unpublished one when it is newer (0 otherwise).
	 *
	 * @param int $id Post id.
	 * @return array{0: int, 1: int}
	 */
	private static function state( int $id ): array {
		$published = self::page( 'get_published_stage_version', $id );
		$unpub     = self::page( 'get_most_recent_unpublished_stage_version', $id );
		$published = is_numeric( $published ) ? (int) $published : 0;
		$unpub     = is_numeric( $unpub ) ? (int) $unpub : 0;
		return array( $published, $unpub > $published ? $unpub : 0 );
	}

	/**
	 * The Kirki page, writable by the user.
	 *
	 * @param int $id Post id.
	 * @throws ToolError When it is not a Kirki page or the user may not build.
	 */
	private static function post( int $id ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! is_array( call_user_func( array( self::HELPER, 'is_kirki_type_data' ), $id ) ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No Kirki page with id ' . $id . '. See kuduy-kirki-pages.' );
		}
		if ( ! call_user_func( array( self::HELPER, 'user_has_builder_access' ) ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not use the Kirki builder (Kirki\'s own access setting).' );
		}
		return $post;
	}

	/**
	 * Blocks of a version (0 = published).
	 *
	 * @param int $id      Post id.
	 * @param int $version Version.
	 * @return array<string, mixed>
	 */
	private static function blocks( int $id, int $version ): array {
		// Without a version Kirki reads its newest version, so the published data is read from the post itself.
		$data = $version > 0 ? self::page( 'read_page_meta', $id, self::META, $version, array() ) : get_post_meta( $id, self::META, true );
		return is_array( $data ) && is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the page is not a Kirki page.
	 */
	public function snapshot( array $input ): array {
		$id = (int) $input['id'];
		self::post( $id );
		[ $published, $draft ] = self::state( $id );
		$meta                  = get_post_meta( $id, self::META, true );
		return array(
			'object_type' => 'kirki_page',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'           => $id,
				'versions'     => array_keys( self::versions( $id ) ),
				'published'    => $published,
				'draft'        => $draft,
				'meta'         => is_array( $meta ) ? $meta : null,
				'draft_blocks' => $draft > 0 ? self::blocks( $id, $draft ) : null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the page is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		self::post( $id );
		$before    = array_map( 'intval', (array) ( $payload['versions'] ?? array() ) );
		$published = (int) ( $payload['published'] ?? 0 );
		[ $now ]   = self::state( $id );
		$meta      = $payload['meta'] ?? null;
		if ( is_array( $meta ) && get_post_meta( $id, self::META, true ) !== $meta ) {
			// The change was published: the earlier published blocks become the published ones again. Kirki publishes
			// into the published version itself when it has no saved copy yet (seen on Kirki 6.4.2), so that copy is
			// put back too; otherwise the earlier version is marked published again.
			update_post_meta( $id, self::META, $meta );
			$stages = self::page( 'stages' );
			if ( $published > 0 && is_object( $stages ) ) {
				if ( $now === $published && method_exists( $stages, 'set_value' ) ) {
					$stages->set_value( $id, $published, self::META, $meta );
				} elseif ( method_exists( $stages, 'set_published' ) ) {
					$stages->set_published( $id, $published );
				}
			}
		}
		foreach ( array_keys( self::versions( $id ) ) as $version ) {
			if ( ! in_array( $version, $before, true ) ) {
				self::page( 'remove_stage_version', $id, $version );
			}
		}
		$draft = (int) ( $payload['draft'] ?? 0 );
		if ( $draft > 0 && is_array( $payload['draft_blocks'] ?? null ) ) {
			self::page( 'save_blocks', $id, array( 'blocks' => $payload['draft_blocks'] ), $draft );
		}
		clean_post_cache( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an operation is refused; nothing is saved then.
	 */
	public function execute( array $input ): array {
		$id = (int) $input['id'];
		self::post( $id );
		AuditLog::target( 'kirki_page', (string) $id );
		$publish               = ! empty( $input['publish'] );
		[ $published, $draft ] = self::state( $id );
		if ( $publish && $draft > 0 ) {
			throw ToolError::of( ToolError::CONFLICT, 'This page has unpublished changes (version ' . $draft . '). Publish or discard them in Kirki first, or save this change with publish false; publishing now would mix them. Nothing was changed.' );
		}
		$blocks = self::blocks( $id, $draft );
		if ( ! isset( $blocks['root'] ) && ! $blocks ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'This Kirki page has no blocks yet; build it in Kirki first. Nothing was changed.' );
		}
		$notes = array();
		$new   = array();
		foreach ( array_values( (array) $input['operations'] ) as $index => $op ) {
			$blocks = self::apply( $blocks, (array) $op, $index + 1, $new, $notes );
		}
		$data = array( 'blocks' => $blocks );
		if ( $publish ) {
			self::page( 'save_blocks', $id, $data, false );
			self::page( 'save_editor_mode', $id );
			self::page( 'publish_stage_version', $id );
			[ $version ] = self::state( $id );
		} else {
			$version = self::page( 'get_stage_version_for_save', $id, self::META, $data );
			if ( false === $version ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The operations leave the page as it is published; nothing was saved.' );
			}
			$version = (int) $version;
			self::page( 'save_blocks', $id, $data, $version );
			self::page( 'set_last_edited_datetime_of_stage_version', $id );
			self::page( 'save_editor_mode', $id );
			$notes[] = 'Saved as unpublished version ' . $version . ': visitors see the published page until it is published in Kirki (or run again with publish true).';
		}
		// As Kirki's own save: touch the post so its modified date and caches follow.
		wp_update_post( array( 'ID' => $id ) );
		return array(
			'id'          => $id,
			'applied'     => count( (array) $input['operations'] ),
			'published'   => $publish,
			'version'     => (int) $version,
			'block_count' => count( $blocks ),
			'new_blocks'  => $new,
			'notes'       => $notes,
		);
	}

	/**
	 * One operation on the block map.
	 *
	 * @param array<string, mixed> $blocks Blocks by id.
	 * @param array<string, mixed> $op     Operation.
	 * @param int                  $n      Operation number.
	 * @param list<string>         $added  Ids of duplicated blocks.
	 * @param list<string>         $notes  Notes.
	 * @return array<string, mixed>
	 * @throws ToolError When the operation is refused.
	 */
	private static function apply( array $blocks, array $op, int $n, array &$added, array &$notes ): array {
		$key = (string) $op['block'];
		if ( ! isset( $blocks[ $key ] ) || ! is_array( $blocks[ $key ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Operation ' . $n . ': no block "' . $key . '" on this page (see kuduy-kirki-get with version "latest"). Nothing was saved.' );
		}
		$block = $blocks[ $key ];
		switch ( (string) $op['op'] ) {
			case 'text':
				if ( ! isset( $op['text'] ) ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ': text needs text. Nothing was saved.' );
				}
				$props = is_array( $block['properties'] ?? null ) ? $block['properties'] : array();
				foreach ( (array) ( $props['contents'] ?? array() ) as $content ) {
					if ( is_array( $content ) ) {
						throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Operation ' . $n . ': block "' . $key . '" has inline elements (links, spans, …) in its text; change those blocks instead. Nothing was saved.' );
					}
				}
				if ( ! empty( $props['dynamicContent'] ) ) {
					$notes[] = 'Block ' . $key . ' shows dynamic content, which Kirki shows instead of this text.';
				}
				$props['contents']            = array( wp_strip_all_tags( (string) $op['text'] ) );
				$blocks[ $key ]['properties'] = $props;
				return $blocks;
			case 'attribute':
				$name  = strtolower( (string) ( $op['name'] ?? '' ) );
				$value = $op['value'] ?? null;
				if ( '' === $name || ! array_key_exists( 'value', $op ) ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ': attribute needs name and value (null removes it). Nothing was saved.' );
				}
				if ( str_starts_with( $name, 'on' ) || 'style' === $name || 'class' === $name || 'id' === $name ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ': attribute ' . $name . ' cannot be set here (event handlers are refused; style, class and id belong to Kirki\'s style panel). Nothing was saved.' );
				}
				if ( is_string( $value ) && in_array( $name, array( 'href', 'src', 'action', 'formaction', 'srcset', 'poster' ), true ) && (string) wp_kses_bad_protocol( $value, wp_allowed_protocols() ) !== $value ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ': ' . $name . ' uses a URL scheme WordPress does not allow. Nothing was saved.' );
				}
				$props = is_array( $block['properties'] ?? null ) ? $block['properties'] : array();
				$attrs = is_array( $props['attributes'] ?? null ) ? $props['attributes'] : array();
				if ( null === $value ) {
					unset( $attrs[ $name ] );
				} else {
					$attrs[ $name ] = sanitize_text_field( (string) $value );
				}
				$props['attributes']          = $attrs;
				$blocks[ $key ]['properties'] = $props;
				return $blocks;
			case 'remove':
				self::not_root( $key, $n );
				$blocks = self::detach( $blocks, $key );
				foreach ( self::subtree( $blocks, $key ) as $gone ) {
					unset( $blocks[ $gone ] );
				}
				return $blocks;
			case 'move':
				self::not_root( $key, $n );
				$parent = (string) ( $op['to_parent'] ?? '' );
				if ( ! isset( $blocks[ $parent ] ) || ! is_array( $blocks[ $parent ] ) ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Operation ' . $n . ': move needs to_parent, an existing block id. Nothing was saved.' );
				}
				if ( in_array( $parent, self::subtree( $blocks, $key ), true ) ) {
					throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ': a block cannot be moved into itself. Nothing was saved.' );
				}
				$blocks = self::detach( $blocks, $key );
				return self::attach( $blocks, $key, $parent, isset( $op['to_index'] ) ? (int) $op['to_index'] : null );
			default:
				self::not_root( $key, $n );
				$parent = (string) ( $block['parentId'] ?? self::parent_of( $blocks, $key ) );
				$map    = array();
				foreach ( self::subtree( $blocks, $key ) as $old ) {
					$map[ $old ] = 'kuduy-' . strtolower( wp_generate_password( 10, false ) );
				}
				foreach ( $map as $old => $copy ) {
					$item       = $blocks[ $old ];
					$item['id'] = $copy;
					if ( $old !== $key && isset( $item['parentId'], $map[ (string) $item['parentId'] ] ) ) {
						$item['parentId'] = $map[ (string) $item['parentId'] ];
					}
					$item['children'] = array_map( static fn( $child ): string => $map[ (string) $child ] ?? (string) $child, (array) ( $item['children'] ?? array() ) );
					if ( isset( $item['properties']['contents'] ) && is_array( $item['properties']['contents'] ) ) {
						foreach ( $item['properties']['contents'] as $i => $content ) {
							if ( is_array( $content ) && isset( $content['id'], $map[ (string) $content['id'] ] ) ) {
								$item['properties']['contents'][ $i ]['id'] = $map[ (string) $content['id'] ];
							}
						}
					}
					$blocks[ $copy ] = $item;
				}
				$siblings = (array) ( $blocks[ $parent ]['children'] ?? array() );
				$at       = array_search( $key, array_map( 'strval', $siblings ), true );
				if ( '' === $parent || false === $at ) {
					throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Operation ' . $n . ': block "' . $key . '" is an inline element of its parent\'s text; it cannot be duplicated here. Nothing was saved.' );
				}
				$blocks  = self::attach( $blocks, $map[ $key ], $parent, (int) $at + 1 );
				$added[] = $map[ $key ];
				return $blocks;
		}//end switch
	}

	/**
	 * Refuse the root block.
	 *
	 * @param string $key Block id.
	 * @param int    $n   Operation number.
	 * @throws ToolError For the root.
	 */
	private static function not_root( string $key, int $n ): void {
		if ( 'root' === $key || 'body' === $key ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ': the page\'s root block cannot be removed, moved or duplicated. Nothing was saved.' );
		}
	}

	/**
	 * Id of the block that lists a block among its children ('' when none).
	 *
	 * @param array<string, mixed> $blocks Blocks.
	 * @param string               $key    Block id.
	 */
	private static function parent_of( array $blocks, string $key ): string {
		foreach ( $blocks as $id => $block ) {
			if ( is_array( $block ) && in_array( $key, array_map( 'strval', (array) ( $block['children'] ?? array() ) ), true ) ) {
				return (string) $id;
			}
		}
		return '';
	}

	/**
	 * A block and every block below it (children and inline elements).
	 *
	 * @param array<string, mixed> $blocks Blocks.
	 * @param string               $key    Block id.
	 * @return list<string>
	 */
	private static function subtree( array $blocks, string $key ): array {
		$out   = array();
		$queue = array( $key );
		while ( $queue ) {
			$id = (string) array_shift( $queue );
			if ( in_array( $id, $out, true ) || ! isset( $blocks[ $id ] ) || ! is_array( $blocks[ $id ] ) ) {
				continue;
			}
			$out[] = $id;
			foreach ( (array) ( $blocks[ $id ]['children'] ?? array() ) as $child ) {
				$queue[] = (string) $child;
			}
			foreach ( (array) ( $blocks[ $id ]['properties']['contents'] ?? array() ) as $content ) {
				if ( is_array( $content ) && isset( $content['id'] ) ) {
					$queue[] = (string) $content['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * Take a block out of its parent's children.
	 *
	 * @param array<string, mixed> $blocks Blocks.
	 * @param string               $key    Block id.
	 * @return array<string, mixed>
	 */
	private static function detach( array $blocks, string $key ): array {
		foreach ( $blocks as $id => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$children = array_map( 'strval', (array) ( $block['children'] ?? array() ) );
			if ( in_array( $key, $children, true ) ) {
				$blocks[ $id ]['children'] = array_values( array_diff( $children, array( $key ) ) );
			}
			if ( isset( $block['properties']['contents'] ) && is_array( $block['properties']['contents'] ) ) {
				$blocks[ $id ]['properties']['contents'] = array_values(
					array_filter(
						$block['properties']['contents'],
						static fn( $content ): bool => ! ( is_array( $content ) && (string) ( $content['id'] ?? '' ) === $key )
					)
				);
			}
		}
		return $blocks;
	}

	/**
	 * Place a block among a parent's children.
	 *
	 * @param array<string, mixed> $blocks Blocks.
	 * @param string               $key    Block id.
	 * @param string               $parent_id Parent id.
	 * @param int|null             $index  Position (null = end).
	 * @return array<string, mixed>
	 */
	private static function attach( array $blocks, string $key, string $parent_id, ?int $index ): array {
		$children = array_values( array_map( 'strval', (array) ( $blocks[ $parent_id ]['children'] ?? array() ) ) );
		$index    = null === $index ? count( $children ) : min( $index, count( $children ) );
		array_splice( $children, $index, 0, array( $key ) );
		$blocks[ $parent_id ]['children'] = $children;
		$blocks[ $key ]['parentId']       = $parent_id;
		return $blocks;
	}
}
