<?php
/**
 * Tools: kuduy-bricks-site-ability-run, kuduy-breakdance-site-ability-run, kuduy-oxygen-site-ability-run.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Site-wide writes of the builders' own abilities (phase Q8, D45): global settings, CSS variables, selectors,
 * stylesheets, the home page and similar abilities that take no page. They run in two steps with a confirmation token,
 * as a site manager, and before the change the builder's own options (names starting with its prefix) and the home
 * page settings are saved for undo; options holding licence keys, API keys, tokens or passwords are neither saved nor
 * restored. Abilities that delete, restore, import, upload, run code or send data out stay refused (rule 6).
 */
final class BuilderSiteAbilities implements Tool, Snapshottable, Confirmable {

	/**
	 * Builders with site-wide abilities.
	 */
	public const BUILDERS = array( 'bricks', 'breakdance', 'oxygen' );

	/**
	 * Option name prefixes of each builder (Oxygen 6 shares Breakdance's code and keeps some breakdance_ options).
	 *
	 * @var array<string, list<string>>
	 */
	private const PREFIXES = array(
		'bricks'     => array( 'bricks_' ),
		'breakdance' => array( 'breakdance_' ),
		'oxygen'     => array( 'oxygen_', 'breakdance_' ),
	);

	/**
	 * Core options the home page abilities change.
	 */
	private const CORE = array( 'show_on_front', 'page_on_front', 'page_for_posts' );

	/**
	 * Options never saved or restored: credentials.
	 */
	private const SECRET = '/licen[cs]e|_key|key_|token|secret|password|credential|_api|api_/i';

	/**
	 * Largest options snapshot, in bytes of serialized data.
	 */
	private const MAX_BYTES = 4000000;

	/**
	 * Builder.
	 *
	 * @param string $builder bricks | breakdance | oxygen.
	 */
	public function __construct( private string $builder ) {}

	/**
	 * The builder's abilities tool, whose checks this tool shares.
	 */
	private function base(): BuilderAbilities {
		return new BuilderAbilities( $this->builder, 'ability-run' );
	}

	/**
	 * Display name.
	 */
	private function label(): string {
		return BuilderAbilities::BUILDERS[ $this->builder ] ?? $this->builder;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->builder . '-site-ability-run';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		/* translators: %s: page builder name. */
		return sprintf( __( 'Change site-wide %s settings', 'kuduy-mcp' ), $this->label() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$b = $this->label();
		return 'Runs one of ' . $b . '\'s site-wide write abilities (global settings, CSS variables, classes, selectors, stylesheets, home page, …: the ones kuduy-' . $this->builder . '-abilities lists with run_with ' . $this->name() . ') with the input it declares. Two steps: the first call changes nothing and returns what would run with a confirm_token; show it to the user and call again with the token once they agree. ' . $b . ' validates the input and checks its own permissions. Before the change ' . $b . '\'s options and the home page settings are saved, so kuduy-snapshot-restore can undo it (options holding licence or API keys are not saved). Abilities that delete, restore, import, upload, run code or send data out are not run.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'            => array(
					'type'        => 'string',
					'pattern'     => '^' . $this->builder . '/[a-z0-9-]+$',
					'description' => 'Ability name from kuduy-' . $this->builder . '-abilities (run_with ' . $this->name() . ').',
				),
				'input'           => array(
					'type'        => 'object',
					'description' => 'Input the ability declares (see kuduy-' . $this->builder . '-abilities with name).',
				),
				Confirmation::ARG => Confirmation::token_input(),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'ability'      => array( 'type' => 'string' ),
				'result'       => array( 'description' => 'What the builder returned.' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return $this->base()->is_available();
	}

	/**
	 * The ability, checked: a site-wide write this tool may run.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When it is unknown or not run here.
	 */
	private function ability( array $input ): \WP_Ability {
		$base    = $this->base();
		$ability = $base->ability( (string) $input['name'] );
		$why     = $base->site_why( $ability );
		if ( '' !== $why ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, $why . ' Nothing was run.' );
		}
		return $ability;
	}

	/**
	 * The builder's options (and the home page settings) as they are now, without credentials.
	 *
	 * @return array<string, mixed>
	 * @throws ToolError When they are too large to save.
	 */
	private function options(): array {
		global $wpdb;
		$names = self::CORE;
		foreach ( self::PREFIXES[ $this->builder ] as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, prepared; options are listed by prefix, which no core API does.
			$found = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( $prefix ) . '%' ) );
			$names = array_merge( $names, array_map( 'strval', $found ) );
		}
		$out = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( preg_match( self::SECRET, $name ) || str_starts_with( $name, '_transient' ) || str_starts_with( $name, '_site_transient' ) ) {
				continue;
			}
			$out[ $name ] = get_option( $name, null );
		}
		ksort( $out );
		if ( strlen( (string) maybe_serialize( $out ) ) > self::MAX_BYTES ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, $this->label() . '\'s options are too large to save for undo here. Change the setting in ' . $this->label() . ' itself. Nothing was run.' );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments without the token.
	 * @return array<string, mixed>
	 * @throws ToolError When the ability is unknown or not run here.
	 */
	public function preview( array $input ): array {
		$ability = $this->ability( $input );
		$options = $this->options();
		return array(
			'ability'       => $ability->get_name(),
			'label'         => wp_strip_all_tags( $ability->get_label() ),
			'description'   => wp_strip_all_tags( $ability->get_description() ),
			'input'         => (object) ( $input['input'] ?? array() ),
			'changes'       => 'Site-wide: every page that uses ' . $this->label() . '\'s global settings may look different afterwards.',
			'saved_options' => count( $options ),
			'undo'          => 'kuduy-snapshot-restore puts back ' . $this->label() . '\'s options and the home page settings as they are now; options holding licence or API keys are not covered.',
			'version'       => md5( (string) maybe_serialize( $options ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): array {
		return array(
			'object_type' => 'option',
			'object_id'   => $this->builder,
			'payload'     => array( 'options' => $this->options() ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$saved = (array) ( $payload['options'] ?? array() );
		$now   = $this->options();
		foreach ( array_keys( $now ) as $name ) {
			if ( ! array_key_exists( $name, $saved ) && ! in_array( $name, self::CORE, true ) ) {
				delete_option( (string) $name );
			}
		}
		foreach ( $saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( (string) $name );
			} elseif ( get_option( (string) $name, null ) !== $value ) {
				update_option( (string) $name, $value );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the ability is refused or the builder refuses.
	 */
	public function execute( array $input ): array {
		$ability = $this->ability( $input );
		AuditLog::target( 'builder', $this->builder );
		$result = $ability->execute( (array) ( $input['input'] ?? array() ) );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, $this->label() . ' refused: ' . wp_strip_all_tags( $result->get_error_message() ) . ' Nothing was changed.' );
		}
		return array(
			'ability' => $ability->get_name(),
			'result'  => $result,
		);
	}
}
