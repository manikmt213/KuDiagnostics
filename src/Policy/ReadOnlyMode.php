<?php
/**
 * Site-wide read-only switch (phase J, decision D80).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Policy;

use Kuduy\Mcp\Mcp\Tool;

/**
 * While on, only tools annotated readOnlyHint run, for every connection and user. Switched by a site administrator on
 * the KuDiagnostics screen (option) or in wp-config.php (`define( 'KUDUY_MCP_READ_ONLY', true|false );`, which wins
 * over the option either way). There is no MCP tool to change it.
 */
final class ReadOnlyMode {

	public const OPTION   = 'kuduy_mcp_read_only';
	public const CONSTANT = 'KUDUY_MCP_READ_ONLY';
	public const ACTION   = 'kuduy_mcp_read_only';

	/**
	 * Whether the constant decides (and the screen cannot change it).
	 */
	public static function forced(): bool {
		return defined( self::CONSTANT ) && is_bool( constant( self::CONSTANT ) );
	}

	/**
	 * Whether read-only mode is on.
	 */
	public static function is_on(): bool {
		return self::forced() ? (bool) constant( self::CONSTANT ) : '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Whether a tool may run now.
	 *
	 * @param Tool $tool Tool.
	 */
	public static function allows( Tool $tool ): bool {
		return ! self::is_on() || true === ( $tool->annotations()['readOnlyHint'] ?? false );
	}

	/**
	 * Hook the admin side.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The switch's handler (admin-post): site administrators only, nonce checked.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site administrators can change read-only mode.', 'kuduy-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$on = isset( $_POST['kuduy_read_only'] ) && '1' === sanitize_key( wp_unslash( $_POST['kuduy_read_only'] ) );
		update_option( self::OPTION, $on ? '1' : '0', true );
		wp_safe_redirect( admin_url( 'tools.php?page=kuduy-mcp' ) );
		exit;
	}

	/**
	 * The read-only section of the Tools page (administrators only).
	 */
	public static function render_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$on = self::is_on();
		echo '<h2>' . esc_html__( 'Read-only mode', 'kuduy-mcp' ) . '</h2>'
			. '<p>' . esc_html__( 'While on, Claude can only read: every tool that would change the site is hidden and refused, for all connections and users, including background jobs.', 'kuduy-mcp' ) . '</p>'
			. '<p><strong>' . ( $on ? esc_html__( 'Read-only mode is on.', 'kuduy-mcp' ) : esc_html__( 'Read-only mode is off.', 'kuduy-mcp' ) ) . '</strong></p>';
		if ( self::forced() ) {
			echo '<p><em>' . esc_html__( 'KUDUY_MCP_READ_ONLY is set in wp-config.php, so it cannot be changed here.', 'kuduy-mcp' ) . '</em></p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="kuduy_read_only" value="' . ( $on ? '0' : '1' ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<button class="button">' . ( $on ? esc_html__( 'Turn read-only mode off', 'kuduy-mcp' ) : esc_html__( 'Turn read-only mode on', 'kuduy-mcp' ) ) . '</button></form>';
	}
}
