<?php
/**
 * KuDiagnostics screen, Integrations tab (phase P, decision D84).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Admin;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Modules\Core\IntegrationCatalog;
use Kuduy\Mcp\Modules\Core\Integrations;

/**
 * The same data Claude gets from kuduy-integrations: what is installed here, how far KuDiagnostics supports it and
 * whether its tools are available now. For users who can activate plugins.
 */
final class IntegrationsTab {

	/**
	 * Tools that check integrations; the last call of any of them is shown.
	 */
	private const CHECKS = array( 'kuduy-integrations', 'kuduy-integration-get', 'kuduy-integration-diagnose', 'kuduy-integration-dependencies', 'kuduy-integration-conflicts' );

	/**
	 * Render the tab.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list switch.
		$all  = ! empty( $_GET['all'] );
		$data = ( new Integrations() )->execute( array( 'all' => $all ) );
		$sum  = $data['summary'];

		echo '<h2>' . esc_html__( 'Integrations', 'kuduy-mcp' ) . '</h2>'
			/* translators: 1: installed integrations, 2: active ones, 3: integrations KuDiagnostics knows. */
			. '<p>' . esc_html( sprintf( __( '%1$d of the %3$d builders, themes and plugins KuDiagnostics knows are installed here, %2$d active.', 'kuduy-mcp' ), $sum['installed'], $sum['active'], $sum['catalogue'] ) ) . ' '
			. esc_html__( 'Support: full = tested against the real product; partial = some operations, or tested against a stand-in; detection = recognised only.', 'kuduy-mcp' ) . '</p>';
		$last = AuditLog::search( array( 'tools' => self::CHECKS ), 1, 0 )['rows'][0] ?? null;
		/* translators: 1: date and time, 2: tool name. */
		echo '<p>' . esc_html( null === $last ? __( 'Claude has not checked integrations on this site yet.', 'kuduy-mcp' ) : sprintf( __( 'Last check by Claude: %1$s (%2$s).', 'kuduy-mcp' ), get_date_from_gmt( (string) $last['ts'] ), (string) $last['tool'] ) ) . '</p>';
		echo '<p><a href="' . esc_url( ConnectionsPage::url( 'integrations', $all ? array() : array( 'all' => 1 ) ) ) . '">' . esc_html( $all ? __( 'Show only what is installed here', 'kuduy-mcp' ) : __( 'Show the whole catalogue', 'kuduy-mcp' ) ) . '</a></p>';

		if ( ! $data['integrations'] ) {
			echo '<p>' . esc_html__( 'None of the builders, themes and plugins in the catalogue is installed here.', 'kuduy-mcp' ) . '</p>';
			return;
		}
		$rows = $data['integrations'];
		$rank = array_flip( IntegrationCatalog::CATEGORIES );
		usort( $rows, static fn( $a, $b ) => array( $rank[ $a['category'] ] ?? 99, $a['name'] ) <=> array( $rank[ $b['category'] ] ?? 99, $b['name'] ) );
		echo '<table class="widefat striped kuduy-integrations"><thead><tr><th>' . esc_html__( 'Product', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Kind', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Here', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Support', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Tools', 'kuduy-mcp' ) . '</th><th>' . esc_html__( 'Notes', 'kuduy-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$here  = $row['active'] ? __( 'active', 'kuduy-mcp' ) : ( $row['installed'] ? __( 'installed, inactive', 'kuduy-mcp' ) : __( 'not installed', 'kuduy-mcp' ) );
			$tools = $row['tools'] ?? null;
			echo '<tr data-id="' . esc_attr( $row['id'] ) . '"><td><strong>' . esc_html( $row['name'] ) . '</strong></td>'
				. '<td>' . esc_html( $row['category'] ) . '</td>'
				. '<td>' . esc_html( $here . ( isset( $row['version'] ) ? ' ' . $row['version'] : '' ) ) . '</td>'
				. '<td>' . esc_html( $row['support'] ) . '</td>'
				/* translators: %d: number of tools. */
				. '<td>' . ( null === $tools ? '—' : esc_html( sprintf( _n( '%d tool', '%d tools', $tools['count'], 'kuduy-mcp' ), $tools['count'] ) . ( $tools['available'] ? '' : ' (' . __( 'not available now', 'kuduy-mcp' ) . ')' ) ) ) . '</td>'
				. '<td>' . esc_html( $row['note'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
