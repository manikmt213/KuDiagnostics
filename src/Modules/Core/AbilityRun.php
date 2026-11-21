<?php
/**
 * Tool: kuduy-ability-run (phase J, D82 / D34).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Runs a public write ability of a plugin KuDiagnostics has no tools for. KuDiagnostics cannot know what such an
 * ability changes, so it cannot take an undo snapshot: every call is confirm-first (D45), and the preview says so.
 */
final class AbilityRun implements Tool, Confirmable {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-ability-run';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Run an ability that changes things', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Runs a public ability that may change the site, from a plugin KuDiagnostics has no tools for (see kuduy-abilities), with the input its schema declares. The first call changes nothing: it returns a preview and a confirm_token; show it to the user and call again with the token only after they agree. The plugin validates the input and checks its own permissions as you. KuDiagnostics cannot undo what an ability does.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return AbilitiesHub::run_input(
			array(
				'type'        => 'string',
				'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+(/[a-z0-9-]+)*$',
				'maxLength'   => 200,
				'description' => 'Ability name from kuduy-abilities.',
			),
			array( Confirmation::ARG => Confirmation::token_input() )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ability'   => array( 'type' => 'string' ),
				'result'    => array(
					'type'        => SnapshotDiff::ANY,
					'description' => 'What the ability returned.',
				),
				'truncated' => array( 'type' => 'boolean' ),
				'note'      => array( 'type' => 'string' ),
			) + Confirmation::output_properties(),
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
	 * Any logged-in user; the ability checks its own permissions.
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
		return AbilitiesHub::supported();
	}

	/**
	 * The ability, checked: public, not refused, a write ability, valid input (as core checks it), and allowed for this
	 * user and input.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When it may not run.
	 */
	private static function ability( array $input ): \WP_Ability {
		$ability = AbilitiesHub::usable( (string) $input['name'] );
		if ( AbilitiesHub::reads( $ability ) ) {
			throw new ToolError( 'This ability only reads: use kuduy-ability-read.', ToolError::INVALID_ARGUMENT );
		}
		$args  = $ability->normalize_input( $input['input'] ?? null );
		$valid = is_wp_error( $args ) ? $args : $ability->validate_input( $args );
		if ( is_wp_error( $valid ) ) {
			throw new ToolError( 'The ability does not accept this input: ' . wp_strip_all_tags( $valid->get_error_message() ) . ' Nothing was changed.', ToolError::INVALID_ARGUMENT );
		}
		$allowed = $ability->check_permissions( $args );
		if ( true !== $allowed ) {
			throw new ToolError( 'The plugin does not allow your account to run this ability' . ( is_wp_error( $allowed ) ? ': ' . wp_strip_all_tags( $allowed->get_error_message() ) : '' ) . '. Nothing was changed.', ToolError::PERMISSION_DENIED );
		}
		return $ability;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments without the token.
	 */
	public function preview( array $input ): array {
		$ability     = self::ability( $input );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		return array(
			'ability'     => $ability->get_name(),
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'destructive' => is_array( $annotations ) && true === ( $annotations['destructive'] ?? null ),
			'input'       => $input['input'] ?? null,
			'undo'        => 'Not possible through KuDiagnostics: it cannot know what this ability changes.',
			'version'     => $ability->get_name(),
		);
	}

	/**
	 * Run it.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		$ability = self::ability( $input );
		AuditLog::target( 'ability', $ability->get_name() );
		return AbilitiesHub::run( $ability, $input );
	}
}
