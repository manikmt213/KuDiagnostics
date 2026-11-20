<?php
/**
 * Storage of the site's memory (table kuduy_memory, D70).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Memory;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table (architecture §5), always through prepare().

use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Modules\Dev\DevText;

/**
 * Rows of {prefix}kuduy_memory. Tags and refs are stored as JSON lists. user_id 0 means shared with every connected
 * user who may read memory; otherwise the row is private to that user.
 */
final class MemoryStore {

	/**
	 * Columns written by insert and update.
	 */
	private const FIELDS = array( 'kind', 'title', 'body', 'tags', 'project', 'user_id', 'refs', 'status', 'version', 'expires_at', 'created_by', 'created_at', 'updated_at' );

	/**
	 * Well-known credential formats (beyond what the redactor and the credential masker catch): API keys of common
	 * services, private keys, JSON web tokens.
	 */
	private const KEY_FORMATS = array(
		'/\bsk-(?:proj-|ant-)?[A-Za-z0-9_-]{20,}/' => 'an API secret key (sk-…)',
		'/\b[sr]k_(?:live|test)_[A-Za-z0-9]{16,}/' => 'a payment secret key (sk_live_/rk_…)',
		'/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}|\bgithub_pat_[A-Za-z0-9_]{30,}/' => 'a GitHub token',
		'/\bxox[abposr]-[A-Za-z0-9-]{10,}/'        => 'a Slack token',
		'/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'          => 'an AWS access key',
		'/\bAIza[0-9A-Za-z_-]{35}\b/'              => 'a Google API key',
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----/'     => 'a private key',
		'/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/' => 'a signed token (JWT)',
	);

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'kuduy_memory';
	}

	/**
	 * Why a text may not be stored (it holds a secret), or '' when it may.
	 *
	 * @param string $text Text.
	 */
	public static function secret_reason( string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}
		if ( DevText::mask( $text ) !== $text ) {
			return 'it contains a value that looks like a password, key or token';
		}
		if ( Redactor::redact( $text ) !== $text ) {
			return 'it contains a bearer token, a KuDiagnostics token or a password hash';
		}
		foreach ( self::KEY_FORMATS as $pattern => $what ) {
			if ( preg_match( $pattern, $text ) ) {
				return 'it contains ' . $what;
			}
		}
		return '';
	}

	/**
	 * One row, decoded.
	 *
	 * @param int $id Memory id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Rows by filter, newest first. Visibility: shared rows and the user's own, unless $everyone.
	 *
	 * @param array<string, mixed> $filter kind, project, status, include_expired, words (any of), ids.
	 * @param int                  $user   Reading user.
	 * @param int                  $limit  Rows.
	 * @param int                  $offset Offset.
	 * @param bool                 $everyone Every row (admin bulk operations).
	 * @return array{0: list<array<string, mixed>>, 1: int}
	 */
	public static function find( array $filter, int $user, int $limit = 100, int $offset = 0, bool $everyone = false ): array {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( ! $everyone ) {
			$where[] = '( user_id = 0 OR user_id = %d )';
			$args[]  = $user;
		}
		foreach ( array( 'kind', 'project', 'status' ) as $key ) {
			if ( isset( $filter[ $key ] ) && '' !== (string) $filter[ $key ] ) {
				$where[] = $key . ' = %s';
				$args[]  = (string) $filter[ $key ];
			}
		}
		if ( empty( $filter['include_expired'] ) ) {
			$where[] = '( expires_at IS NULL OR expires_at > %s )';
			$args[]  = current_time( 'mysql', true );
		}
		$words = array_values( array_filter( (array) ( $filter['words'] ?? array() ), static fn( $w ): bool => is_string( $w ) && '' !== $w ) );
		if ( $words ) {
			$any = array();
			foreach ( $words as $word ) {
				$like  = '%' . $wpdb->esc_like( $word ) . '%';
				$any[] = '( title LIKE %s OR body LIKE %s OR tags LIKE %s )';
				array_push( $args, $like, $like, $like );
			}
			$where[] = '( ' . implode( ' OR ', $any ) . ' )';
		}
		$sql   = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $sql, array_merge( array( self::table() ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders and column names; the values are in the array.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $sql . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d', array_merge( array( self::table() ), $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders and column names; the values are in the array.
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::decode( (array) $row );
		}
		return array( $out, $total );
	}

	/**
	 * Insert a row (with its id when given, for undo).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int New id, 0 on failure.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$data = self::encode( $row );
		if ( isset( $row['id'] ) ) {
			$data['id'] = (int) $row['id'];
		}
		return false === $wpdb->insert( self::table(), $data ) ? 0 : (int) ( $data['id'] ?? $wpdb->insert_id );
	}

	/**
	 * Replace a row's fields.
	 *
	 * @param int                  $id  Memory id.
	 * @param array<string, mixed> $row Full row.
	 */
	public static function update( int $id, array $row ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), self::encode( $row ), array( 'id' => $id ) );
	}

	/**
	 * Delete rows.
	 *
	 * @param list<int> $ids Ids.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$n = 0;
		foreach ( $ids as $id ) {
			$n += (int) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		}
		return $n;
	}

	/**
	 * Database row → array with lists decoded.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		foreach ( array( 'tags', 'refs' ) as $key ) {
			$list        = json_decode( (string) ( $row[ $key ] ?? '[]' ), true );
			$row[ $key ] = is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
		}
		foreach ( array( 'id', 'user_id', 'version', 'created_by' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		return $row;
	}

	/**
	 * Array → database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function encode( array $row ): array {
		$out = array();
		foreach ( self::FIELDS as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$out[ $key ] = in_array( $key, array( 'tags', 'refs' ), true ) ? (string) wp_json_encode( array_values( (array) $row[ $key ] ) ) : $row[ $key ];
		}
		return $out;
	}
}
