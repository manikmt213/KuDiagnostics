<?php
/**
 * Tools: kuduy-forms-export and kuduy-forms-migrate (phase B3, decision D83).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Migration;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Export a form to the canonical model; migrate it to another forms plugin as a new form, with a plan first and a
 * check of the saved result. The source form, its entries and notifications are never changed or moved.
 */
final class MigrationTools implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode export | migrate.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-forms-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'export' === $this->mode ? __( 'Export a form (plugin-neutral)', 'kuduy-mcp' ) : __( 'Move a form to another forms plugin', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$plugins = implode( ', ', FormModel::sources() );
		return 'export' === $this->mode
			? 'Reads a form of ' . $plugins . ' into one plugin-neutral shape: fields with a common type (text, email, textarea, number, tel, url, date, select, radio, checkbox, acceptance, file, hidden), label, required, placeholder, description, default, choices and storage key; submit text; confirmation message; the number of email notifications. Fields with no common type are listed under skipped. Changes nothing.'
			: 'Copies a form from ' . $plugins . ' into another forms plugin (' . implode( ', ', array_keys( FormModel::editors() ) ) . ') as a new form, through that plugin\'s own save path. First call without confirm: returns the plan (each field\'s type in the target, what becomes a plainer field, what cannot be carried) and changes nothing; show it to the user. With confirm: true it creates the form and reads it back to verify each field. The old form, its entries and its email notifications stay where they are; notifications are re-created with kuduy-<plugin>-notification-save (merge tags differ per plugin), and the new form is embedded where the old one was.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$source = array(
			'plugin'  => array(
				'type' => 'string',
				'enum' => FormModel::sources(),
			),
			'form_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'export' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => $source,
				'required'             => array( 'plugin', 'form_id' ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $source + array(
				'to'      => array(
					'type' => 'string',
					'enum' => array_keys( FormModel::editors() ),
				),
				'title'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Title of the new form (default: the old title).',
				),
				'confirm' => array(
					'type'        => 'boolean',
					'description' => 'Leave out for the plan only; true creates the new form.',
				),
			),
			'required'             => array( 'plugin', 'form_id', 'to' ),
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
		$read = 'export' === $this->mode;
		return array(
			'readOnlyHint'    => $read,
			'destructiveHint' => false,
			'idempotentHint'  => $read,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'export' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * The WordPress gate; each plugin's own permission is checked per form in execute().
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			return true;
		}
		foreach ( FormModel::editors() as $editor ) {
			if ( true === $editor->available() ) {
				return true;
			}
		}
		return 'No supported forms plugin is active on this site.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a plugin is not active, the form is missing or a permission is missing.
	 */
	public function execute( array $input ): array {
		$plugin = (string) $input['plugin'];
		$id     = (int) $input['form_id'];
		if ( ! FormModel::can_edit( $plugin, $id ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not edit this form in its plugin, so it cannot be exported or copied here.' );
		}
		$model = FormModel::export( $plugin, $id );
		if ( 'export' === $this->mode ) {
			return $model;
		}
		$to = (string) $input['to'];
		if ( $to === $plugin ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'The form is already in ' . $to . '; give another plugin as to.' );
		}
		$editor = FormModel::editor( $to );
		$plan   = FormModel::plan( $model, $to );
		$title  = (string) ( $input['title'] ?? $model['title'] );
		$report = array(
			'from'          => array(
				'plugin'  => $plugin,
				'form_id' => $id,
				'title'   => $model['title'],
			),
			'to'            => $to,
			'title'         => $title,
			'fields'        => $plan['fields'],
			'not_carried'   => array_merge( $plan['not_carried'], (array) $model['skipped'] ),
			'submit_text'   => $model['submit_text'],
			'confirmation'  => $model['confirmation_message'],
			'notifications' => (int) $model['notifications'],
			'left_behind'   => 'The old form, its entries and its ' . (int) $model['notifications'] . ' email notification(s) are not changed or moved.',
		);
		if ( ! $plan['operations'] ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'None of this form\'s fields can be carried to ' . $editor->name() . '. Nothing was created.' );
		}
		if ( empty( $input['confirm'] ) ) {
			return array(
				'confirmation_required' => true,
				'plan'                  => $report,
				'next'                  => 'Nothing was created. Show the plan to the user; if they agree, call again with the same arguments and confirm: true.',
			);
		}
		if ( ! $editor->can_create() ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Your account may not create ' . $editor->name() . ' forms. Nothing was created.' );
		}
		$settings = array( 'op' => 'form' );
		if ( '' !== (string) $model['submit_text'] ) {
			$settings['submit_text'] = (string) $model['submit_text'];
		}
		if ( '' !== (string) $model['confirmation_message'] ) {
			$settings['confirmation_message'] = (string) $model['confirmation_message'];
		}
		$new = $editor->create( $title );
		AuditLog::target( $to . '_form', (string) $new );
		try {
			$added = $editor->apply( $new, array_merge( $plan['operations'], count( $settings ) > 1 ? array( $settings ) : array() ) );
		} catch ( ToolError $e ) {
			// Rule 5: say exactly what is left behind.
			throw ToolError::of( ToolError::OPERATION_FAILED, $editor->name() . ' created the new form ' . $new . ' but refused its fields: ' . $e->getMessage() . ' The new form is empty; delete it in ' . $editor->name() . ' or try again. The old form was not changed.' );
		}
		$saved = $editor->form( $new );
		return array(
			'created' => array(
				'plugin'  => $to,
				'form_id' => $new,
				'title'   => (string) $saved['title'],
			),
			'report'  => $report,
			'verify'  => FormModel::verify( $to, $saved, $added, $plan['fields'], $model ),
			'next'    => 'Embed the new form where the old one was, re-create the email notifications with kuduy-' . $to . '-notification-save if needed, and check the new form on the site.',
		);
	}
}
