<?php
/**
 * OAuth scopes and their hierarchy (architecture §8).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * Read ⊂ write ⊂ admin. `kuduy:code` stands alone and exists only when D4's constant is set.
 */
final class Scopes {

	public const READ = 'kuduy:read';

	public const WRITE = 'kuduy:write';

	public const ADMIN = 'kuduy:admin';

	public const CODE = 'kuduy:code';

	/**
	 * Scopes requested when a client asks for none, and named in 401 challenges.
	 */
	public const DEFAULT = array( self::READ, self::WRITE );

	private const RANK = array(
		self::READ  => 1,
		self::WRITE => 2,
		self::ADMIN => 3,
	);

	/**
	 * A scope's name for people (consent form, KuDiagnostics screen; D85). Wording only: what a scope allows is
	 * decided by allows() and the tools.
	 *
	 * @param string $scope Scope.
	 */
	public static function label( string $scope ): string {
		return match ( $scope ) {
			self::READ  => __( 'Read content and settings', 'kuduy-mcp' ),
			self::WRITE => __( 'Create and edit content', 'kuduy-mcp' ),
			self::ADMIN => __( 'Full Site Administration', 'kuduy-mcp' ),
			self::CODE  => __( 'Run code (developer mode)', 'kuduy-mcp' ),
			default     => $scope,
		};
	}

	/**
	 * What a scope adds, in one sentence.
	 *
	 * @param string $scope Scope.
	 */
	public static function explain( string $scope ): string {
		return match ( $scope ) {
			self::READ  => __( 'Pages, posts, media, settings and the state of plugins and themes, without changing anything.', 'kuduy-mcp' ),
			self::WRITE => __( 'Write and change pages, posts, media, menus, forms and page-builder layouts. Most changes can be undone.', 'kuduy-mcp' ),
			self::ADMIN => __( 'Everything a site administrator manages: plugins, themes, users, settings, site templates, notification routing and permanent deletes. Still limited by your own WordPress permissions; risky actions ask first.', 'kuduy-mcp' ),
			self::CODE  => __( 'Write theme and plugin files and run PHP on the server. Offered only because the site owner switched developer mode on in wp-config.php. Separate from Full Site Administration.', 'kuduy-mcp' ),
			default     => '',
		};
	}

	/**
	 * Resource scopes this site offers.
	 *
	 * @return list<string>
	 */
	public static function supported(): array {
		$scopes = array( self::READ, self::WRITE, self::ADMIN );
		if ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) {
			$scopes[] = self::CODE;
		}
		return $scopes;
	}

	/**
	 * Known scopes from a space-separated string, in canonical order (unknown ones and offline_access dropped).
	 *
	 * @param string $scope Scope string.
	 * @return list<string>
	 */
	public static function parse( string $scope ): array {
		$asked = preg_split( '/\s+/', trim( $scope ) );
		return array_values( array_intersect( self::supported(), false === $asked ? array() : $asked ) );
	}

	/**
	 * Whether granted scopes cover the required one.
	 *
	 * @param list<string> $granted  Granted scopes.
	 * @param string       $required Required scope.
	 */
	public static function allows( array $granted, string $required ): bool {
		if ( in_array( $required, $granted, true ) ) {
			return true;
		}
		if ( ! isset( self::RANK[ $required ] ) ) {
			return false;
		}
		foreach ( $granted as $scope ) {
			if ( ( self::RANK[ $scope ] ?? 0 ) >= self::RANK[ $required ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Requested scopes this user's capabilities justify.
	 *
	 * @param list<string> $requested Requested scopes.
	 * @param \WP_User     $user      User.
	 * @return list<string>
	 */
	public static function for_user( array $requested, \WP_User $user ): array {
		$allowed = array_filter(
			$requested,
			static fn( string $scope ): bool => match ( $scope ) {
				self::READ  => user_can( $user, 'read' ),
				self::WRITE => user_can( $user, 'edit_posts' ),
				self::ADMIN => user_can( $user, 'manage_options' ),
				self::CODE  => user_can( $user, 'manage_options' ) && ( user_can( $user, 'unfiltered_html' ) || user_can( $user, 'edit_plugins' ) ),
				default     => false,
			}
		);
		return array_values( $allowed );
	}
}
