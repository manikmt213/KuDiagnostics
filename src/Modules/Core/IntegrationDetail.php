<?php
/**
 * Tools: kuduy-integration-get, kuduy-integration-diagnose.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;

/**
 * Detail behind kuduy-integrations (decision D44): `get` returns one integration with the exact tools behind it and
 * whether each can be called now; `diagnose` reports what stands in the way of using the integrations on this site.
 * Both read the catalogue and the live state on every call; nothing is cached or changed.
 */
final class IntegrationDetail implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode get | diagnose.
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
		return 'get' === $this->mode ? __( 'One integration in detail', 'kuduy-mcp' ) : __( 'Diagnose integrations', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'get' === $this->mode
			? 'Returns one builder, theme or plugin from kuduy-integrations in detail: installed, active, version, how far it is supported, whether a free copy exists, what it needs (e.g. an add-on needs Elementor) and the state of those, and every tool that manages it — name, title, read-only or changing, destructive, needed access level, and whether it can be called right now (or why not). include_schemas: true adds each tool\'s input schema. Read only.'
			: 'Checks the integrations installed on this site (or one, with integration) and lists what stands in the way of using them: installed but not active, a required plugin or theme missing or inactive, tools that cannot run and why, products whose support was not verified against the real product, and products that are only recognised. Each finding has a level (error, warning, info). Read only; nothing is changed.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'type'        => 'string',
			'maxLength'   => 60,
			'pattern'     => '^[a-z0-9-]+$',
			'description' => 'Integration id from kuduy-integrations, e.g. "elementor" or "woocommerce".',
		);
		if ( 'diagnose' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array( 'integration' => $id ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'integration'     => $id,
				'include_schemas' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'integration' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'diagnose' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'checked'  => array( 'type' => 'integer' ),
					'findings' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'integration' => array( 'type' => 'string' ),
								'level'       => array(
									'type' => 'string',
									'enum' => array( 'error', 'warning', 'info' ),
								),
								'message'     => array( 'type' => 'string' ),
							),
							'required'   => array( 'integration', 'level', 'message' ),
						),
					),
				),
				'required'   => array( 'checked', 'findings' ),
			);
		}//end if
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'string' ),
				'name'      => array( 'type' => 'string' ),
				'category'  => array( 'type' => 'string' ),
				'installed' => array( 'type' => 'boolean' ),
				'active'    => array( 'type' => 'boolean' ),
				'version'   => array( 'type' => 'string' ),
				'support'   => array(
					'type' => 'string',
					'enum' => IntegrationCatalog::SUPPORT,
				),
				'copy'      => array( 'type' => 'string' ),
				'note'      => array( 'type' => 'string' ),
				'requires'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'tools'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
			'required'   => array( 'id', 'name', 'category', 'installed', 'active', 'support', 'requires', 'tools' ),
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
		return 'get' === $this->mode ? 'edit_posts' : 'activate_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The tools behind a row.
	 *
	 * @param list<string> $prefixes Tool name prefixes.
	 * @return list<Tool>
	 */
	private static function tools_of( array $prefixes ): array {
		$out = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $tool->name(), $prefix ) ) {
					$out[] = $tool;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * State of the integrations a row needs.
	 *
	 * @param string                                     $id      Integration id.
	 * @param array<string, array{0: string, 1: string}> $plugins Installed plugins by folder.
	 * @return list<array{id: string, name: string, installed: bool, active: bool}>
	 */
	private static function parents( string $id, array $plugins ): array {
		$out = array();
		foreach ( IntegrationCatalog::requires( $id ) as $parent_id ) {
			$parent = IntegrationCatalog::find( $parent_id );
			if ( null === $parent ) {
				continue;
			}
			$state = Integrations::detect( $parent, $plugins );
			$out[] = array(
				'id'        => $parent_id,
				'name'      => $parent['name'],
				'installed' => $state['installed'],
				'active'    => $state['active'],
			);
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the integration id is not in the catalogue.
	 */
	public function execute( array $input ): array {
		$plugins = Integrations::plugin_folders();
		$only    = (string) ( $input['integration'] ?? '' );
		if ( '' !== $only && null === IntegrationCatalog::find( $only ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No integration with this id. See kuduy-integrations with all: true for the ids.' );
		}
		return 'get' === $this->mode ? self::get( $only, $plugins, ! empty( $input['include_schemas'] ) ) : self::diagnose( $only, $plugins );
	}

	/**
	 * One integration in detail.
	 *
	 * @param string                                     $id      Integration id.
	 * @param array<string, array{0: string, 1: string}> $plugins Installed plugins by folder.
	 * @param bool                                       $schemas Add input schemas.
	 * @return array<string, mixed>
	 */
	private static function get( string $id, array $plugins, bool $schemas ): array {
		$row   = (array) IntegrationCatalog::find( $id );
		$state = Integrations::detect( $row, $plugins );
		$out   = array(
			'id'        => $row['id'],
			'name'      => $row['name'],
			'category'  => $row['category'],
			'installed' => $state['installed'],
			'active'    => $state['active'],
			'support'   => $row['support'],
			'copy'      => $row['copy'],
			'requires'  => self::parents( $id, $plugins ),
			'tools'     => array(),
		);
		if ( current_user_can( 'activate_plugins' ) && '' !== $state['version'] ) {
			$out['version'] = $state['version'];
		}
		if ( '' !== $row['note'] ) {
			$out['note'] = $row['note'];
		}
		foreach ( self::tools_of( $row['prefixes'] ) as $tool ) {
			$available = $tool->is_available();
			$hints     = $tool->annotations();
			$item      = array(
				'name'        => $tool->name(),
				'title'       => $tool->title(),
				'read_only'   => ! empty( $hints['readOnlyHint'] ),
				'destructive' => ! empty( $hints['destructiveHint'] ),
				'scope'       => $tool->scope(),
				'available'   => true === $available,
			);
			if ( true !== $available ) {
				$item['unavailable_because'] = (string) $available;
			}
			if ( $schemas ) {
				$item['input_schema'] = $tool->input_schema();
			}
			$out['tools'][] = $item;
		}
		return $out;
	}

	/**
	 * What stands in the way.
	 *
	 * @param string                                     $only    One integration id, or '' for every installed one.
	 * @param array<string, array{0: string, 1: string}> $plugins Installed plugins by folder.
	 * @return array{checked: int, findings: list<array{integration: string, level: string, message: string}>}
	 */
	private static function diagnose( string $only, array $plugins ): array {
		$findings = array();
		$checked  = 0;
		$add      = static function ( string $id, string $level, string $message ) use ( &$findings ): void {
			$findings[] = array(
				'integration' => $id,
				'level'       => $level,
				'message'     => $message,
			);
		};
		foreach ( IntegrationCatalog::all() as $row ) {
			$id    = $row['id'];
			$state = Integrations::detect( $row, $plugins );
			if ( '' === $only ? ! $state['installed'] : $only !== $id ) {
				continue;
			}
			++$checked;
			if ( ! $state['installed'] ) {
				$add( $id, 'info', $row['name'] . ' is not installed on this site.' );
				continue;
			}
			if ( ! $state['active'] ) {
				$add( $id, 'warning', $row['name'] . ' is installed but not active, so its tools are not offered.' );
				continue;
			}
			foreach ( self::parents( $id, $plugins ) as $parent ) {
				if ( ! $parent['active'] ) {
					$add( $id, 'error', $row['name'] . ' needs ' . $parent['name'] . ', which is ' . ( $parent['installed'] ? 'installed but not active.' : 'not installed.' ) );
				}
			}
			$reasons = array();
			foreach ( self::tools_of( $row['prefixes'] ) as $tool ) {
				$available = $tool->is_available();
				if ( true !== $available ) {
					$reasons[ (string) $available ][] = $tool->name();
				}
			}
			foreach ( $reasons as $reason => $names ) {
				$add( $id, 'warning', count( $names ) . ' tool(s) cannot run (' . implode( ', ', array_slice( $names, 0, 5 ) ) . ( count( $names ) > 5 ? ', …' : '' ) . '): ' . $reason );
			}
			if ( 'detection' === $row['support'] || 'none' === $row['support'] ) {
				$add( $id, 'info', $row['name'] . ' is recognised but KuDiagnostics has no tools for it yet.' . ( '' !== $row['note'] ? ' ' . $row['note'] : '' ) );
			} elseif ( 'partial' === $row['support'] ) {
				$add( $id, 'info', $row['name'] . ' is only partly supported. ' . $row['note'] );
			}
		}//end foreach
		return array(
			'checked'  => $checked,
			'findings' => $findings,
		);
	}
}
