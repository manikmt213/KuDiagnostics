<?php
/**
 * Tools: kuduy-astra-abilities, kuduy-astra-ability-get, kuduy-astra-ability-run (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Astra's theme settings through Astra's own abilities (upgrade plan phase D, D38; Astra 4.14.2 source). Astra
 * registers about sixty "astra/…" abilities with the WordPress Abilities API (get-… / list-… read, update-… write:
 * colours, typography, container, sidebar, header and footer builder, blog, …) once the site owner switches them on
 * (Astra > Settings; write abilities have a second switch). Each ability validates its own input, writes Astra's
 * options itself and tells Astra to refresh what it caches. KuDiagnostics runs them as the connected user through
 * WP_Ability::execute(), which checks the ability's permission and input schema; it never flips Astra's switches.
 */
final class AstraAbilities implements Tool, Snapshottable {

	private const PREFIX = 'astra/';

	/**
	 * Abilities that change post meta, not theme settings: left to the post tools (their change is not snapshotted here).
	 */
	private const NOT_HERE = array( 'astra/update-post-meta' );

	/**
	 * Options Astra's abilities write, saved before a write for undo.
	 */
	private const OPTIONS = array( 'astra-settings', 'astra_admin_settings', 'blogname', 'blogdescription' );

	/**
	 * Mode.
	 *
	 * @param string $mode abilities | ability-get | ability-run.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-astra-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'abilities'   => __( 'List Astra theme abilities', 'kuduy-mcp' ),
			'ability-get' => __( 'Read Astra theme settings', 'kuduy-mcp' ),
			default       => __( 'Change Astra theme settings', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'abilities'   => 'Lists what the Astra theme lets an assistant read and change through Astra\'s own abilities (colours, fonts, container, sidebar, header and footer builder, blog layouts, …): name, label, description, read or write. With name it returns that ability\'s input schema. Says so when the site owner has not switched Astra\'s abilities on (Astra > Settings).',
			'ability-get' => 'Runs one of Astra\'s read abilities (astra/get-… or astra/list-…) and returns its data, e.g. astra/get-global-palette or astra/get-container-layout. Names and inputs: kuduy-astra-abilities.',
			default       => 'Runs one of Astra\'s write abilities (astra/update-…) with the input it declares, e.g. astra/update-container-layout {"container_layout": "narrow-width-container"}. Astra validates the input, saves the setting and refreshes its generated CSS. Changes the live site design at once. Snapshot of Astra\'s settings first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name  = array(
			'type'        => 'string',
			'pattern'     => '^astra/[a-z0-9-]+$',
			'description' => 'Ability name from kuduy-astra-abilities.',
		);
		$props = 'abilities' === $this->mode
			? array( 'name' => $name + array( 'title' => 'Return the input schema of this ability.' ) )
			: array(
				'name'  => $name,
				'input' => array(
					'type'        => 'object',
					'description' => 'Input the ability declares (see kuduy-astra-abilities with name).',
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'abilities' === $this->mode ? array() : array( 'name' ),
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
		$write = 'ability-run' === $this->mode;
		return array(
			'readOnlyHint'    => ! $write,
			'destructiveHint' => $write,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'ability-run' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return defined( 'ASTRA_THEME_VERSION' ) ? true : 'Astra is not the active theme on this site.';
	}

	/**
	 * Astra's registered abilities by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private static function abilities(): array {
		$out = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( str_starts_with( $ability->get_name(), self::PREFIX ) ) {
					$out[ $ability->get_name() ] = $ability;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether an ability changes something.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private static function writes( \WP_Ability $ability ): bool {
		return 'write' === $ability->get_meta_item( 'tool_type', '' );
	}

	/**
	 * An Astra ability of the kind this tool runs.
	 *
	 * @param string $name  Ability name.
	 * @param bool   $write Expect a write ability.
	 * @throws ToolError When abilities are off, the name is unknown or of the other kind.
	 */
	private static function ability( string $name, bool $write ): \WP_Ability {
		$all = self::abilities();
		if ( ! $all ) {
			throw new ToolError( 'Astra\'s abilities are switched off on this site. The site owner can switch them on in Astra > Settings; KuDiagnostics does not change that switch.' );
		}
		$ability = $all[ $name ] ?? null;
		if ( null === $ability ) {
			throw new ToolError( 'Astra has no ability "' . esc_html( $name ) . '" on this site (write abilities have their own switch in Astra > Settings). See kuduy-astra-abilities.' );
		}
		if ( in_array( $name, self::NOT_HERE, true ) ) {
			throw new ToolError( 'This ability changes a post, not the theme. Use the post tools (kuduy-post-meta-set).' );
		}
		if ( self::writes( $ability ) !== $write ) {
			throw new ToolError( $write ? 'This is a read ability: use kuduy-astra-ability-get.' : 'This ability changes settings: use kuduy-astra-ability-run.' );
		}
		return $ability;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'ability-run' !== $this->mode ) {
			return null;
		}
		$options = array();
		$none    = new \stdClass();
		foreach ( array_merge( self::OPTIONS, array( 'theme_mods_' . get_stylesheet() ) ) as $option ) {
			$value              = get_option( $option, $none );
			$options[ $option ] = array(
				'existed' => $value !== $none,
				'value'   => $value !== $none ? $value : null,
			);
		}
		return array(
			'object_type' => 'theme_settings',
			'object_id'   => (string) get_stylesheet(),
			'payload'     => array(
				'stylesheet' => (string) get_stylesheet(),
				'options'    => $options,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When another theme is active now.
	 */
	public function restore( array $payload ): void {
		if ( (string) ( $payload['stylesheet'] ?? '' ) !== get_stylesheet() ) {
			throw new ToolError( 'These settings belong to the theme "' . esc_html( (string) ( $payload['stylesheet'] ?? '' ) ) . '", which is not the active theme now. Nothing was restored.' );
		}
		foreach ( (array) ( $payload['options'] ?? array() ) as $option => $state ) {
			if ( empty( $state['existed'] ) ) {
				delete_option( (string) $option );
			} else {
				update_option( (string) $option, $state['value'] );
			}
		}
		// Astra refreshes its cached options and generated CSS on this hook after an ability wrote settings.
		do_action( 'astra_ability_settings_updated', 'kuduy/restore', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Astra's own hook.
		self::refresh();
	}

	/**
	 * Astra keeps its options in memory for the request; reload them after a write, as Astra does after a
	 * Customizer save (Astra_Customizer::customize_save()).
	 */
	private static function refresh(): void {
		if ( is_callable( array( 'Astra_Theme_Options', 'refresh' ) ) ) {
			call_user_func( array( 'Astra_Theme_Options', 'refresh' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When abilities are off, the ability is unknown or Astra refuses.
	 */
	public function execute( array $input ): array {
		if ( 'abilities' === $this->mode ) {
			return self::listing( (string) ( $input['name'] ?? '' ) );
		}
		$write   = 'ability-run' === $this->mode;
		$name    = (string) $input['name'];
		$ability = self::ability( $name, $write );
		if ( $write ) {
			AuditLog::target( 'theme_settings', (string) get_stylesheet() );
		}
		$args   = (array) ( $input['input'] ?? array() );
		$result = $ability->execute( $args );
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Astra refused: ' . esc_html( wp_strip_all_tags( $result->get_error_message() ) ) . ( $write ? ' Nothing was changed.' : '' ) );
		}
		if ( is_array( $result ) && array_key_exists( 'success', $result ) && ! $result['success'] ) {
			throw new ToolError( 'Astra refused: ' . esc_html( wp_strip_all_tags( trim( (string) ( $result['message'] ?? '' ) . ' ' . (string) ( $result['suggestion'] ?? '' ) ) ) ) );
		}
		if ( $write ) {
			self::refresh();
		}
		return array(
			'ability' => $name,
			'result'  => $result,
		);
	}

	/**
	 * The abilities list, or one ability with its input schema.
	 *
	 * @param string $name Ability name or ''.
	 * @return array<string, mixed>
	 * @throws ToolError When the named ability is unknown.
	 */
	private static function listing( string $name ): array {
		$all = self::abilities();
		if ( ! $all ) {
			return array(
				'enabled'   => false,
				'abilities' => array(),
				'note'      => 'Astra\'s abilities are switched off. The site owner can switch them on in Astra > Settings (reading and, separately, changing settings). Until then Astra\'s settings cannot be read or changed here.',
			);
		}
		if ( '' !== $name ) {
			$ability = $all[ $name ] ?? null;
			if ( null === $ability ) {
				throw new ToolError( 'Astra has no ability "' . esc_html( $name ) . '" on this site. See kuduy-astra-abilities.' );
			}
			return array(
				'enabled' => true,
				'ability' => self::row( $ability ) + array( 'input_schema' => $ability->get_input_schema() ),
			);
		}
		return array(
			'enabled'   => true,
			'abilities' => array_values( array_map( array( self::class, 'row' ), $all ) ),
		);
	}

	/**
	 * Summary of one ability.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed>
	 */
	private static function row( \WP_Ability $ability ): array {
		return array(
			'name'        => $ability->get_name(),
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'kind'        => self::writes( $ability ) ? 'write' : 'read',
			'run_with'    => in_array( $ability->get_name(), self::NOT_HERE, true ) ? 'kuduy-post-meta-set' : ( self::writes( $ability ) ? 'kuduy-astra-ability-run' : 'kuduy-astra-ability-get' ),
		);
	}
}
