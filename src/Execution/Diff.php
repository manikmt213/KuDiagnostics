<?php
/**
 * Structural comparison of two snapshot payloads (phase J, D81).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Execution;

use Kuduy\Mcp\Audit\Redactor;

/**
 * Walks two decoded JSON values and lists what differs, by path (`meta._price`, `elements.0.settings.title`).
 * Values are redacted and long ones shortened; a list of scalars that changed is reported once, as a whole.
 */
final class Diff {

	public const MAX_CHANGES = 200;
	private const MAX_VALUE  = 300;

	/**
	 * Collect the differences between $before and $after under $path.
	 *
	 * @param mixed                      $before  Saved value.
	 * @param mixed                      $after   Current value.
	 * @param string                     $path    Path so far ('' at the top).
	 * @param list<array<string, mixed>> $changes Changes found (appended).
	 */
	public static function walk( mixed $before, mixed $after, string $path, array &$changes ): void {
		if ( self::same( $before, $after ) ) {
			return;
		}
		if ( is_array( $before ) && is_array( $after ) && ( self::nested( $before ) || self::nested( $after ) || ! array_is_list( $before ) || ! array_is_list( $after ) ) ) {
			foreach ( array_keys( $before + $after ) as $key ) {
				$sub = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $after ) ) {
					$changes[] = array(
						'path'   => $sub,
						'change' => 'removed',
						'before' => self::show( $before[ $key ], $sub ),
					);
				} elseif ( ! array_key_exists( $key, $before ) ) {
					$changes[] = array(
						'path'   => $sub,
						'change' => 'added',
						'after'  => self::show( $after[ $key ], $sub ),
					);
				} else {
					self::walk( $before[ $key ], $after[ $key ], $sub, $changes );
				}
			}
			return;
		}//end if
		$changes[] = array(
			'path'   => '' === $path ? '(whole)' : $path,
			'change' => 'changed',
			'before' => self::show( $before, $path ),
			'after'  => self::show( $after, $path ),
		);
	}

	/**
	 * Equal after JSON decoding (`1` and `"1"` differ; key order does not matter for objects).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 */
	private static function same( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $key => $value ) {
				if ( ! array_key_exists( $key, $b ) || ! self::same( $value, $b[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b || ( ( is_float( $a ) || is_float( $b ) ) && is_numeric( $a ) && is_numeric( $b ) && (float) $a === (float) $b );
	}

	/**
	 * Whether an array holds arrays.
	 *
	 * @param array<mixed> $value Value.
	 */
	private static function nested( array $value ): bool {
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A value as reported: redacted (by its key) and, as JSON text, at most MAX_VALUE characters.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Path, whose last part is the key the redactor judges.
	 */
	private static function show( mixed $value, string $path ): mixed {
		$key   = (string) substr( $path, (int) strrpos( '.' . $path, '.' ) );
		$value = Redactor::redact_array( array( $key => $value ) )[ $key ] ?? null;
		$json  = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) <= self::MAX_VALUE ) {
			return $value;
		}
		return mb_substr( is_string( $value ) ? $value : $json, 0, self::MAX_VALUE ) . '… (' . strlen( $json ) . ' bytes)';
	}
}
