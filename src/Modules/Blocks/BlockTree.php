<?php
/**
 * Block tree operations on parsed blocks (phase C).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Works on the arrays `parse_blocks()` returns and keeps `innerContent` in step with `innerBlocks` (each `null` in
 * `innerContent` is the place of the next inner block, see `serialize_block()`), so the result serializes to valid
 * block markup. Paths are those of kuduy-post-blocks: indexes of blocks per level, whitespace-only HTML between
 * top-level blocks not counted ("0.2.1" = second inner block of the third inner block of the first block).
 */
final class BlockTree {

	/**
	 * Separator WordPress' editor writes between blocks.
	 */
	private const SEP = "\n\n";

	/**
	 * Whether a parsed top-level item counts as a block (skips whitespace between blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function counts( array $block ): bool {
		return null !== ( $block['blockName'] ?? null ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) );
	}

	/**
	 * Split a path into indexes.
	 *
	 * @param string $path Path such as "0.2.1"; "" is the top level (only valid as a parent).
	 * @return list<int>
	 * @throws ToolError When the path is malformed.
	 */
	public static function steps( string $path ): array {
		if ( '' === $path ) {
			return array();
		}
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A block path is numbers separated by dots, e.g. "0" or "2.1" (see kuduy-post-blocks).' );
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Raw array index of the n-th counted block in a list.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 * @param int                              $n    Visible index.
	 * @param bool                             $top  Top level (whitespace items are skipped).
	 */
	private static function raw_index( array $items, int $n, bool $top ): ?int {
		if ( ! $top ) {
			return isset( $items[ $n ] ) ? $n : null;
		}
		$seen = 0;
		foreach ( $items as $i => $block ) {
			if ( self::counts( $block ) ) {
				if ( $seen === $n ) {
					return $i;
				}
				++$seen;
			}
		}
		return null;
	}

	/**
	 * Number of counted blocks in a list.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 * @param bool                             $top  Top level.
	 */
	private static function count_in( array $items, bool $top ): int {
		return $top ? count( array_filter( $items, array( self::class, 'counts' ) ) ) : count( $items );
	}

	/**
	 * Read the block at a path.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level parsed blocks.
	 * @param string                           $path   Path.
	 * @return array<string, mixed>
	 * @throws ToolError When there is no block at the path.
	 */
	public static function get( array $blocks, string $path ): array {
		$steps = self::steps( $path );
		if ( ! $steps ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A block path is needed here, not the top level.' );
		}
		$items = $blocks;
		$top   = true;
		$node  = array();
		foreach ( $steps as $n ) {
			$i = self::raw_index( $items, $n, $top );
			if ( null === $i ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no block at path ' . $path . '. Read the current paths with kuduy-post-blocks.' );
			}
			$node  = $items[ $i ];
			$items = (array) ( $node['innerBlocks'] ?? array() );
			$top   = false;
		}
		return $node;
	}

	/**
	 * Remove the block at a path.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks, changed in place.
	 * @param string                           $path   Path.
	 * @return array<string, mixed> The removed block.
	 * @throws ToolError When there is no block at the path.
	 */
	public static function remove( array &$blocks, string $path ): array {
		$steps   = self::steps( $path );
		$last    = array_pop( $steps );
		$removed = array();
		self::at_parent(
			$blocks,
			$steps,
			$path,
			static function ( array &$items, ?array &$under ) use ( $last, $path, &$removed ): void {
				$top = null === $under;
				$i   = null === $last ? null : self::raw_index( $items, $last, $top );
				if ( null === $i ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no block at path ' . $path . '. Read the current paths with kuduy-post-blocks.' );
				}
				$removed = $items[ $i ];
				if ( $top ) {
					// Drop the block and one whitespace separator next to it.
					$next = $items[ $i + 1 ] ?? null;
					$prev = $items[ $i - 1 ] ?? null;
					$cut  = array( $i );
					if ( is_array( $next ) && ! self::counts( $next ) ) {
						$cut[] = $i + 1;
					} elseif ( is_array( $prev ) && ! self::counts( $prev ) ) {
						$cut[] = $i - 1;
					}
					foreach ( $cut as $k ) {
						unset( $items[ $k ] );
					}
					$items = array_values( $items );
					return;
				}
				array_splice( $items, $i, 1 );
				$under['innerBlocks']  = $items;
				$under['innerContent'] = self::content_without( (array) $under['innerContent'], $i );
			}
		);
		return $removed;
	}

	/**
	 * Insert blocks under a parent at a visible index.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks, changed in place.
	 * @param string                           $under Parent path ("" for the top level).
	 * @param int|null                         $index  Position among the parent's blocks; null = at the end.
	 * @param list<array<string, mixed>>       $added    Parsed blocks to insert (counted blocks only).
	 * @throws ToolError When the parent or index does not exist.
	 */
	public static function insert( array &$blocks, string $under, ?int $index, array $added ): void {
		self::at_parent(
			$blocks,
			self::steps( $under ),
			$under,
			static function ( array &$items, ?array &$node ) use ( $index, $added ): void {
				$top   = null === $node;
				$count = self::count_in( $items, $top );
				$at    = null === $index ? $count : $index;
				if ( $at < 0 || $at > $count ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, 'index ' . $at . ' is outside 0…' . $count . ' for this parent.' );
				}
				if ( $top ) {
					$sep = array(
						'blockName'    => null,
						'attrs'        => array(),
						'innerBlocks'  => array(),
						'innerHTML'    => self::SEP,
						'innerContent' => array( self::SEP ),
					);
					$add = array();
					foreach ( $added as $block ) {
						$add[] = $block;
						$add[] = $sep;
					}
					$raw = $at === $count ? count( $items ) : (int) self::raw_index( $items, $at, true );
					if ( $at === $count ) {
						// At the end: separator before the new blocks (if anything precedes them), none after.
						array_pop( $add );
						if ( $count > 0 ) {
							array_unshift( $add, $sep );
						}
					}
					array_splice( $items, $raw, 0, $add );
					return;
				}//end if
				$content = (array) $node['innerContent'];
				foreach ( $added as $k => $block ) {
					$content = self::content_with( $content, $at + $k, count( $items ) );
					array_splice( $items, $at + $k, 0, array( $block ) );
				}
				$node['innerBlocks']  = $items;
				$node['innerContent'] = $content;
			}
		);
	}

	/**
	 * Replace the block at a path.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks, changed in place.
	 * @param string                           $path   Path.
	 * @param array<string, mixed>             $block  New parsed block.
	 * @throws ToolError When there is no block at the path.
	 */
	public static function replace( array &$blocks, string $path, array $block ): void {
		self::update( $blocks, $path, static fn(): array => $block );
	}

	/**
	 * Change the block at a path with a callback.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks, changed in place.
	 * @param string                           $path   Path.
	 * @param callable                         $change Receives the block, returns the new block.
	 * @throws ToolError When there is no block at the path.
	 */
	public static function update( array &$blocks, string $path, callable $change ): void {
		$steps = self::steps( $path );
		$last  = array_pop( $steps );
		self::at_parent(
			$blocks,
			$steps,
			$path,
			static function ( array &$items, ?array &$node ) use ( $last, $path, $change ): void {
				$i = null === $last ? null : self::raw_index( $items, $last, null === $node );
				if ( null === $i ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no block at path ' . $path . '. Read the current paths with kuduy-post-blocks.' );
				}
				$items[ $i ] = $change( $items[ $i ] );
				if ( null !== $node ) {
					$node['innerBlocks'] = $items;
				}
			}
		);
	}

	/**
	 * Run a callback on the block list under a parent path (by reference, all the way down).
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks.
	 * @param list<int>                        $steps  Parent steps.
	 * @param string                           $path   Path for messages.
	 * @param callable                         $callback     Receives (&list, &parent-or-null).
	 * @throws ToolError When the parent does not exist.
	 */
	private static function at_parent( array &$blocks, array $steps, string $path, callable $callback ): void {
		if ( ! $steps ) {
			$none = null;
			$callback( $blocks, $none );
			return;
		}
		$n = array_shift( $steps );
		$i = self::raw_index( $blocks, $n, true );
		if ( null === $i || null === ( $blocks[ $i ]['blockName'] ?? null ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no block at path ' . $path . '. Read the current paths with kuduy-post-blocks.' );
		}
		self::descend( $blocks[ $i ], $steps, $path, $callback );
	}

	/**
	 * Walk down inner blocks.
	 *
	 * @param array<string, mixed> $node  Current block (by reference).
	 * @param list<int>            $steps Remaining steps.
	 * @param string               $path  Path for messages.
	 * @param callable             $callback    Callback.
	 * @throws ToolError When the parent does not exist.
	 */
	private static function descend( array &$node, array $steps, string $path, callable $callback ): void {
		if ( ! $steps ) {
			$items = (array) ( $node['innerBlocks'] ?? array() );
			$callback( $items, $node );
			return;
		}
		$n = array_shift( $steps );
		if ( ! isset( $node['innerBlocks'][ $n ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no block at path ' . $path . '. Read the current paths with kuduy-post-blocks.' );
		}
		self::descend( $node['innerBlocks'][ $n ], $steps, $path, $callback );
	}

	/**
	 * The innerContent without the k-th inner block and one whitespace separator next to it.
	 *
	 * @param list<string|null> $content innerContent.
	 * @param int               $k       Inner block index.
	 * @return list<string|null>
	 */
	private static function content_without( array $content, int $k ): array {
		$seen = -1;
		foreach ( $content as $pos => $chunk ) {
			if ( null === $chunk && ++$seen === $k ) {
				unset( $content[ $pos ] );
				$after  = $content[ $pos + 1 ] ?? false;
				$before = $content[ $pos - 1 ] ?? false;
				// Remove the separator only when it lies between this block and a neighbouring block.
				if ( is_string( $after ) && '' === trim( $after ) && array_key_exists( $pos + 2, $content ) && null === $content[ $pos + 2 ] ) {
					unset( $content[ $pos + 1 ] );
				} elseif ( is_string( $before ) && '' === trim( $before ) && array_key_exists( $pos - 2, $content ) && null === $content[ $pos - 2 ] ) {
					unset( $content[ $pos - 1 ] );
				}
				break;
			}
		}
		return array_values( $content );
	}

	/**
	 * The innerContent with a place for a new inner block at index k.
	 *
	 * @param list<string|null> $content innerContent.
	 * @param int               $k       Index of the new block.
	 * @param int               $count   Inner blocks before the insert.
	 * @return list<string|null>
	 * @throws ToolError When an empty block has no closing tag to insert before.
	 */
	private static function content_with( array $content, int $k, int $count ): array {
		$nulls = array_keys( array_filter( $content, 'is_null' ) );
		if ( 0 === $count ) {
			// An empty container such as <div class="wp-block-group"></div>: the inner blocks go before its closing tag.
			$html = implode( '', array_filter( $content, 'is_string' ) );
			$cut  = strrpos( $html, '</' );
			if ( false === $cut ) {
				if ( '' === trim( $html ) ) {
					return array( null );
				}
				throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'This block has no place for inner blocks in its HTML. Replace the whole block with markup that contains the inner blocks.' );
			}
			return array( substr( $html, 0, $cut ), null, substr( $html, $cut ) );
		}
		if ( $k < $count ) {
			array_splice( $content, $nulls[ $k ], 0, array( null, self::SEP ) );
		} else {
			array_splice( $content, $nulls[ $count - 1 ] + 1, 0, array( self::SEP, null ) );
		}
		return $content;
	}

	/**
	 * Parse markup into the counted blocks it contains.
	 *
	 * @param string $markup Block markup.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When the markup holds no block or HTML outside blocks.
	 */
	public static function parse_markup( string $markup ): array {
		$out = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( ! self::counts( $block ) ) {
				continue;
			}
			if ( null === ( $block['blockName'] ?? null ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The markup has HTML outside block comments (' . mb_substr( trim( wp_strip_all_tags( (string) $block['innerHTML'] ) ), 0, 60 ) . '…). Wrap it in a block, e.g. <!-- wp:html -->…<!-- /wp:html -->.' );
			}
			$out[] = $block;
		}
		if ( ! $out ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'The markup contains no block. Block markup looks like <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->.' );
		}
		return $out;
	}

	/**
	 * Problems of one block and its inner blocks against the registered block types.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param string|null          $under Name of the parent block (null at the top level).
	 * @param list<string>         $chain  Names of all ancestors.
	 * @param string               $path   Path for messages.
	 * @return list<string>
	 */
	public static function problems( array $block, ?string $under, array $chain, string $path ): array {
		$name = $block['blockName'] ?? null;
		if ( null === $name ) {
			return '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ? array() : array( $path . ': HTML outside any block (shown as Classic block in the editor).' );
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $name );
		if ( null === $type ) {
			return array( $path . ': block type ' . $name . ' is not registered on this site (its plugin may be inactive).' );
		}
		$out = array();
		foreach ( (array) ( $block['attrs'] ?? array() ) as $key => $value ) {
			$definition = $type->attributes[ $key ] ?? null;
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$schema = array_intersect_key( $definition, array_flip( array( 'type', 'enum', 'items', 'properties', 'minimum', 'maximum', 'pattern' ) ) );
			if ( isset( $schema['type'] ) && ! self::known_type( $schema['type'] ) ) {
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, $schema, (string) $key );
			if ( is_wp_error( $valid ) ) {
				$out[] = $path . ': ' . $name . ' attribute ' . $key . ': ' . $valid->get_error_message();
			}
		}
		if ( is_array( $type->parent ) && $type->parent && ! in_array( $under, $type->parent, true ) ) {
			$out[] = $path . ': ' . $name . ' can only be placed directly inside ' . implode( ' or ', $type->parent ) . '.';
		}
		if ( is_array( $type->ancestor ) && $type->ancestor && ! array_intersect( $type->ancestor, $chain ) ) {
			$out[] = $path . ': ' . $name . ' can only be placed somewhere inside ' . implode( ' or ', $type->ancestor ) . '.';
		}
		$allowed = $type->allowed_blocks;
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $k => $inner ) {
			$child = (string) ( $inner['blockName'] ?? '' );
			if ( is_array( $allowed ) && $allowed && '' !== $child && ! in_array( $child, $allowed, true ) ) {
				$out[] = $path . '.' . $k . ': ' . $name . ' only accepts ' . implode( ', ', $allowed ) . ' inside it, not ' . $child . '.';
			}
			$out = array_merge( $out, self::problems( $inner, (string) $name, array_merge( $chain, array( (string) $name ) ), $path . '.' . $k ) );
		}
		return $out;
	}

	/**
	 * Whether rest_validate_value_from_schema() can check this attribute type (e.g. not "rich-text").
	 *
	 * @param mixed $type Type or list of types.
	 */
	private static function known_type( mixed $type ): bool {
		$known = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );
		foreach ( (array) $type as $one ) {
			if ( ! in_array( $one, $known, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Problems of a whole list of top-level blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Top-level blocks.
	 * @return list<string>
	 */
	public static function document_problems( array $blocks ): array {
		$out = array();
		$n   = 0;
		foreach ( $blocks as $block ) {
			if ( ! self::counts( $block ) ) {
				continue;
			}
			$out = array_merge( $out, self::problems( $block, null, array(), (string) $n ) );
			++$n;
		}
		return $out;
	}
}
