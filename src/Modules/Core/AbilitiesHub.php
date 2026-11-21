<?php
/**
 * Tools: kuduy-abilities and kuduy-ability-read (phase J, D82 / D34).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Abilities other plugins register with the WordPress Abilities API (6.9+) and mark for MCP (`meta.mcp.public`).
 * Every call goes through `WP_Ability::execute()`, which validates the input and runs the ability's own permission
 * callback as the connected user. Namespaces with their own KuDiagnostics tools are left to those tools (they add
 * snapshots and refusals the hub cannot), and abilities that run code or send reports out are never run.
 */
final class AbilitiesHub implements Tool {

	/**
	 * Ability namespaces handled by dedicated KuDiagnostics tools => product name.
	 */
	public const COVERED = array(
		'aioseo'         => 'All in One SEO',
		'astra'          => 'Astra',
		'breakdance'     => 'Breakdance',
		'bricks'         => 'Bricks',
		'elementor'      => 'Elementor',
		'kuduy'          => 'KuDiagnostics',
		'masteriyo'      => 'Masteriyo',
		'meta-box'       => 'Meta Box',
		'oxygen'         => 'Oxygen',
		'rank-math'      => 'Rank Math',
		'seopress'       => 'SEOPress',
		'spectra-blocks' => 'Spectra Blocks',
		'woocommerce'    => 'WooCommerce',
		'wp-rocket'      => 'WP Rocket',
		'wpcode'         => 'WPCode',
	);

	/**
	 * Parts of an ability's own name that mean it runs code or sends data out: never run here.
	 */
	private const REFUSED = '/(^|[-_])(php|eval|sql|shell|exec|execute|code|cli|report-bug|send-report)([-_]|$)/';

	/**
	 * Largest result returned as is, in bytes of JSON.
	 */
	private const MAX_RESULT = 100000;

	/**
	 * Mode.
	 *
	 * @param string $mode list | read.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-abilities' : 'kuduy-ability-read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'list' === $this->mode ? __( 'List public abilities', 'kuduy-mcp' ) : __( 'Run a read-only ability', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'list' === $this->mode
			? 'Lists abilities that other plugins on this site register with the WordPress Abilities API and mark public for MCP: name, label, description, category, read or write, and which tool runs it. Give name for one ability with its input and output schema. Abilities of plugins KuDiagnostics has its own tools for are listed under covered (use those tools: they keep undo snapshots). Use this for plugins KuDiagnostics has no tools for.'
			: 'Runs a public ability that its plugin marks read-only (annotations.readonly), with the input its schema declares (see kuduy-abilities with name). The plugin validates the input and checks its own permissions as you. Changes nothing; write abilities go through kuduy-ability-run.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name = array(
			'type'        => 'string',
			'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+(/[a-z0-9-]+)*$',
			'maxLength'   => 200,
			'description' => 'Ability name, e.g. "my-plugin/get-items".',
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'name'     => $name,
					'category' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'search'   => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => 'Matches name, label or description.',
					),
				),
				'additionalProperties' => false,
			);
		}
		return self::run_input( $name );
	}

	/**
	 * Input schema of the read and run tools.
	 *
	 * @param array<string, mixed> $name   Schema of the name argument.
	 * @param array<string, mixed> $extra  Extra properties.
	 * @return array<string, mixed>
	 */
	public static function run_input( array $name, array $extra = array() ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'  => $name,
				'input' => array(
					'type'        => SnapshotDiff::ANY,
					'description' => 'Input the ability declares (see kuduy-abilities with name). Leave out for abilities without input.',
				),
			) + $extra,
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'   => true,
			'idempotentHint' => 'list' === $this->mode,
			'openWorldHint'  => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * Any logged-in user; each ability checks its own permissions.
	 *
	 * @param array<string, mixed> $input Unused.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return self::supported();
	}

	/**
	 * True, or why the Abilities API is missing.
	 */
	public static function supported(): bool|string {
		return function_exists( 'wp_get_abilities' ) ? true : 'The WordPress Abilities API (WordPress 6.9+) is not available.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the ability is unknown, not public, covered, refused or refuses.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			return self::listing( $input );
		}
		$ability = self::usable( (string) $input['name'] );
		if ( ! self::reads( $ability ) ) {
			throw new ToolError( 'This ability is not marked read-only by its plugin: use kuduy-ability-run (it asks for confirmation first).', ToolError::INVALID_ARGUMENT );
		}
		return self::run( $ability, $input );
	}

	/**
	 * Public abilities by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	public static function public_abilities(): array {
		$out = array();
		foreach ( wp_get_abilities() as $ability ) {
			$mcp = $ability->get_meta_item( 'mcp', array() );
			if ( is_array( $mcp ) && true === ( $mcp['public'] ?? false ) ) {
				$out[ $ability->get_name() ] = $ability;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The product whose KuDiagnostics tools cover an ability, or ''.
	 *
	 * @param string $name Ability name.
	 */
	public static function covered_by( string $name ): string {
		$namespace = (string) strstr( $name, '/', true );
		foreach ( self::COVERED as $prefix => $product ) {
			if ( $namespace === $prefix || ( 'aioseo' === $prefix && str_starts_with( $namespace, 'aioseo' ) ) ) {
				return $product;
			}
		}
		return '';
	}

	/**
	 * Why an ability is never run here, or ''.
	 *
	 * @param string $name Ability name.
	 */
	public static function refused( string $name ): string {
		$product = self::covered_by( $name );
		if ( '' !== $product ) {
			return 'KuDiagnostics has its own tools for ' . $product . ' (see kuduy-integrations); they keep undo snapshots and safety checks, so its abilities are not run through the hub.';
		}
		$short = (string) substr( $name, (int) strpos( $name, '/' ) + 1 );
		if ( 1 === preg_match( self::REFUSED, $short ) ) {
			return 'Its name says it runs code or sends a report out. KuDiagnostics only runs code through its own guarded developer tools and does not send site data to outside services.';
		}
		return '';
	}

	/**
	 * Whether the ability's plugin marks it read-only. Only an explicit annotation counts here.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public static function reads( \WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations', array() );
		return is_array( $annotations ) && true === ( $annotations['readonly'] ?? $annotations['readOnlyHint'] ?? null );
	}

	/**
	 * A public ability that may be run here.
	 *
	 * @param string $name Ability name.
	 * @throws ToolError When it is unknown, not public or refused.
	 */
	public static function usable( string $name ): \WP_Ability {
		$ability = self::public_abilities()[ $name ] ?? null;
		if ( null === $ability ) {
			throw new ToolError( 'There is no public ability "' . $name . '" on this site. See kuduy-abilities.', ToolError::RESOURCE_NOT_FOUND );
		}
		$why = self::refused( $name );
		if ( '' !== $why ) {
			throw new ToolError( $why . ' Nothing was run.', ToolError::FEATURE_NOT_SUPPORTED );
		}
		return $ability;
	}

	/**
	 * Run an ability through core (input validation, its own permission callback, output validation).
	 *
	 * @param \WP_Ability          $ability Ability.
	 * @param array<string, mixed> $input   Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the ability refuses or fails.
	 */
	public static function run( \WP_Ability $ability, array $input ): array {
		$result = $ability->execute( $input['input'] ?? null );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'The ability refused: ' . wp_strip_all_tags( $result->get_error_message() ), 'ability_invalid_permissions' === $result->get_error_code() ? ToolError::PERMISSION_DENIED : ToolError::OPERATION_FAILED );
		}
		$json = (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > self::MAX_RESULT ) {
			return array(
				'ability'   => $ability->get_name(),
				'result'    => mb_substr( $json, 0, self::MAX_RESULT ),
				'truncated' => true,
				'note'      => 'The result was ' . strlen( $json ) . ' bytes of JSON; the first ' . self::MAX_RESULT . ' are returned as text. Ask the ability for less (filters, paging) if it allows.',
			);
		}
		return array(
			'ability' => $ability->get_name(),
			'result'  => $result,
		);
	}

	/**
	 * The list, or one ability with its schemas.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the named ability is unknown.
	 */
	private static function listing( array $input ): array {
		$all  = self::public_abilities();
		$name = (string) ( $input['name'] ?? '' );
		if ( '' !== $name ) {
			$ability = $all[ $name ] ?? null;
			if ( null === $ability ) {
				throw new ToolError( 'There is no public ability "' . $name . '" on this site.', ToolError::RESOURCE_NOT_FOUND );
			}
			return array(
				'ability' => self::row( $ability ) + array(
					'input_schema'  => $ability->get_input_schema(),
					'output_schema' => $ability->get_output_schema(),
				),
			);
		}
		$category  = (string) ( $input['category'] ?? '' );
		$search    = mb_strtolower( (string) ( $input['search'] ?? '' ) );
		$abilities = array();
		$covered   = array();
		foreach ( $all as $ability ) {
			$product = self::covered_by( $ability->get_name() );
			if ( '' !== $product ) {
				$covered[ $product ] = ( $covered[ $product ] ?? 0 ) + 1;
				continue;
			}
			if ( '' !== $category && $ability->get_category() !== $category ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( mb_strtolower( $ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description() ), $search ) ) {
				continue;
			}
			$abilities[] = self::row( $ability );
		}
		$rows = array();
		foreach ( $covered as $product => $count ) {
			$rows[] = array(
				'product'   => $product,
				'abilities' => $count,
				'note'      => 'Use KuDiagnostics\' own ' . $product . ' tools.',
			);
		}
		return array(
			'abilities' => $abilities,
			'covered'   => $rows,
		);
	}

	/**
	 * Summary of one ability.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed>
	 */
	private static function row( \WP_Ability $ability ): array {
		$reads       = self::reads( $ability );
		$why         = self::refused( $ability->get_name() );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$row         = array(
			'name'        => $ability->get_name(),
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'category'    => $ability->get_category(),
			'kind'        => $reads ? 'read' : 'write',
			'destructive' => is_array( $annotations ) && true === ( $annotations['destructive'] ?? null ),
			'run_with'    => '' !== $why ? 'not run here' : ( $reads ? 'kuduy-ability-read' : 'kuduy-ability-run' ),
		);
		if ( '' !== $why ) {
			$row['why_not'] = $why;
		}
		return $row;
	}
}
