<?php
/**
 * Tools → KuDiagnostics: connections, activity and integrations (phase P, decision D84).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\Urls;
use Kuduy\Mcp\Policy\ReadOnlyMode;

/**
 * Every logged-in user can manage their own connections and see their own activity here; site managers see
 * everyone's. The Integrations tab is for users who can activate plugins.
 */
final class ConnectionsPage {

	public const SLUG = 'kuduy-mcp';

	/**
	 * Add the menu entry (admin_menu).
	 */
	public static function menu(): void {
		if ( HiddenMode::is_hidden() ) {
			return;
		}
		add_management_page( 'KuDiagnostics', 'KuDiagnostics', 'read', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Tabs the current user may open: slug => title.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		$tabs = array(
			'connections' => __( 'Connections', 'kuduy-mcp' ),
			'activity'    => __( 'Activity', 'kuduy-mcp' ),
		);
		if ( current_user_can( 'activate_plugins' ) ) {
			$tabs['integrations'] = __( 'Integrations', 'kuduy-mcp' );
		}
		return $tabs;
	}

	/**
	 * URL of a tab with extra query arguments.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function url( string $tab, array $args = array() ): string {
		// Values are encoded: a client id is a URL (found on the live site: it went into the link unencoded).
		return add_query_arg(
			array_map(
				'rawurlencode',
				array_map(
					'strval',
					array_merge(
						array(
							'page' => self::SLUG,
							'tab'  => $tab,
						),
						$args
					)
				)
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Render the page (and process a revoke).
	 */
	public static function render(): void {
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab choice.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connections';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'connections';

		echo '<div class="wrap"><h1>KuDiagnostics</h1>';
		if ( ReadOnlyMode::is_on() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Read-only mode is on: Claude can read this site but not change it.', 'kuduy-mcp' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $title ) {
			echo '<a href="' . esc_url( self::url( $slug ) ) . '" class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $title ) . '</a>';
		}
		echo '</nav>';
		if ( 'activity' === $tab ) {
			ActivityTab::render();
		} elseif ( 'integrations' === $tab ) {
			IntegrationsTab::render();
		} else {
			self::connections();
		}
		echo '</div>';
	}

	/**
	 * The Connections tab.
	 */
	private static function connections(): void {
		$user    = wp_get_current_user();
		$is_boss = current_user_can( 'manage_options' );
		if ( isset( $_POST['kuduy_revoke'] ) ) {
			// Dies on a bad nonce.
			check_admin_referer( 'kuduy-mcp-revoke' );
			$done = Connections::revoke( sanitize_key( wp_unslash( $_POST['kuduy_revoke'] ) ), $user );
			echo '<div class="notice notice-info"><p>' . esc_html( $done ? __( 'Connection revoked. The app must sign in again.', 'kuduy-mcp' ) : __( 'That connection was not found or is not yours.', 'kuduy-mcp' ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Connect Claude', 'kuduy-mcp' ) . '</h2>'
			. '<p>' . esc_html__( 'In Claude, add a custom connector and paste this URL. You will be asked to log in here and approve access.', 'kuduy-mcp' ) . '</p>'
			. '<p><code>' . esc_html( Urls::resource() ) . '</code></p>';

		echo '<h2>' . esc_html__( 'Active connections', 'kuduy-mcp' ) . '</h2>';
		$connections = Connections::for_user( $user );
		if ( ! $connections ) {
			echo '<p>' . esc_html__( 'No active connections.', 'kuduy-mcp' ) . '</p>';
		} else {
			echo '<table class="widefat striped kuduy-connections"><thead><tr><th>' . esc_html__( 'App', 'kuduy-mcp' ) . '</th>'
				. ( $is_boss ? '<th>' . esc_html__( 'User', 'kuduy-mcp' ) . '</th>' : '' )
				. '<th>' . esc_html__( 'Access', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Last used', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Signed in until', 'kuduy-mcp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $connections as $c ) {
				$owner  = get_userdata( $c['user_id'] );
				$usage  = AuditLog::usage( (int) $c['user_id'], (string) $c['client_id'] );
				$access = array_map( static fn( string $s ): string => '<span title="' . esc_attr( Scopes::explain( $s ) ) . '">' . esc_html( Scopes::label( $s ) ) . '</span>', Scopes::parse( (string) $c['scope'] ) );
				$args   = array( 'client' => (string) $c['client_id'] ) + ( $is_boss ? array( 'user' => (int) $c['user_id'] ) : array() );
				echo '<tr><td>' . esc_html( $c['client_name'] ) . '</td>'
					. ( $is_boss ? '<td>' . esc_html( $owner ? $owner->user_login : '#' . $c['user_id'] ) . '</td>' : '' )
					. '<td>' . implode( '<br>', $access ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part escaped above.
					. '<td>' . ( null === $usage['last'] ? esc_html__( 'Not yet', 'kuduy-mcp' ) : esc_html( get_date_from_gmt( $usage['last'] ) ) )
					/* translators: %d: number of tool calls in the last 7 days. */
					. '<br><a href="' . esc_url( self::url( 'activity', $args ) ) . '">' . esc_html( sprintf( _n( '%d call in 7 days', '%d calls in 7 days', $usage['week'], 'kuduy-mcp' ), $usage['week'] ) ) . '</a></td>'
					. '<td>' . esc_html( get_date_from_gmt( (string) $c['expires_at'] ) ) . '</td><td><form method="post">';
				wp_nonce_field( 'kuduy-mcp-revoke' );
				echo '<button class="button" name="kuduy_revoke" value="' . esc_attr( (string) $c['family_id'] ) . '">' . esc_html__( 'Revoke', 'kuduy-mcp' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}//end if

		echo '<h2>' . esc_html__( 'What each access level allows', 'kuduy-mcp' ) . '</h2><dl class="kuduy-scopes">';
		foreach ( Scopes::supported() as $scope ) {
			echo '<dt><strong>' . esc_html( Scopes::label( $scope ) ) . '</strong></dt><dd>' . esc_html( Scopes::explain( $scope ) ) . '</dd>';
		}
		echo '</dl>';

		ReadOnlyMode::render_section();
		HiddenMode::render_section();
	}
}
