<?php
/**
 * KuDiagnostics screen, Activity tab (phase P, decision D84).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\OAuth\Scopes;

/**
 * The audit log as a filtered list: by connection, result and high-risk tools. Users see their own calls; site
 * managers everyone's (and may filter by user). Arguments are not shown here (they stay redacted in the table).
 */
final class ActivityTab {

	private const PER_PAGE = 50;

	private const RESULTS = array( 'ok', 'error', 'denied' );

	/**
	 * Tools that count as high-risk: confirm-first, destructive, or changing things with Full Site Administration or code
	 * access (found on the live site: read-only admin tools such as file reads were flagged).
	 *
	 * @return list<string>
	 */
	public static function high_risk(): array {
		$out = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			$hints = $tool->annotations();
			// Reading files or settings with administration access is not risky; changing things with it is.
			$admin = in_array( $tool->scope(), array( Scopes::ADMIN, Scopes::CODE ), true ) && true !== ( $hints['readOnlyHint'] ?? false );
			if ( $tool instanceof Confirmable || true === ( $hints['destructiveHint'] ?? false ) || $admin ) {
				$out[] = $tool->name();
			}
		}
		return $out;
	}

	/**
	 * Filters from the request, limited to what the viewer may see.
	 *
	 * @return array{user_id: int|null, client_id: string, result: string, risk: bool, page: int}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$boss   = current_user_can( 'manage_options' );
		$user   = $boss ? ( isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0 ) : get_current_user_id();
		$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
		$out    = array(
			'user_id'   => $user > 0 ? $user : null,
			'client_id' => isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : '',
			'result'    => in_array( $result, self::RESULTS, true ) ? $result : '',
			'risk'      => ! empty( $_GET['risk'] ),
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * Render the tab.
	 */
	public static function render(): void {
		$boss    = current_user_can( 'manage_options' );
		$filters = self::filters();
		$risky   = self::high_risk();
		$found   = AuditLog::search(
			array(
				'user_id'   => $filters['user_id'],
				'client_id' => $filters['client_id'],
				'result'    => $filters['result'],
				'tools'     => $filters['risk'] ? $risky : null,
			),
			self::PER_PAGE,
			( $filters['page'] - 1 ) * self::PER_PAGE
		);

		echo '<h2>' . esc_html__( 'Tool calls', 'kuduy-mcp' ) . '</h2>'
			. '<p>' . esc_html( $boss ? __( 'Every call Claude made on this site, newest first. Arguments are stored with secrets removed and are not shown here.', 'kuduy-mcp' ) : __( 'Calls made with your account, newest first.', 'kuduy-mcp' ) ) . '</p>';

		echo '<form method="get" class="kuduy-activity-filters"><input type="hidden" name="page" value="' . esc_attr( ConnectionsPage::SLUG ) . '"><input type="hidden" name="tab" value="activity">';
		if ( '' !== $filters['client_id'] ) {
			echo '<input type="hidden" name="client" value="' . esc_attr( $filters['client_id'] ) . '">';
		}
		if ( $boss && null !== $filters['user_id'] ) {
			echo '<input type="hidden" name="user" value="' . esc_attr( (string) $filters['user_id'] ) . '">';
		}
		echo '<label>' . esc_html__( 'Result', 'kuduy-mcp' ) . ' <select name="result"><option value="">' . esc_html__( 'Any', 'kuduy-mcp' ) . '</option>';
		foreach ( self::RESULTS as $result ) {
			echo '<option value="' . esc_attr( $result ) . '"' . selected( $filters['result'], $result, false ) . '>' . esc_html( $result ) . '</option>';
		}
		echo '</select></label> <label><input type="checkbox" name="risk" value="1"' . checked( $filters['risk'], true, false ) . '> ' . esc_html__( 'High-risk only (confirm-first, destructive, administration or code)', 'kuduy-mcp' ) . '</label> '
			. '<button class="button">' . esc_html__( 'Filter', 'kuduy-mcp' ) . '</button>';
		if ( '' !== $filters['client_id'] || ( $boss && null !== $filters['user_id'] ) ) {
			echo ' <a href="' . esc_url( ConnectionsPage::url( 'activity' ) ) . '">' . esc_html__( 'Show all connections', 'kuduy-mcp' ) . '</a>';
		}
		echo '</form>';

		if ( ! $found['rows'] ) {
			echo '<p>' . esc_html__( 'No tool calls match.', 'kuduy-mcp' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped kuduy-activity"><thead><tr><th>' . esc_html__( 'Time', 'kuduy-mcp' ) . '</th>'
			. ( $boss ? '<th>' . esc_html__( 'User', 'kuduy-mcp' ) . '</th>' : '' )
			. '<th>' . esc_html__( 'App', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Tool', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Changed', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Result', 'kuduy-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $found['rows'] as $row ) {
			$who    = get_userdata( (int) $row['user_id'] );
			$tool   = (string) $row['tool'];
			$target = '' !== (string) $row['object_type'] ? $row['object_type'] . ' ' . $row['object_id'] : '';
			$undo   = null !== $row['snapshot_id'] ? ' · ' . sprintf( /* translators: %d: snapshot id. */ __( 'undo snapshot %d', 'kuduy-mcp' ), (int) $row['snapshot_id'] ) : '';
			echo '<tr><td>' . esc_html( get_date_from_gmt( (string) $row['ts'] ) ) . '</td>'
				. ( $boss ? '<td>' . esc_html( $who ? $who->user_login : '—' ) . '</td>' : '' )
				. '<td>' . esc_html( self::app( (string) $row['client_id'] ) ) . '</td>'
				. '<td><code>' . esc_html( $tool ) . '</code>' . ( in_array( $tool, $risky, true ) ? ' <span class="kuduy-risk">' . esc_html__( 'high-risk', 'kuduy-mcp' ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( $target . $undo ) . '</td>'
				. '<td>' . esc_html( $row['result'] . ( $row['error_code'] ? ' (' . $row['error_code'] . ')' : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $found['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$args = array_filter(
				array(
					'client' => $filters['client_id'],
					'user'   => $boss ? (int) $filters['user_id'] : 0,
					'result' => $filters['result'],
					'risk'   => $filters['risk'] ? 1 : 0,
				)
			);
			/* translators: 1: page number, 2: number of pages, 3: number of calls. */
			echo '<p>' . esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d calls).', 'kuduy-mcp' ), $filters['page'], $pages, $found['total'] ) );
			if ( $filters['page'] > 1 ) {
				echo ' <a href="' . esc_url( ConnectionsPage::url( 'activity', $args + array( 'paged' => $filters['page'] - 1 ) ) ) . '">' . esc_html__( 'Newer', 'kuduy-mcp' ) . '</a>';
			}
			if ( $filters['page'] < $pages ) {
				echo ' <a href="' . esc_url( ConnectionsPage::url( 'activity', $args + array( 'paged' => $filters['page'] + 1 ) ) ) . '">' . esc_html__( 'Older', 'kuduy-mcp' ) . '</a>';
			}
			echo '</p>';
		}
	}

	/**
	 * A client id for people: the name the application registered with (found on the live site: dynamically
	 * registered apps showed as kmcp_c_… ids), else the host of a URL id (CIMD), else the id or how the user signed in.
	 *
	 * @param string $client_id Client id.
	 */
	private static function app( string $client_id ): string {
		static $names = array();
		if ( ! array_key_exists( $client_id, $names ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- KuDiagnostics' own table; one lookup per app and page.
			$name                = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}kuduy_oauth_clients WHERE client_id = %s", $client_id ) );
			$names[ $client_id ] = is_string( $name ) ? trim( $name ) : '';
		}
		if ( '' !== $names[ $client_id ] ) {
			return $names[ $client_id ];
		}
		$host = str_starts_with( $client_id, 'https://' ) ? wp_parse_url( $client_id, PHP_URL_HOST ) : null;
		return is_string( $host ) ? $host : $client_id;
	}
}
