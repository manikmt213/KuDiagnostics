<?php
/**
 * Single-use confirmation tokens for high-risk tools (decision D45).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Execution;

use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * A token is random, lives ten minutes, and is stored only as its SHA-256 together with a fingerprint of who asked
 * (user and OAuth client), which tool, which arguments and the state of the target at preview time. Using a token
 * always deletes it, whether or not it matches.
 */
final class Confirmation {

	/**
	 * Name of the token argument.
	 */
	public const ARG = 'confirm_token';

	/**
	 * Lifetime in seconds.
	 */
	public const TTL = 600;

	/**
	 * JSON Schema of the token argument, for a Confirmable tool's input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function token_input(): array {
		return array(
			'type'        => 'string',
			'maxLength'   => 64,
			'pattern'     => '^[a-f0-9]{48}$',
			'description' => 'Leave out on the first call: nothing changes and the result describes what would happen, with a confirm_token. Show that to the user and ask. Only after they agree, call again with the same arguments plus the confirm_token (valid 10 minutes, usable once).',
		);
	}

	/**
	 * Properties a preview result carries, for a Confirmable tool's output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_properties(): array {
		return array(
			'confirmation_required' => array( 'type' => 'boolean' ),
			'preview'               => array( 'type' => 'object' ),
			'confirm_token'         => array( 'type' => 'string' ),
			'expires_in'            => array( 'type' => 'integer' ),
			'next'                  => array( 'type' => 'string' ),
		);
	}

	/**
	 * Issue a token for this call.
	 *
	 * @param string               $tool    Tool name.
	 * @param array<string, mixed> $input   Arguments without the token.
	 * @param string               $version Fingerprint of the target's state.
	 */
	public static function issue( string $tool, array $input, string $version ): string {
		$token = bin2hex( random_bytes( 24 ) );
		set_transient( self::key( $token ), self::fingerprint( $tool, $input, $version ), self::TTL );
		return $token;
	}

	/**
	 * Use a token: true only when it exists, was not used before and was issued for exactly this user, client,
	 * tool, arguments and target state. The token is gone afterwards in every case.
	 *
	 * @param string               $token   Token from the client.
	 * @param string               $tool    Tool name.
	 * @param array<string, mixed> $input   Arguments without the token.
	 * @param string               $version Fingerprint of the target's state now.
	 */
	public static function consume( string $token, string $tool, array $input, string $version ): bool {
		$key    = self::key( $token );
		$stored = get_transient( $key );
		if ( ! is_string( $stored ) || ! delete_transient( $key ) ) {
			return false;
		}
		return hash_equals( $stored, self::fingerprint( $tool, $input, $version ) );
	}

	/**
	 * Reference for the audit log: identifies the confirmation without being usable as a token.
	 *
	 * @param string $token Token.
	 */
	public static function reference( string $token ): string {
		return substr( hash( 'sha256', $token ), 0, 12 );
	}

	/**
	 * Transient name.
	 *
	 * @param string $token Token.
	 */
	private static function key( string $token ): string {
		return 'kuduy_mcp_confirm_' . hash( 'sha256', $token );
	}

	/**
	 * What a token is bound to.
	 *
	 * @param string               $tool    Tool name.
	 * @param array<string, mixed> $input   Arguments without the token.
	 * @param string               $version Target state.
	 */
	private static function fingerprint( string $tool, array $input, string $version ): string {
		unset( $input[ self::ARG ] );
		return hash( 'sha256', (string) wp_json_encode( array( get_current_user_id(), (string) TokenAuth::client_id(), $tool, self::sorted( $input ), $version ) ) );
	}

	/**
	 * Arguments with object keys in a fixed order, so the same arguments always give the same fingerprint.
	 *
	 * @param mixed $value Value.
	 */
	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sorted' ), $value );
	}
}
