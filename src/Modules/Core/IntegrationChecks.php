<?php
/**
 * Tools: kuduy-integration-dependencies, kuduy-integration-conflicts.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;

/**
 * Cross-plugin checks (phase O, D78), read only. Dependencies: every installed plugin's "Requires Plugins" header
 * (WordPress 6.5+) and the catalogue's own dependencies (e.g. Elementor Pro needs Elementor) — which are missing or
 * inactive while the plugin needing them is active. Conflicts: more than one active plugin from a catalogue category
 * where one is expected (SEO, page cache, translation), which typically doubles meta tags, caches or language
 * switches.
 */
final class IntegrationChecks implements Tool {

	/**
	 * Categories where more than one active plugin usually conflicts, with why.
	 */
	private const SINGLE = array(
		'seo'         => 'Two SEO plugins print two sets of titles, descriptions, canonical tags and sitemaps.',
		'cache'       => 'Two page cache plugins cache over each other and can serve stale or broken pages.',
		'translation' => 'Two translation plugins manage languages differently and break each other\'s links.',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode dependencies | conflicts.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-integration-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'dependencies' === $this->mode ? __( 'Check plugin dependencies', 'kuduy-mcp' ) : __( 'Check plugin conflicts', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'dependencies' === $this->mode
			? 'Lists what installed plugins need: from their "Requires Plugins" header and from what KuDiagnostics knows (e.g. Elementor Pro needs Elementor, WooCommerce add-ons need WooCommerce), with each requirement\'s state (active, inactive, not installed) and the problems first — an active plugin whose requirement is missing or inactive. Reads only.'
			: 'Finds active plugins that usually conflict: more than one SEO plugin, page cache plugin or translation plugin active at once, with why it matters. Reads only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'problems'     => $list,
				'requirements' => $list,
				'conflicts'    => $list,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'activate_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		Packages::load_admin();
		$plugins = Integrations::plugin_folders();
		$rows    = IntegrationCatalog::all();
		$state   = array();
		foreach ( $rows as $row ) {
			$state[ $row['id'] ] = array( $row ) + array( 1 => Integrations::detect( $row, $plugins ) );
		}
		return 'dependencies' === $this->mode ? self::dependencies( $plugins, $state ) : self::conflicts( $state );
	}

	/**
	 * Requirements and their problems.
	 *
	 * @param array<string, array{0: string, 1: string}>                                                              $plugins Folder => [file, version].
	 * @param array<string, array{0: array<string, mixed>, 1: array{installed: bool, active: bool, version: string}}> $state   Catalogue id => [row, detection].
	 * @return array<string, mixed>
	 */
	private static function dependencies( array $plugins, array $state ): array {
		$requirements = array();
		$status       = static function ( string $slug ) use ( $plugins ): string {
			if ( ! isset( $plugins[ $slug ] ) ) {
				return 'not installed';
			}
			return is_plugin_active( $plugins[ $slug ][0] ) ? 'active' : 'inactive';
		};
		foreach ( get_plugins() as $file => $data ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) ) ) as $slug ) {
				$requirements[] = array(
					'plugin'        => (string) ( $data['Name'] ?? $file ),
					'plugin_active' => is_plugin_active( (string) $file ),
					'needs'         => $slug,
					'needs_state'   => $status( $slug ),
					'source'        => 'Requires Plugins header',
				);
			}
		}
		foreach ( $state as $id => [ $row, $found ] ) {
			if ( ! $found['installed'] ) {
				continue;
			}
			foreach ( IntegrationCatalog::requires( (string) $id ) as $need ) {
				$needed         = $state[ $need ] ?? null;
				$requirements[] = array(
					'plugin'        => (string) $row['name'],
					'plugin_active' => $found['active'],
					'needs'         => null === $needed ? $need : (string) $needed[0]['name'],
					'needs_state'   => null === $needed ? 'unknown' : ( $needed[1]['active'] ? 'active' : ( $needed[1]['installed'] ? 'inactive' : 'not installed' ) ),
					'source'        => 'KuDiagnostics catalogue',
				);
			}
		}
		$problems = array_values( array_filter( $requirements, static fn( array $r ): bool => $r['plugin_active'] && 'active' !== $r['needs_state'] ) );
		return array(
			'problems'     => $problems,
			'requirements' => $requirements,
		);
	}

	/**
	 * Categories with more than one active plugin.
	 *
	 * @param array<string, array{0: array<string, mixed>, 1: array{installed: bool, active: bool, version: string}}> $state Catalogue id => [row, detection].
	 * @return array<string, mixed>
	 */
	private static function conflicts( array $state ): array {
		$active = array();
		foreach ( $state as [ $row, $found ] ) {
			if ( $found['active'] && 'plugin' === $row['kind'] && isset( self::SINGLE[ (string) $row['category'] ] ) ) {
				$active[ (string) $row['category'] ][] = (string) $row['name'];
			}
		}
		$out = array();
		foreach ( $active as $category => $names ) {
			// A Pro add-on and its base plugin are one product, not a conflict (e.g. Rank Math and Rank Math Pro).
			$names = array_values( array_unique( $names ) );
			$base  = array_values( array_filter( $names, static fn( string $n ): bool => ! preg_match( '/\b(Pro|Premium)\b/', $n ) ) );
			if ( count( $base ) > 1 ) {
				$out[] = array(
					'category' => $category,
					'active'   => $names,
					'why'      => self::SINGLE[ $category ],
				);
			}
		}
		return array( 'conflicts' => $out );
	}
}
