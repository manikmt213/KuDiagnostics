<?php
/**
 * Tool: kuduy-block-edit.
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
use Kuduy\Mcp\Modules\Core\PostWriter;

/**
 * Block-level editing of one post (phase C): 1–50 operations on the parsed block tree, every new or changed block
 * checked against its registered type (attribute types, nesting rules), then saved once with wp_update_post().
 * Nothing is saved when one operation fails. Snapshot first; undo restores the previous content.
 */
final class BlockEdit implements Tool, Snapshottable {

	/**
	 * Operations.
	 */
	private const OPS = array( 'insert', 'replace', 'update', 'move', 'duplicate', 'remove' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-block-edit';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Edit blocks of a post', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Changes individual blocks of a post or page (block editor content) without rewriting the rest: 1–50 operations applied in order and saved once. Paths come from kuduy-post-blocks ("0", "2.1" = second inner block of the third block) and refer to the tree as it is when that operation runs. op insert: markup (block markup) or pattern (a registered pattern name from kuduy-patterns) under parent ("" = top level) at index (default: end). op replace: path + markup (exactly one block). op update: path + set (attributes to set) and/or unset (names to remove), merge: true to merge object values into the stored ones; only attributes the block type registers (see kuduy-block-schema); attributes stored in the block\'s HTML (with a "source") cannot be set this way — replace the block instead. op move: path to to_parent + to_index (counted after the block was taken out). op duplicate: path (copy placed right after). op remove: path. expect (block name) on any operation refuses it when the block at path is a different one. Every new or changed block is checked against its registered type (known block, attribute types, where it may be placed); if anything fails nothing is saved. Static blocks: KuDiagnostics cannot regenerate a block\'s HTML, so send complete markup when the look changes. Works on pages built with Divi 5 (divi/... blocks with nested settings: use merge) and other block-based builders; Divi clears its CSS cache on save. Refuses pages built with Elementor, Avada, Beaver Builder, WPBakery or Divi 4. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$path = array(
			'type'      => 'string',
			'maxLength' => 60,
			'pattern'   => '^(\d+(\.\d+)*)?$',
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
							'path'      => $path,
							'parent'    => $path,
							'index'     => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'markup'    => array(
								'type'      => 'string',
								'maxLength' => 500000,
							),
							'pattern'   => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'set'       => array( 'type' => 'object' ),
							'merge'     => array(
								'type'        => 'boolean',
								'description' => 'update only: merge object values of set into the stored ones instead of replacing them (for nested settings such as Divi 5 {"content":{"innerContent":{"desktop":{"value":"..."}}}}).',
							),
							'unset'     => array(
								'type'  => 'array',
								'items' => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
							),
							'to_parent' => $path,
							'to_index'  => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'expect'    => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Block name expected at path, e.g. "core/heading" or "heading".',
							),
						),
						'required'             => array( 'op' ),
						'additionalProperties' => false,
					),
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
				'block_count'  => array( 'type' => 'integer' ),
				'notes'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'applied', 'block_count', 'notes' ),
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
	 * The post, writable and not built with a page builder.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is missing, not writable or built with a page builder.
	 */
	private static function post( array $input ): \WP_Post {
		$post = Content::post( (int) $input['id'] );
		if ( null === $post ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Post ' . (int) $input['id'] . ' was not found (or is not a content post type).' );
		}
		Content::assert_writable( $post->post_type );
		PostWriter::guard_builder( $post, array( 'content' => true ) );
		return $post;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$post = Content::post( (int) $input['id'] );
		return null === $post ? null : array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => PostWriter::state( $post ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		PostWriter::restore_state( $payload );
	}

	/**
	 * Full block name ("heading" → "core/heading").
	 *
	 * @param string $name Name.
	 */
	private static function full( string $name ): string {
		return str_contains( $name, '/' ) ? $name : 'core/' . $name;
	}

	/**
	 * Name of the block at a parent path and of all its ancestors.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $under Parent path.
	 * @return array{0: string|null, 1: list<string>}
	 */
	private static function context( array $blocks, string $under ): array {
		$chain = array();
		$steps = BlockTree::steps( $under );
		$depth = count( $steps );
		for ( $i = 1; $i <= $depth; $i++ ) {
			$chain[] = (string) ( BlockTree::get( $blocks, implode( '.', array_slice( $steps, 0, $i ) ) )['blockName'] ?? '' );
		}
		return array( $chain ? (string) end( $chain ) : null, $chain );
	}

	/**
	 * Parent path of a path.
	 *
	 * @param string $path Path.
	 */
	private static function parent_of( string $path ): string {
		$pos = strrpos( $path, '.' );
		return false === $pos ? '' : substr( $path, 0, $pos );
	}

	/**
	 * Refuse a block that does not fit its new place.
	 *
	 * @param array<string, mixed>             $block  Parsed block.
	 * @param array<int, array<string, mixed>> $blocks Tree it goes into.
	 * @param string                           $under Parent path.
	 * @param int                              $n      Operation number (1-based).
	 * @throws ToolError When the block is unknown, has invalid attributes or breaks a nesting rule.
	 */
	private static function check( array $block, array $blocks, string $under, int $n ): void {
		[ $name, $chain ] = self::context( $blocks, $under );
		$problems         = BlockTree::problems( $block, $name, $chain, 'new' );
		if ( null !== $name ) {
			$type    = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			$allowed = null === $type ? null : $type->allowed_blocks;
			if ( is_array( $allowed ) && $allowed && ! in_array( (string) ( $block['blockName'] ?? '' ), $allowed, true ) ) {
				$problems[] = 'new: ' . $name . ' only accepts ' . implode( ', ', $allowed ) . ' inside it.';
			}
		}
		if ( $problems ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ' was refused, nothing was saved: ' . implode( ' ', $problems ) );
		}
	}

	/**
	 * Blocks to insert: from markup or a registered pattern.
	 *
	 * @param array<string, mixed> $op Operation.
	 * @param int                  $n  Operation number.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When neither is given or the pattern is unknown.
	 */
	private static function new_blocks( array $op, int $n ): array {
		if ( isset( $op['pattern'] ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $op['pattern'] );
			if ( ! is_array( $pattern ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Operation ' . $n . ': no registered pattern named ' . $op['pattern'] . ' (see kuduy-patterns).' );
			}
			return BlockTree::parse_markup( (string) $pattern['content'] );
		}
		if ( ! isset( $op['markup'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ' (' . $op['op'] . ') needs markup' . ( 'insert' === $op['op'] ? ' or pattern' : '' ) . '.' );
		}
		return BlockTree::parse_markup( (string) $op['markup'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an operation fails or WordPress refuses the save.
	 */
	public function execute( array $input ): array {
		$post     = self::post( $input );
		$original = (string) $post->post_content;
		$blocks   = parse_blocks( $original );
		$notes    = array();
		AuditLog::target( 'post', (string) $post->ID );
		foreach ( array_values( (array) $input['operations'] ) as $k => $op ) {
			$n    = $k + 1;
			$op   = (array) $op;
			$path = (string) ( $op['path'] ?? '' );
			if ( 'insert' !== $op['op'] && '' === $path ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ' (' . $op['op'] . ') needs path.' );
			}
			if ( isset( $op['expect'] ) && 'insert' !== $op['op'] ) {
				$found = (string) ( BlockTree::get( $blocks, $path )['blockName'] ?? 'core/freeform' );
				if ( self::full( (string) $op['expect'] ) !== $found ) {
					throw ToolError::of( ToolError::CONFLICT, 'Operation ' . $n . ': the block at ' . $path . ' is ' . $found . ', not ' . self::full( (string) $op['expect'] ) . '. Nothing was saved; read the paths again with kuduy-post-blocks.' );
				}
			}
			switch ( $op['op'] ) {
				case 'insert':
					$under = (string) ( $op['parent'] ?? '' );
					$added = self::new_blocks( $op, $n );
					foreach ( $added as $block ) {
						self::check( $block, $blocks, $under, $n );
					}
					BlockTree::insert( $blocks, $under, isset( $op['index'] ) ? (int) $op['index'] : null, $added );
					break;
				case 'replace':
					$added = self::new_blocks( $op, $n );
					if ( 1 !== count( $added ) ) {
						throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ': replace takes exactly one block; insert the others separately.' );
					}
					BlockTree::get( $blocks, $path );
					self::check( $added[0], $blocks, self::parent_of( $path ), $n );
					BlockTree::replace( $blocks, $path, $added[0] );
					break;
				case 'update':
					$block = BlockTree::get( $blocks, $path );
					$block = self::updated( $block, $op, $n, $notes );
					self::check( $block, $blocks, self::parent_of( $path ), $n );
					BlockTree::replace( $blocks, $path, $block );
					break;
				case 'move':
					if ( ! array_key_exists( 'to_parent', $op ) ) {
						throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ' (move) needs to_parent ("" for the top level) and to_index.' );
					}
					$to = (string) $op['to_parent'];
					if ( $to === $path || str_starts_with( $to, $path . '.' ) ) {
						throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ': a block cannot be moved into itself.' );
					}
					$block = BlockTree::remove( $blocks, $path );
					self::check( $block, $blocks, $to, $n );
					BlockTree::insert( $blocks, $to, isset( $op['to_index'] ) ? (int) $op['to_index'] : null, array( $block ) );
					break;
				case 'duplicate':
					$block = BlockTree::get( $blocks, $path );
					$steps = BlockTree::steps( $path );
					BlockTree::insert( $blocks, self::parent_of( $path ), (int) end( $steps ) + 1, array( $block ) );
					break;
				case 'remove':
					BlockTree::remove( $blocks, $path );
					break;
			}//end switch
		}//end foreach

		$content = serialize_blocks( $blocks );
		if ( serialize_blocks( parse_blocks( $original ) ) !== $original ) {
			$notes[] = 'WordPress re-wrote the stored form of some untouched blocks (attribute escaping or spacing), as the block editor does when it saves; what they show is unchanged.';
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'WordPress refused the change: ' . esc_html( $result->get_error_message() ) );
		}
		$saved = (string) get_post_field( 'post_content', $post->ID, 'raw' );
		if ( $saved !== $content ) {
			$notes[] = 'WordPress filtered the saved content (your account may not post unfiltered HTML, e.g. scripts or some attributes); read it back with kuduy-post-blocks.';
		}
		$count = count( array_filter( parse_blocks( $saved ), array( BlockTree::class, 'counts' ) ) );
		return array(
			'id'          => $post->ID,
			'applied'     => count( (array) $input['operations'] ),
			'block_count' => $count,
			'notes'       => $notes,
		);
	}

	/**
	 * A block with attributes set and removed, checked against its registered type.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param array<string, mixed> $op    Operation.
	 * @param int                  $n     Operation number.
	 * @param list<string>         $notes Notes, extended.
	 * @return array<string, mixed>
	 * @throws ToolError When an attribute is unknown or lives in the block's HTML.
	 */
	private static function updated( array $block, array $op, int $n, array &$notes ): array {
		$name = (string) ( $block['blockName'] ?? '' );
		$type = '' === $name ? null : \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( null === $type ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Operation ' . $n . ': the block at ' . $op['path'] . ' is ' . ( '' === $name ? 'HTML outside blocks' : 'of type ' . $name . ', which is not registered here' ) . ', so its attributes cannot be checked. Use replace.' );
		}
		$set   = (array) ( $op['set'] ?? array() );
		$unset = (array) ( $op['unset'] ?? array() );
		if ( ! $set && ! $unset ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Operation ' . $n . ' (update) needs set and/or unset.' );
		}
		$defined = (array) $type->attributes;
		foreach ( array_merge( array_keys( $set ), $unset ) as $key ) {
			if ( ! isset( $defined[ $key ] ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Operation ' . $n . ': ' . $name . ' has no attribute ' . $key . '. Its attributes: ' . implode( ', ', array_keys( $defined ) ) . ' (details: kuduy-block-schema).' );
			}
			if ( isset( $defined[ $key ]['source'] ) ) {
				throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Operation ' . $n . ': ' . $name . ' keeps ' . $key . ' in its HTML, not as a setting. Change it with replace and the complete block markup.' );
			}
		}
		$attrs = (array) ( $block['attrs'] ?? array() );
		foreach ( $unset as $key ) {
			unset( $attrs[ $key ] );
		}
		if ( ! empty( $op['merge'] ) ) {
			foreach ( $set as $key => $value ) {
				$set[ $key ] = is_array( $value ) && is_array( $attrs[ $key ] ?? null ) ? array_replace_recursive( $attrs[ $key ], $value ) : $value;
			}
		}
		$block['attrs'] = array_merge( $attrs, $set );
		if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			$notes[] = $name . ' at ' . $op['path'] . ' has HTML saved by the block editor, which was kept as it was. If the changed settings affect that HTML (classes, colours, alignment), replace the block with matching markup, or the editor will offer to recover it.';
		}
		return $block;
	}
}
