<?php
/**
 * SEOPress PRO redirects.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * SEOPress PRO keeps redirects as seopress_404 posts (origin in the title, meta _seopress_redirections_*) and edits
 * them through its own REST controller (read on SEOPress PRO 10.2.1, src/Actions/Api/Redirections.php):
 * GET/POST seopress/v1/redirections, GET/PUT/DELETE seopress/v1/redirections/{id}. Its permission callbacks check the
 * redirection capabilities (read_redirection, publish_redirections, edit_redirection, delete_redirection); its
 * handlers normalize the origin, keep regex patterns verbatim and accept only its types. A row without a type is a
 * logged 404, not a redirect, and is left out. Deleting is permanent in SEOPress (wp_delete_post with force).
 */
final class SeopressRedirects implements RedirectStore {

	private const BASE = '/seopress/v1/redirections';

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'SEOPress PRO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		return defined( 'SEOPRESS_PRO_VERSION' ) ? true : 'SEOPress PRO is not active (redirects are a PRO feature).';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ToolError When not allowed.
	 */
	public function check_access(): void {
		// Checked before any read (one redirect is read outside the plugin's routes); the routes check again.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_redirections' ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'SEOPress PRO lets only users who manage redirects (edit_redirections) change them.' );
		}
	}

	/**
	 * A row in the tool's shape.
	 *
	 * @param array<string, mixed> $row SEOPress row.
	 * @return array<string, mixed>
	 */
	private static function row( array $row ): array {
		return array(
			'id'      => (string) ( $row['id'] ?? '' ),
			'source'  => (string) ( $row['origin'] ?? '' ),
			'target'  => (string) ( $row['destination'] ?? '' ),
			'type'    => (int) ( $row['type'] ?? 0 ),
			'regex'   => ! empty( $row['enabledRegex'] ),
			'enabled' => ! empty( $row['enabled'] ),
			'hits'    => (int) ( $row['count'] ?? 0 ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $search Search.
	 * @param int    $page   Page.
	 */
	public function all( string $search, int $page ): array {
		$query = array(
			'per_page' => 50,
			'page'     => max( 1, $page ),
		);
		if ( '' !== $search ) {
			$query['search'] = $search;
		}
		$data = RestRedirects::call( 'GET', self::BASE, $query, $this->name() );
		$rows = array_values( array_filter( (array) ( $data['data'] ?? array() ), static fn( $r ) => is_array( $r ) && '' !== (string) ( $r['type'] ?? '' ) ) );
		return array(
			'redirects' => array_map( array( self::class, 'row' ), $rows ),
			'total'     => (int) ( $data['total'] ?? count( $rows ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): ?array {
		if ( (int) $id <= 0 || 'seopress_404' !== get_post_type( (int) $id ) ) {
			return null;
		}
		$data = RestRedirects::call( 'GET', self::BASE . '/' . (int) $id, array(), $this->name() );
		return '' === (string) ( $data['type'] ?? '' ) ? null : self::row( $data );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null          $id     Id.
	 * @param array<string, mixed> $fields Fields.
	 */
	public function save( ?string $id, array $fields ): array {
		$body = array();
		foreach (
			array(
				'source'  => 'origin',
				'target'  => 'destination',
				'regex'   => 'enabledRegex',
				'enabled' => 'enabled',
			) as $ours => $theirs
		) {
			if ( array_key_exists( $ours, $fields ) ) {
				$body[ $theirs ] = $fields[ $ours ];
			}
		}
		if ( isset( $fields['type'] ) ) {
			$body['type'] = (string) $fields['type'];
		}
		$data = null === $id
			? RestRedirects::call( 'POST', self::BASE, $body, $this->name() )
			: RestRedirects::call( 'PUT', self::BASE . '/' . (int) $id, $body, $this->name() );
		return array(
			'redirect' => self::row( (array) ( $data['data'] ?? array() ) ),
			'warnings' => array(),
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
