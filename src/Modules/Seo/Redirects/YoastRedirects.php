<?php
/**
 * Yoast SEO Premium redirects.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Yoast SEO Premium keeps redirects in its options (wpseo-premium-redirects-base, plus the plain and regex export
 * options) and edits them with WPSEO_Redirect_Manager after WPSEO_Redirect_Validator (read on Yoast SEO Premium 28.8,
 * classes/redirect/ and classes/premium-redirect-service.php — the path of its own REST service). Errors stop a
 * save; warnings (for example a target that does not answer) are returned and the redirect is saved, as with the
 * service's ignore_warning. A Yoast redirect is identified by its origin ("regex:" in front for regex redirects), has
 * no on/off switch and no hit count. Capability: wpseo_manage_redirects.
 */
final class YoastRedirects implements RedirectStore {

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Yoast SEO Premium';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool|string {
		return defined( 'WPSEO_PREMIUM_VERSION' ) && class_exists( 'WPSEO_Redirect_Manager' ) && class_exists( 'WPSEO_Redirect_Validator' ) ? true : 'Yoast SEO Premium is not active (redirects are a Premium feature).';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ToolError When not allowed.
	 */
	public function check_access(): void {
		if ( ! current_user_can( 'wpseo_manage_redirects' ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Yoast SEO Premium lets only users with wpseo_manage_redirects manage redirects.' );
		}
	}

	/**
	 * A redirect in the tool's shape.
	 *
	 * @param \WPSEO_Redirect $redirect Redirect.
	 * @return array<string, mixed>
	 */
	private static function row( \WPSEO_Redirect $redirect ): array {
		$regex = \WPSEO_Redirect_Formats::REGEX === $redirect->get_format();
		return array(
			'id'      => ( $regex ? 'regex:' : '' ) . $redirect->get_origin(),
			'source'  => (string) $redirect->get_origin(),
			'target'  => (string) $redirect->get_target(),
			'type'    => (int) $redirect->get_type(),
			'regex'   => $regex,
			'enabled' => true,
			'hits'    => null,
		);
	}

	/**
	 * The stored redirect by id, or null.
	 *
	 * @param string $id Id.
	 */
	private static function find( string $id ): ?\WPSEO_Redirect {
		foreach ( ( new \WPSEO_Redirect_Manager() )->get_all_redirects() as $redirect ) {
			if ( self::row( $redirect )['id'] === $id ) {
				return $redirect;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $search Search.
	 * @param int    $page   Page.
	 */
	public function all( string $search, int $page ): array {
		$rows = array_map( array( self::class, 'row' ), array_values( ( new \WPSEO_Redirect_Manager() )->get_all_redirects() ) );
		if ( '' !== $search ) {
			$rows = array_values( array_filter( $rows, static fn( $r ) => false !== stripos( $r['source'] . ' ' . $r['target'], $search ) ) );
		}
		return array(
			'redirects' => array_slice( $rows, 50 * ( max( 1, $page ) - 1 ), 50 ),
			'total'     => count( $rows ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): ?array {
		$redirect = self::find( $id );
		return null === $redirect ? null : self::row( $redirect );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null          $id     Id.
	 * @param array<string, mixed> $fields Fields.
	 * @throws ToolError When Yoast refuses or a field cannot be set in Yoast.
	 */
	public function save( ?string $id, array $fields ): array {
		if ( array_key_exists( 'enabled', $fields ) && ! $fields['enabled'] ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Yoast SEO Premium has no switch to turn a redirect off; delete it instead. Nothing was saved.' );
		}
		$current = null === $id ? null : self::find( $id );
		if ( null !== $id && null === $current ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Yoast SEO Premium has no redirect ' . $id . '. Nothing was saved.' );
		}
		$old = null === $current ? array() : self::row( $current );
		if ( null !== $current && ( ( isset( $fields['source'] ) && $fields['source'] !== $old['source'] ) || ( isset( $fields['regex'] ) && (bool) $fields['regex'] !== $old['regex'] ) ) ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Yoast SEO Premium identifies a redirect by its source: to change the source or the regex setting, delete the redirect and create a new one. Nothing was saved.' );
		}
		$all      = array_merge(
			array(
				'source' => '',
				'target' => '',
				'type'   => 301,
				'regex'  => false,
			),
			$old,
			$fields
		);
		$format   = $all['regex'] ? \WPSEO_Redirect_Formats::REGEX : \WPSEO_Redirect_Formats::PLAIN;
		$type     = (int) $all['type'];
		$redirect = new \WPSEO_Redirect( (string) $all['source'], in_array( $type, array( 410, 451 ), true ) ? '' : (string) $all['target'], $type, $format );
		$warnings = array();
		$check    = new \WPSEO_Redirect_Validator();
		if ( ! $check->validate( $redirect, $current ) ) {
			$error = $check->get_error();
			$error = is_object( $error ) && method_exists( $error, 'to_array' ) ? (array) $error->to_array() : array(
				'type'    => 'error',
				'message' => 'invalid redirect',
			);
			if ( 'warning' !== ( $error['type'] ?? 'error' ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Yoast SEO Premium refused the redirect: ' . wp_strip_all_tags( (string) ( $error['message'] ?? '' ) ) . ' Nothing was saved.' );
			}
			$warnings[] = wp_strip_all_tags( (string) ( $error['message'] ?? '' ) );
		}
		$manager = new \WPSEO_Redirect_Manager( $format );
		$done    = null === $current ? $manager->create_redirect( $redirect ) : $manager->update_redirect( $current, $redirect );
		if ( ! $done ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Yoast SEO Premium did not save the redirect. Nothing was saved.' );
		}
		$saved = self::find( self::row( $redirect )['id'] );
		if ( null === $saved ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Yoast SEO Premium did not keep the redirect.' );
		}
		return array(
			'redirect' => self::row( $saved ),
			'warnings' => $warnings,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 * @throws ToolError When there is no such redirect.
	 */
	public function delete( string $id ): void {
		$current = self::find( $id );
		if ( null === $current ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Yoast SEO Premium has no redirect ' . $id . '.' );
		}
		( new \WPSEO_Redirect_Manager( $current->get_format() ) )->delete_redirects( array( $current ) );
		if ( null !== self::find( $id ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Yoast SEO Premium did not delete the redirect.' );
		}
	}
}
