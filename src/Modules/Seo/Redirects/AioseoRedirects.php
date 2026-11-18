<?php
/**
 * All in One SEO Pro redirects.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * AIOSEO Pro keeps redirects in its own table (aioseo_redirects) and edits them through its own REST routes (read on
 * AIOSEO Pro 5.0.5, app/Pro/Api/Api.php and Redirects.php): POST aioseo/v1/redirects/all (list), POST
 * aioseo/v1/redirects (create), POST aioseo/v1/redirects/{id} (change), POST aioseo/v1/redirects/bulk/enable|disable,
 * DELETE aioseo/v1/redirects/{id}; access aioseo_redirects_manage (administrators always). Its handler refuses a
 * source already in use (HTTP 409), clears its redirect cache and rewrites its server rules. One redirect is read with
 * its model (Models\Redirect::getRedirectById()), as its handlers do.
 */
final class AioseoRedirects implements RedirectStore {

	private const BASE  = '/aioseo/v1/redirects';
	private const MODEL = '\AIOSEO\Plugin\Pro\Redirects\Models\Redirect';

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'All in One SEO Pro';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		return function_exists( 'aioseo' ) && ! empty( aioseo()->redirects ) && class_exists( self::MODEL ) ? true : 'All in One SEO Pro redirects are not active (a Pro feature).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_access(): void {
		// AIOSEO's own route access check decides (RestRedirects::call).
	}

	/**
	 * A row in the tool's shape.
	 *
	 * @param array<string, mixed>|object $row AIOSEO row or model.
	 * @return array<string, mixed>
	 */
	private static function row( array|object $row ): array {
		if ( is_object( $row ) ) {
			// AIOSEO's models expose their columns as properties.
			$row = array(
				'id'         => $row->id ?? '',
				'source_url' => $row->source_url ?? '',
				'target_url' => $row->target_url ?? '',
				'type'       => $row->type ?? 0,
				'regex'      => $row->regex ?? false,
				'enabled'    => $row->enabled ?? false,
				'hits'       => $row->hits ?? 0,
			);
		}
		return array(
			'id'      => (string) ( $row['id'] ?? '' ),
			'source'  => (string) ( $row['source_url'] ?? '' ),
			'target'  => (string) ( $row['target_url'] ?? '' ),
			'type'    => (int) ( $row['type'] ?? 0 ),
			'regex'   => ! empty( $row['regex'] ),
			'enabled' => ! empty( $row['enabled'] ),
			'hits'    => (int) ( $row['hits'] ?? 0 ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $search Search.
	 * @param int    $page   Page.
	 */
	public function all( string $search, int $page ): array {
		$body = array(
			'limit'  => 50,
			'offset' => 50 * ( max( 1, $page ) - 1 ),
		);
		if ( '' !== $search ) {
			$body['searchTerm'] = $search;
		}
		$data = RestRedirects::call( 'POST', self::BASE . '/all', $body, $this->name() );
		return array(
			'redirects' => array_map( array( self::class, 'row' ), array_values( (array) ( $data['rows'] ?? array() ) ) ),
			'total'     => (int) ( $data['totals']['total'] ?? 0 ),
		);
	}

	/**
	 * The stored model, or null.
	 *
	 * @param string $id Id.
	 */
	private static function model( string $id ): ?object {
		if ( (int) $id <= 0 ) {
			return null;
		}
		$model = call_user_func( array( self::MODEL, 'getRedirectById' ), (int) $id );
		return is_object( $model ) && $model->exists() ? $model : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): ?array {
		$model = self::model( $id );
		return null === $model ? null : self::row( $model );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null          $id     Id.
	 * @param array<string, mixed> $fields Fields.
	 * @throws ToolError When AIOSEO refuses.
	 */
	public function save( ?string $id, array $fields ): array {
		$model = null === $id ? null : self::model( $id );
		$old   = null === $model ? array() : self::row( $model );
		$all   = array_merge(
			array(
				'source' => '',
				'target' => '',
				'type'   => 301,
				'regex'  => false,
			),
			$old,
			$fields
		);
		$type  = (int) $all['type'];
		$core  = array_intersect_key( $fields, array_flip( array( 'source', 'target', 'type', 'regex' ) ) );
		$saved = $id;
		if ( null === $id || $core ) {
			$body = array(
				'sourceUrls'            => array(
					array(
						'url'   => (string) $all['source'],
						'regex' => (bool) $all['regex'],
					),
				),
				'targetUrl'             => in_array( $type, array( 410, 451 ), true ) ? '' : (string) $all['target'],
				'redirectType'          => $type,
				'redirectTypeHasTarget' => ! in_array( $type, array( 410, 451 ), true ),
			);
			if ( null !== $model ) {
				// AIOSEO's handler resets these to its defaults when they are not sent.
				$body['group']      = (string) ( $model->group ?? 'manual' );
				$body['queryParam'] = (string) ( $model->query_param ?? '' );
				if ( ! empty( $model->post_id ) ) {
					$body['postId'] = (int) $model->post_id;
				}
			}
			$data  = RestRedirects::call( 'POST', null === $id ? self::BASE : self::BASE . '/' . (int) $id, $body, $this->name() );
			$made  = $data['redirect'] ?? null;
			$saved = (string) ( is_object( $made ) ? ( $made->id ?? '' ) : ( is_array( $made ) ? ( $made['id'] ?? '' ) : '' ) );
			if ( '' === $saved || '0' === $saved ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, $this->name() . ' did not report the saved redirect.' );
			}
		}//end if
		if ( array_key_exists( 'enabled', $fields ) ) {
			RestRedirects::call( 'POST', self::BASE . '/bulk/' . ( $fields['enabled'] ? 'enable' : 'disable' ), array( 'rowIds' => array( (int) $saved ) ), $this->name() );
		}
		$row = $this->get( (string) $saved );
		if ( null === $row ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, $this->name() . ' did not keep the redirect.' );
		}
		$warnings = array();
		if ( array_key_exists( 'enabled', $fields ) && $fields['enabled'] && ! $row['enabled'] ) {
			$warnings[] = 'AIOSEO keeps a redirect without the target its type needs switched off.';
		}
		return array(
			'redirect' => $row,
			'warnings' => $warnings,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function delete( string $id ): void {
		RestRedirects::call( 'DELETE', self::BASE . '/' . (int) $id, array(), $this->name() );
	}
}
